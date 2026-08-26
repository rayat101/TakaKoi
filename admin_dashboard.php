<?php
// admin_dashboard.php

session_start();

if (!isset($_SESSION["employee_id"])) {
    header("Location: employee_login.php");
    exit();
}

$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "Banking_software";

$stats = [
    "customers"    => 0,
    "accounts"     => 0,
    "loans"        => 0,
    "transactions" => 0,
    "fraud"        => 0,
    "branches"     => 0,
];

$recent_transactions = [];
$recent_loans        = [];
$recent_fraud        = [];
$db_error            = "";

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    $db_error = "Could not connect to the database. Please try again later.";
} else {

    // --- Simple stats ---
    $count_queries = [
        "customers"    => "SELECT COUNT(*) AS total FROM Customer",
        "accounts"     => "SELECT COUNT(*) AS total FROM Account",
        "loans"        => "SELECT COUNT(*) AS total FROM Loan",
        "transactions" => "SELECT COUNT(*) AS total FROM Transaction",
        "fraud"        => "SELECT COUNT(*) AS total FROM Fraud",
        "branches"     => "SELECT COUNT(*) AS total FROM Branch",
    ];

    foreach ($count_queries as $key => $sql) {
        $result = $conn->query($sql);
        if ($result) {
            $stats[$key] = (int) $result->fetch_assoc()["total"];
        }
    }

    // --- Recent transactions ---
    $result = $conn->query(
        "SELECT * FROM Transaction ORDER BY t_date DESC, t_time DESC LIMIT 5"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $recent_transactions[] = $row;
        }
    }

    // --- Recent loan activity ---
    $result = $conn->query(
        "SELECT * FROM Loan ORDER BY start_date DESC LIMIT 5"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $recent_loans[] = $row;
        }
    }

    // --- Recent fraud alerts ---
    $result = $conn->query(
        "SELECT * FROM Fraud ORDER BY alert_date DESC LIMIT 5"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $recent_fraud[] = $row;
        }
    }

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TakaKoi - Admin Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

    <nav class="navbar">
        <div class="logo-wrapper" style="flex-direction: row; align-items: center; margin-bottom: 0;">
            <img src="img/logo.png" alt="TakaKoi logo" style="width: 36px; margin-bottom: 0; margin-right: 10px;">
        </div>
        <div>
            <a href="admin_dashboard.php" class="nav-active">Dashboard</a>
            <a href="#">Customers</a>
            <a href="admin_accounts.php">Accounts</a>
            <a href="#">Transactions</a>
            <a href="admin_loans.php">Loans</a>
            <a href="admin_employees.php">Employees</a>
            <a href="#">Fraud Monitoring</a>
            <a href="#">Branches</a>
            <a href="#">Reports</a>
            <a href="login.php">Logout</a>
        </div>
    </nav>

    <div style="max-width: 1000px; margin: 0 auto; padding: 28px 20px;">

        <?php if ($db_error !== "") : ?>
            <div class="message message-danger"><?php echo htmlspecialchars($db_error); ?></div>
        <?php else : ?>

            <h1 class="mb-16">
                Welcome, <?php echo htmlspecialchars($_SESSION["employee_name"] ?? "Employee"); ?>
            </h1>

            <h2 class="mb-16">Overview</h2>
            <div style="display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 24px;">
                <div class="card" style="flex: 1; min-width: 150px;">
                    <p class="small-text">Total Customers</p>
                    <h2><?php echo $stats["customers"]; ?></h2>
                </div>
                <div class="card" style="flex: 1; min-width: 150px;">
                    <p class="small-text">Total Accounts</p>
                    <h2><?php echo $stats["accounts"]; ?></h2>
                </div>
                <div class="card" style="flex: 1; min-width: 150px;">
                    <p class="small-text">Total Loans</p>
                    <h2><?php echo $stats["loans"]; ?></h2>
                </div>
                <div class="card" style="flex: 1; min-width: 150px;">
                    <p class="small-text">Total Transactions</p>
                    <h2><?php echo $stats["transactions"]; ?></h2>
                </div>
                <div class="card" style="flex: 1; min-width: 150px;">
                    <p class="small-text">Fraud Alerts</p>
                    <h2><?php echo $stats["fraud"]; ?></h2>
                </div>
                <div class="card" style="flex: 1; min-width: 150px;">
                    <p class="small-text">Branches</p>
                    <h2><?php echo $stats["branches"]; ?></h2>
                </div>
            </div>

            <h2 class="mb-16">Recent Transactions</h2>
            <div class="card mb-16">
                <?php if (empty($recent_transactions)) : ?>
                    <p>No data available.</p>
                <?php else : ?>
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                            <th style="padding: 8px 6px;">Date</th>
                            <th style="padding: 8px 6px;">Type</th>
                            <th style="padding: 8px 6px;">Amount</th>
                            <th style="padding: 8px 6px;">Account ID</th>
                        </tr>
                        <?php foreach ($recent_transactions as $t) : ?>
                            <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($t["t_date"]); ?></td>
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($t["transaction_type"]); ?></td>
                                <td style="padding: 8px 6px;">Tk. <?php echo number_format($t["amount"], 2); ?></td>
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($t["account_id"]); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>

            <h2 class="mb-16">Recent Loan Activity</h2>
            <div class="card mb-16">
                <?php if (empty($recent_loans)) : ?>
                    <p>No data available.</p>
                <?php else : ?>
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                            <th style="padding: 8px 6px;">Loan ID</th>
                            <th style="padding: 8px 6px;">Type</th>
                            <th style="padding: 8px 6px;">Amount</th>
                            <th style="padding: 8px 6px;">Status</th>
                        </tr>
                        <?php foreach ($recent_loans as $loan) : ?>
                            <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($loan["loan_id"]); ?></td>
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($loan["loan_type"]); ?></td>
                                <td style="padding: 8px 6px;">Tk. <?php echo number_format($loan["amount"], 2); ?></td>
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($loan["status"]); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>

            <h2 class="mb-16">Recent Fraud Alerts</h2>
            <div class="card mb-16">
                <?php if (empty($recent_fraud)) : ?>
                    <p>No data available.</p>
                <?php else : ?>
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                            <th style="padding: 8px 6px;">Alert ID</th>
                            <th style="padding: 8px 6px;">Reason</th>
                            <th style="padding: 8px 6px;">Status</th>
                            <th style="padding: 8px 6px;">Date</th>
                        </tr>
                        <?php foreach ($recent_fraud as $alert) : ?>
                            <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($alert["alert_id"]); ?></td>
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($alert["reason"]); ?></td>
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($alert["status"]); ?></td>
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($alert["alert_date"]); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>

        <?php endif; ?>

    </div>

</body>
</html>