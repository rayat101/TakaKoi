<?php
// admin_loans.php - Employee reviews loan applications
// Employees can approve or reject Pending loans.

session_start();

if (!isset($_SESSION["employee_id"])) {
    header("Location: employee_login.php");
    exit();
}

$employee_id = $_SESSION["employee_id"];

// --- Database connection ---
$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "Banking_software";

$errors          = [];
$success_message = "";
$pending_loans   = [];
$all_loans       = [];

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    $errors[] = "Could not connect to the database. Please try again later.";
} else {

    // ---------- Handle approve / reject ----------
    if ($_SERVER["REQUEST_METHOD"] === "POST") {

        $loan_id  = $_POST["loan_id"] ?? "";
        $decision = $_POST["decision"] ?? "";

        if ($loan_id === "" || !ctype_digit($loan_id)) {
            $errors[] = "Invalid loan.";
        }

        if ($decision !== "Approved" && $decision !== "Rejected") {
            $errors[] = "Invalid decision.";
        }

        if (empty($errors)) {

            // The employee making the decision becomes the loan's employee
            $stmt = $conn->prepare(
                "UPDATE Loan SET status = ?, employee_id = ?
                 WHERE loan_id = ? AND status = 'Pending'"
            );
            $stmt->bind_param("sii", $decision, $employee_id, $loan_id);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                $success_message = "Loan " . $loan_id . " has been " . strtolower($decision) . ".";
            } else {
                $errors[] = "That loan could not be updated. It may already have been decided.";
            }

            $stmt->close();
        }
    }

    // ---------- Pending loan applications ----------
    $result = $conn->query(
        "SELECT l.*, c.first_name, c.last_name
         FROM Loan l
         JOIN Customer c ON l.customer_id = c.customer_id
         WHERE l.status = 'Pending'
         ORDER BY l.start_date"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $pending_loans[] = $row;
        }
    }

    // ---------- All loans ----------
    $result = $conn->query(
        "SELECT l.*, c.first_name, c.last_name,
                (SELECT IFNULL(SUM(p.amount), 0)
                 FROM Loan_payment p WHERE p.loan_id = l.loan_id) AS paid_so_far
         FROM Loan l
         JOIN Customer c ON l.customer_id = c.customer_id
         ORDER BY l.start_date DESC
         LIMIT 20"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $all_loans[] = $row;
        }
    }

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TakaKoi - Loan Applications</title>
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
            <a href="admin_loans.php" class="nav-active">Loans</a>
            <a href="admin_employees.php">Employees</a>
            <a href="fraud_monitoring.php">Fraud Monitoring</a>
            <a href="admin_branches.php">Branches</a>
            <a href="closure_requests.php">Closures</a>
            <a href="cheque_requests.php">Cheque Books</a>
            <a href="reports.php">Reports</a>
            <a href="employee_login.php">Logout</a>
        </div>
    </nav>

    <div style="max-width: 1000px; margin: 0 auto; padding: 28px 20px;">

        <h1 class="mb-16">Loan Applications</h1>

        <?php foreach ($errors as $error) : ?>
            <div class="message message-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>

        <?php if ($success_message !== "") : ?>
            <div class="message message-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <h2 class="mb-16">Pending Applications</h2>
        <div class="card mb-16">
            <?php if (empty($pending_loans)) : ?>
                <p>No pending loan applications.</p>
            <?php else : ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Loan ID</th>
                        <th style="padding: 8px 6px;">Customer</th>
                        <th style="padding: 8px 6px;">Type</th>
                        <th style="padding: 8px 6px;">Amount</th>
                        <th style="padding: 8px 6px;">Rate</th>
                        <th style="padding: 8px 6px;">Months</th>
                        <th style="padding: 8px 6px;">Reason</th>
                        <th style="padding: 8px 6px;">Decision</th>
                    </tr>
                    <?php foreach ($pending_loans as $loan) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($loan["loan_id"]); ?></td>
                            <td style="padding: 8px 6px;">
                                <?php echo htmlspecialchars($loan["first_name"] . " " . $loan["last_name"]); ?>
                            </td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($loan["loan_type"]); ?></td>
                            <td style="padding: 8px 6px;">Tk. <?php echo number_format($loan["amount"], 2); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($loan["interest_rate"]); ?>%</td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($loan["duration"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($loan["reason"]); ?></td>
                            <td style="padding: 8px 6px;">
                                <form action="admin_loans.php" method="POST" style="display:flex; gap:6px;">
                                    <input type="hidden" name="loan_id"
                                           value="<?php echo htmlspecialchars($loan["loan_id"]); ?>">
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

        <h2 class="mb-16">All Loans</h2>
        <div class="card mb-16">
            <?php if (empty($all_loans)) : ?>
                <p>No data available.</p>
            <?php else : ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Loan ID</th>
                        <th style="padding: 8px 6px;">Customer</th>
                        <th style="padding: 8px 6px;">Type</th>
                        <th style="padding: 8px 6px;">Amount</th>
                        <th style="padding: 8px 6px;">Paid</th>
                        <th style="padding: 8px 6px;">Status</th>
                        <th style="padding: 8px 6px;">Start Date</th>
                    </tr>
                    <?php foreach ($all_loans as $loan) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($loan["loan_id"]); ?></td>
                            <td style="padding: 8px 6px;">
                                <?php echo htmlspecialchars($loan["first_name"] . " " . $loan["last_name"]); ?>
                            </td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($loan["loan_type"]); ?></td>
                            <td style="padding: 8px 6px;">Tk. <?php echo number_format($loan["amount"], 2); ?></td>
                            <td style="padding: 8px 6px;">Tk. <?php echo number_format($loan["paid_so_far"], 2); ?></td>
                            <td style="padding: 8px 6px;">
                                <?php
                                    $status = $loan["status"];
                                    $status_class = "message-warning";
                                    if ($status === "Approved" || $status === "Active" || $status === "Paid") {
                                        $status_class = "message-success";
                                    }
                                    if ($status === "Rejected" || $status === "Defaulted") {
                                        $status_class = "message-danger";
                                    }
                                ?>
                                <span class="message <?php echo $status_class; ?>"
                                      style="display:inline; padding: 2px 10px;">
                                    <?php echo htmlspecialchars($status); ?>
                                </span>
                            </td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($loan["start_date"]); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

    </div>

</body>
</html>
