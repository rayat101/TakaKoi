<?php
// closure_requests.php - Feature 7: employee side of Account Closure
// Employees approve or reject customer closure requests.
// Approving updates the request AND closes the account inside one transaction.

session_start();

if (!isset($_SESSION["employee_id"])) {
    header("Location: employee_login.php");
    exit();
}

$employee_id = $_SESSION["employee_id"];   // taken from session, never from the form

// --- Database connection ---
$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "Banking_software";

$errors          = [];
$success_message = "";
$pending         = [];
$processed       = [];

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    $errors[] = "Could not connect to the database. Please try again later.";
} else {

    // ---------- Handle approve / reject ----------
    if ($_SERVER["REQUEST_METHOD"] === "POST") {

        $request_id    = $_POST["request_id"] ?? "";
        $decision      = $_POST["decision"] ?? "";
        $decision_note = trim($_POST["decision_note"] ?? "");

        if ($request_id === "" || !ctype_digit($request_id)) {
            $errors[] = "Invalid request.";
        }

        if ($decision !== "Approved" && $decision !== "Rejected") {
            $errors[] = "Invalid decision.";
        }

        if ($decision_note === "") {
            $decision_note = $decision . " by employee " . $employee_id;
        }

        // --- Load the request and make sure it is still Pending ---
        $request = null;
        if (empty($errors)) {

            $stmt = $conn->prepare(
                "SELECT r.request_id, r.status, r.account_id, r.customer_id,
                        a.balance, a.status AS account_status
                 FROM Closure_request r
                 JOIN Account a ON r.account_id = a.account_id
                 WHERE r.request_id = ?"
            );
            $stmt->bind_param("i", $request_id);
            $stmt->execute();
            $request = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$request) {
                $errors[] = "That request was not found.";
            } elseif ($request["status"] !== "Pending") {
                $errors[] = "That request has already been " . strtolower($request["status"]) . ".";
            }
        }

        // --- Business rules that block APPROVAL only ---
        if (empty($errors) && $decision === "Approved") {

            // Rule 1: the account must be empty before it can be closed
            if ($request["balance"] > 0) {
                $errors[] = "Account " . $request["account_id"] . " still holds Tk. " .
                    number_format($request["balance"], 2) .
                    ". The balance must be zero before closing.";
            }

            // Rule 2: no unfinished loans for this customer
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS total FROM Loan
                 WHERE customer_id = ? AND status IN ('Approved', 'Active', 'Defaulted')"
            );
            $stmt->bind_param("i", $request["customer_id"]);
            $stmt->execute();
            $open_loans = (int) $stmt->get_result()->fetch_assoc()["total"];
            $stmt->close();

            if ($open_loans > 0) {
                $errors[] = "This customer has " . $open_loans .
                    " outstanding loan(s). Settle them before closing the account.";
            }
        }

        // ---------- Write the decision ----------
        if (empty($errors)) {

            $today = date("Y-m-d");

            // A transaction is used because approving touches TWO tables.
            // If the account update failed after the request was marked Approved,
            // we would have an "approved" closure on an account that is still open.
            $conn->begin_transaction();

            try {
                $stmt = $conn->prepare(
                    "UPDATE Closure_request
                     SET status = ?, decision_date = ?, decision_note = ?, handled_by = ?
                     WHERE request_id = ? AND status = 'Pending'"
                );
                $stmt->bind_param("sssii", $decision, $today, $decision_note, $employee_id, $request_id);
                $stmt->execute();
                $changed = $stmt->affected_rows;
                $stmt->close();

                if ($changed === 0) {
                    throw new Exception("Request was already handled.");
                }

                // Only an approval closes the account. A rejection leaves it untouched.
                if ($decision === "Approved") {
                    $stmt = $conn->prepare(
                        "UPDATE Account SET status = 'Closed', closed_date = ? WHERE account_id = ?"
                    );
                    $stmt->bind_param("si", $today, $request["account_id"]);
                    $stmt->execute();
                    $stmt->close();
                }

                $conn->commit();

                $success_message = "Request " . $request_id . " has been " .
                    strtolower($decision) . ".";

            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = "Could not process the request. Nothing was changed.";
            }
        }
    }

    // ---------- Pending requests ----------
    $result = $conn->query(
        "SELECT r.*, a.account_type, a.balance, a.status AS account_status,
                c.first_name, c.last_name
         FROM Closure_request r
         JOIN Account a  ON r.account_id = a.account_id
         JOIN Customer c ON r.customer_id = c.customer_id
         WHERE r.status = 'Pending'
         ORDER BY r.request_date"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $pending[] = $row;
        }
    }

    // ---------- Already processed requests ----------
    $result = $conn->query(
        "SELECT r.*, a.account_type, c.first_name, c.last_name,
                e.first_name AS emp_first, e.last_name AS emp_last
         FROM Closure_request r
         JOIN Account a       ON r.account_id = a.account_id
         JOIN Customer c      ON r.customer_id = c.customer_id
         LEFT JOIN Employee e ON r.handled_by = e.employee_id
         WHERE r.status <> 'Pending'
         ORDER BY r.decision_date DESC, r.request_id DESC
         LIMIT 20"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $processed[] = $row;
        }
    }

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TakaKoi - Closure Requests</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

    <nav class="navbar">
        <div class="logo-wrapper" style="flex-direction: row; align-items: center; margin-bottom: 0;">
            <img src="img/logo.png" alt="TakaKoi logo" style="width: 36px; margin-bottom: 0; margin-right: 10px;">
        </div>
        <div>
            <a href="admin_dashboard.php">Dashboard</a>
            <a href="#">Customers</a>
            <a href="admin_accounts.php">Accounts</a>
            <a href="#">Transactions</a>
            <a href="admin_loans.php">Loans</a>
            <a href="admin_employees.php">Employees</a>
            <a href="fraud_monitoring.php">Fraud Monitoring</a>
            <a href="admin_branches.php">Branches</a>
            <a href="closure_requests.php" class="nav-active">Closures</a>
            <a href="cheque_requests.php">Cheque Books</a>
            <a href="reports.php">Reports</a>
            <a href="employee_login.php">Logout</a>
        </div>
    </nav>

    <div style="max-width: 1100px; margin: 0 auto; padding: 28px 20px;">

        <h1 class="mb-16">Account Closure Requests</h1>

        <?php foreach ($errors as $error) : ?>
            <div class="message message-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>

        <?php if ($success_message !== "") : ?>
            <div class="message message-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <h2 class="mb-16">Pending Requests</h2>
        <div class="card mb-16">
            <?php if (empty($pending)) : ?>
                <p>No pending closure requests.</p>
            <?php else : ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Req ID</th>
                        <th style="padding: 8px 6px;">Customer</th>
                        <th style="padding: 8px 6px;">Account</th>
                        <th style="padding: 8px 6px;">Type</th>
                        <th style="padding: 8px 6px;">Balance</th>
                        <th style="padding: 8px 6px;">Requested</th>
                        <th style="padding: 8px 6px;">Reason</th>
                        <th style="padding: 8px 6px;">Decision</th>
                    </tr>
                    <?php foreach ($pending as $r) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($r["request_id"]); ?></td>
                            <td style="padding: 8px 6px;">
                                <?php echo htmlspecialchars($r["first_name"] . " " . $r["last_name"]); ?>
                            </td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($r["account_id"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($r["account_type"]); ?></td>
                            <td style="padding: 8px 6px;">Tk. <?php echo number_format($r["balance"], 2); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($r["request_date"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($r["reason"]); ?></td>
                            <td style="padding: 8px 6px;">
                                <form action="closure_requests.php" method="POST"
                                      style="display:flex; gap:6px; flex-wrap:wrap;">
                                    <input type="hidden" name="request_id"
                                           value="<?php echo htmlspecialchars($r["request_id"]); ?>">
                                    <input type="text" name="decision_note" placeholder="Note"
                                           maxlength="200"
                                           style="padding:6px 8px; border-radius:8px; width:120px;
                                                  border:1px solid rgba(48,27,7,0.25); font-size:13px;">
                                    <button type="submit" name="decision" value="Approved"
                                            class="btn btn-primary"
                                            style="width:auto; padding:6px 12px; margin-bottom:0; font-size:13px;">
                                        Approve
                                    </button>
                                    <button type="submit" name="decision" value="Rejected"
                                            class="btn btn-secondary"
                                            style="width:auto; padding:6px 12px; margin-bottom:0; font-size:13px;">
                                        Reject
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

        <h2 class="mb-16">Processed Requests</h2>
        <div class="card mb-16">
            <?php if (empty($processed)) : ?>
                <p>No processed requests yet.</p>
            <?php else : ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Req ID</th>
                        <th style="padding: 8px 6px;">Customer</th>
                        <th style="padding: 8px 6px;">Account</th>
                        <th style="padding: 8px 6px;">Status</th>
                        <th style="padding: 8px 6px;">Decided</th>
                        <th style="padding: 8px 6px;">Handled By</th>
                        <th style="padding: 8px 6px;">Note</th>
                    </tr>
                    <?php foreach ($processed as $r) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($r["request_id"]); ?></td>
                            <td style="padding: 8px 6px;">
                                <?php echo htmlspecialchars($r["first_name"] . " " . $r["last_name"]); ?>
                            </td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($r["account_id"]); ?></td>
                            <td style="padding: 8px 6px;">
                                <?php
                                    $status = $r["status"];
                                    $status_class = $status === "Approved" ? "message-success" : "message-danger";
                                ?>
                                <span class="message <?php echo $status_class; ?>"
                                      style="display:inline; padding: 2px 10px;">
                                    <?php echo htmlspecialchars($status); ?>
                                </span>
                            </td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($r["decision_date"]); ?></td>
                            <td style="padding: 8px 6px;">
                                <?php echo htmlspecialchars(($r["emp_first"] ?? "") . " " . ($r["emp_last"] ?? "")); ?>
                            </td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($r["decision_note"] ?? ""); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

    </div>

</body>
</html>
