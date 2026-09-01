<?php
// admin_branches.php - Branch directory (read only)
// Shows each branch with how many employees and accounts belong to it.

session_start();

if (!isset($_SESSION["employee_id"])) {
    header("Location: employee_login.php");
    exit();
}

// --- Database connection ---
$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "Banking_software";

$errors   = [];
$branches = [];

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    $errors[] = "Could not connect to the database. Please try again later.";
} else {

    // ---------- Branches with employee and account counts ----------
    $result = $conn->query(
        "SELECT b.branch_id, b.branch_name, b.address, b.phone,
                (SELECT COUNT(*) FROM Employee e WHERE e.branch_id = b.branch_id) AS employee_count,
                (SELECT COUNT(*) FROM Account a WHERE a.branch_id = b.branch_id) AS account_count,
                (SELECT IFNULL(SUM(a.balance), 0) FROM Account a WHERE a.branch_id = b.branch_id) AS total_balance
         FROM Branch b
         ORDER BY b.branch_name"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $branches[] = $row;
        }
    }

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TakaKoi - Branches</title>
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
            <a href="admin_customers.php">Customers</a>
            <a href="admin_accounts.php">Accounts</a>
            <a href="admin_transactions.php">Transactions</a>
            <a href="admin_loans.php">Loans</a>
            <a href="admin_employees.php">Employees</a>
            <a href="fraud_monitoring.php">Fraud Monitoring</a>
            <a href="admin_branches.php" class="nav-active">Branches</a>
            <a href="closure_requests.php">Closures</a>
            <a href="cheque_requests.php">Cheque Books</a>
            <a href="reports.php">Reports</a>
            <a href="employee_login.php">Logout</a>
        </div>
    </nav>

    <div style="max-width: 1000px; margin: 0 auto; padding: 28px 20px;">

        <h1 class="mb-16">Branches</h1>

        <?php foreach ($errors as $error) : ?>
            <div class="message message-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>

        <div class="card mb-16">
            <?php if (empty($branches)) : ?>
                <p>No branches available.</p>
            <?php else : ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Branch ID</th>
                        <th style="padding: 8px 6px;">Name</th>
                        <th style="padding: 8px 6px;">Address</th>
                        <th style="padding: 8px 6px;">Phone</th>
                        <th style="padding: 8px 6px;">Employees</th>
                        <th style="padding: 8px 6px;">Accounts</th>
                        <th style="padding: 8px 6px;">Total Balance</th>
                    </tr>
                    <?php foreach ($branches as $b) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($b["branch_id"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($b["branch_name"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($b["address"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($b["phone"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($b["employee_count"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($b["account_count"]); ?></td>
                            <td style="padding: 8px 6px;">Tk. <?php echo number_format($b["total_balance"], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

    </div>

</body>
</html>
