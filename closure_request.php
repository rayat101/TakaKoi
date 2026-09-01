<?php
// closure_request.php - Feature 7: customer side of Account Closure
// The customer requests closure. The account is NOT closed here - an employee
// approves it later from closure_requests.php.

session_start();

if (!isset($_SESSION["customer_id"])) {
    header("Location: login.php");
    exit();
}

$customer_id = $_SESSION["customer_id"];

// --- Database connection ---
$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "Banking_software";

$errors          = [];
$success_message = "";
$accounts        = [];
$requests        = [];

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    $errors[] = "Could not connect to the database. Please try again later.";
} else {

    // ---------- Handle a new closure request ----------
    if ($_SERVER["REQUEST_METHOD"] === "POST") {

        $account_id = $_POST["account_id"] ?? "";
        $reason     = trim($_POST["reason"] ?? "");

        if ($account_id === "" || !ctype_digit($account_id)) {
            $errors[] = "Please choose an account.";
        }

        if ($reason === "") {
            $errors[] = "Please write a reason for closing the account.";
        }

        // --- Rule 1: the account must belong to THIS customer ---
        // customer_id comes from the session, never from the form, so a customer
        // cannot request closure of somebody else's account.
        if (empty($errors)) {

            $stmt = $conn->prepare(
                "SELECT status FROM Account WHERE account_id = ? AND customer_id = ?"
            );
            $stmt->bind_param("ii", $account_id, $customer_id);
            $stmt->execute();
            $account = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$account) {
                $errors[] = "That account was not found.";
            } elseif ($account["status"] === "Closed") {
                // --- Rule 2: an already closed account cannot be closed again ---
                $errors[] = "That account is already closed.";
            }
        }

        // --- Rule 3: only one Pending request per account ---
        if (empty($errors)) {

            $stmt = $conn->prepare(
                "SELECT request_id FROM Closure_request
                 WHERE account_id = ? AND status = 'Pending'"
            );
            $stmt->bind_param("i", $account_id);
            $stmt->execute();
            $already = $stmt->get_result()->num_rows > 0;
            $stmt->close();

            if ($already) {
                $errors[] = "There is already a pending closure request for that account.";
            }
        }

        if (empty($errors)) {

            $today = date("Y-m-d");

            $stmt = $conn->prepare(
                "INSERT INTO Closure_request
                 (reason, request_date, status, account_id, customer_id)
                 VALUES (?, ?, 'Pending', ?, ?)"
            );
            $stmt->bind_param("ssii", $reason, $today, $account_id, $customer_id);

            if ($stmt->execute()) {
                $success_message = "Closure request submitted for account " . $account_id .
                    ". A bank employee will review it.";
            } else {
                $errors[] = "Could not submit the request. Please try again.";
            }
            $stmt->close();
        }
    }

    // ---------- Accounts that can still be closed ----------
    // Active and Suspended accounts are eligible; already Closed ones are not.
    $stmt = $conn->prepare(
        "SELECT account_id, account_type, balance, status
         FROM Account
         WHERE customer_id = ? AND status <> 'Closed'
         ORDER BY account_id"
    );
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $accounts[] = $row;
    }
    $stmt->close();

    // ---------- This customer's past requests ----------
    // Joined to Employee so the customer can see who handled it.
    $stmt = $conn->prepare(
        "SELECT r.*, a.account_type,
                e.first_name AS emp_first, e.last_name AS emp_last
         FROM Closure_request r
         JOIN Account a       ON r.account_id = a.account_id
         LEFT JOIN Employee e ON r.handled_by = e.employee_id
         WHERE r.customer_id = ?
         ORDER BY r.request_date DESC, r.request_id DESC"
    );
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $requests[] = $row;
    }
    $stmt->close();

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TakaKoi - Close Account</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

    <nav class="navbar">
        <div class="logo-wrapper" style="flex-direction: row; align-items: center; margin-bottom: 0;">
            <img src="img/logo.png" alt="TakaKoi logo" style="width: 36px; margin-bottom: 0; margin-right: 10px;">
        </div>
        <div>
            <a href="customer_dashboard.php">Dashboard</a>
            <a href="accounts.php">Accounts</a>
            <a href="transactions.php">Transactions</a>
            <a href="transfer.php">Transfer</a>
            <a href="loans.php">Loans</a>
            <a href="cheque_book.php">Cheque Book</a>
            <a href="closure_request.php" class="nav-active">Close Account</a>
            <a href="login.php">Logout</a>
        </div>
    </nav>

    <div style="max-width: 900px; margin: 0 auto; padding: 28px 20px;">

        <h1 class="mb-16">Close an Account</h1>

        <?php foreach ($errors as $error) : ?>
            <div class="message message-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>

        <?php if ($success_message !== "") : ?>
            <div class="message message-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <h2 class="mb-16">Request Account Closure</h2>
        <div class="card mb-16">
            <?php if (empty($accounts)) : ?>
                <p>You have no accounts that can be closed.</p>
            <?php else : ?>
                <form action="closure_request.php" method="POST">

                    <div class="form-group">
                        <label for="account_id">Account</label>
                        <select id="account_id" name="account_id" required
                                style="width:100%; padding:10px 12px; border-radius:8px;
                                       border:1px solid rgba(48,27,7,0.25); font-size:14px;">
                            <?php foreach ($accounts as $account) : ?>
                                <option value="<?php echo htmlspecialchars($account["account_id"]); ?>">
                                    <?php
                                        echo htmlspecialchars(
                                            $account["account_id"] . " (" . $account["account_type"] . ") - Tk. " .
                                            number_format($account["balance"], 2) . " - " . $account["status"]
                                        );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="reason">Reason for Closing</label>
                        <input type="text" id="reason" name="reason" maxlength="200" required>
                    </div>

                    <p class="small-text mb-16">
                        Your account stays open until an employee approves the request.
                        Withdraw or transfer any remaining balance first, and clear any
                        outstanding loans, or the request may be rejected.
                    </p>

                    <button type="submit" class="btn btn-primary">Submit Request</button>

                </form>
            <?php endif; ?>
        </div>

        <h2 class="mb-16">My Closure Requests</h2>
        <div class="card mb-16">
            <?php if (empty($requests)) : ?>
                <p>You have not made any closure requests.</p>
            <?php else : ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Request ID</th>
                        <th style="padding: 8px 6px;">Account</th>
                        <th style="padding: 8px 6px;">Reason</th>
                        <th style="padding: 8px 6px;">Requested</th>
                        <th style="padding: 8px 6px;">Status</th>
                        <th style="padding: 8px 6px;">Decision</th>
                    </tr>
                    <?php foreach ($requests as $r) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($r["request_id"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($r["account_id"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($r["reason"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($r["request_date"]); ?></td>
                            <td style="padding: 8px 6px;">
                                <?php
                                    $status = $r["status"];
                                    $status_class = "message-warning";
                                    if ($status === "Approved") $status_class = "message-success";
                                    if ($status === "Rejected") $status_class = "message-danger";
                                ?>
                                <span class="message <?php echo $status_class; ?>"
                                      style="display:inline; padding: 2px 10px;">
                                    <?php echo htmlspecialchars($status); ?>
                                </span>
                            </td>
                            <td style="padding: 8px 6px;">
                                <?php if ($r["status"] === "Pending") : ?>
                                    Awaiting review
                                <?php else : ?>
                                    <?php echo htmlspecialchars($r["decision_date"]); ?><br>
                                    <span class="small-text">
                                        <?php echo htmlspecialchars($r["decision_note"] ?? ""); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

    </div>

</body>
</html>
