<?php
// reports.php - Feature 6: Banking Reports and Analytics
// Every number on this page comes from a live SQL query - nothing is hard-coded.

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

$errors = [];

// --- Optional date filter for the transaction report ---
$from_date = $_GET["from_date"] ?? "";
$to_date   = $_GET["to_date"]   ?? "";

// Only accept a proper YYYY-MM-DD date, otherwise ignore the filter
function valid_date($d) {
    return $d !== "" && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
}

$use_filter = valid_date($from_date) && valid_date($to_date);

if (($from_date !== "" || $to_date !== "") && !$use_filter) {
    $errors[] = "Please provide both dates in YYYY-MM-DD format. Showing all data instead.";
}

// Report containers
$customer_report = [];
$account_report  = [];
$txn_summary     = [];
$txn_by_type     = [];
$txn_by_date     = [];
$loan_report     = [];
$loan_totals     = [];
$branch_report   = [];
$fraud_report    = [];

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    $errors[] = "Could not connect to the database. Please try again later.";
} else {

    // =========================================================
    //  CUSTOMER & ACCOUNT REPORT
    // =========================================================

    // Simple totals using COUNT()
    $result = $conn->query(
        "SELECT
            (SELECT COUNT(*) FROM Customer) AS total_customers,
            (SELECT COUNT(*) FROM Account)  AS total_accounts,
            (SELECT COUNT(*) FROM Employee) AS total_employees,
            (SELECT COUNT(*) FROM Branch)   AS total_branches"
    );
    if ($result) {
        $customer_report = $result->fetch_assoc();
    }

    // Accounts grouped by status and type, with the money held in each group
    $result = $conn->query(
        "SELECT status, account_type, COUNT(*) AS total,
                IFNULL(SUM(balance), 0) AS total_balance,
                IFNULL(AVG(balance), 0) AS average_balance
         FROM Account
         GROUP BY status, account_type
         ORDER BY status, account_type"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $account_report[] = $row;
        }
    }

    // =========================================================
    //  TRANSACTION REPORT (respects the date filter)
    // =========================================================

    if ($use_filter) {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total_count,
                    IFNULL(SUM(amount), 0) AS total_amount,
                    IFNULL(AVG(amount), 0) AS average_amount,
                    IFNULL(MAX(amount), 0) AS largest_amount
             FROM Transaction
             WHERE t_date BETWEEN ? AND ?"
        );
        $stmt->bind_param("ss", $from_date, $to_date);
        $stmt->execute();
        $txn_summary = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $stmt = $conn->prepare(
            "SELECT transaction_type, COUNT(*) AS total,
                    IFNULL(SUM(amount), 0) AS total_amount
             FROM Transaction
             WHERE t_date BETWEEN ? AND ?
             GROUP BY transaction_type
             ORDER BY total_amount DESC"
        );
        $stmt->bind_param("ss", $from_date, $to_date);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $txn_by_type[] = $row;
        }
        $stmt->close();

        $stmt = $conn->prepare(
            "SELECT t_date, COUNT(*) AS total, IFNULL(SUM(amount), 0) AS total_amount
             FROM Transaction
             WHERE t_date BETWEEN ? AND ?
             GROUP BY t_date
             ORDER BY t_date DESC
             LIMIT 15"
        );
        $stmt->bind_param("ss", $from_date, $to_date);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $txn_by_date[] = $row;
        }
        $stmt->close();

    } else {
        $result = $conn->query(
            "SELECT COUNT(*) AS total_count,
                    IFNULL(SUM(amount), 0) AS total_amount,
                    IFNULL(AVG(amount), 0) AS average_amount,
                    IFNULL(MAX(amount), 0) AS largest_amount
             FROM Transaction"
        );
        if ($result) {
            $txn_summary = $result->fetch_assoc();
        }

        $result = $conn->query(
            "SELECT transaction_type, COUNT(*) AS total,
                    IFNULL(SUM(amount), 0) AS total_amount
             FROM Transaction
             GROUP BY transaction_type
             ORDER BY total_amount DESC"
        );
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $txn_by_type[] = $row;
            }
        }

        $result = $conn->query(
            "SELECT t_date, COUNT(*) AS total, IFNULL(SUM(amount), 0) AS total_amount
             FROM Transaction
             GROUP BY t_date
             ORDER BY t_date DESC
             LIMIT 15"
        );
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $txn_by_date[] = $row;
            }
        }
    }

    // =========================================================
    //  LOAN REPORT
    // =========================================================

    // Loans grouped by status, with how much has been repaid against each group
    $result = $conn->query(
        "SELECT l.status, COUNT(*) AS total,
                IFNULL(SUM(l.amount), 0) AS total_amount,
                IFNULL(AVG(l.interest_rate), 0) AS average_rate
         FROM Loan l
         GROUP BY l.status
         ORDER BY l.status"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $loan_report[] = $row;
        }
    }

    $result = $conn->query(
        "SELECT
            (SELECT COUNT(*) FROM Loan) AS total_loans,
            (SELECT IFNULL(SUM(amount), 0) FROM Loan) AS total_lent,
            (SELECT IFNULL(SUM(amount), 0) FROM Loan_payment) AS total_repaid,
            (SELECT COUNT(*) FROM Loan_payment) AS total_payments"
    );
    if ($result) {
        $loan_totals = $result->fetch_assoc();
    }

    // =========================================================
    //  BRANCH REPORT
    //  LEFT JOIN so a branch with no accounts still appears
    // =========================================================
    $result = $conn->query(
        "SELECT b.branch_id, b.branch_name,
                (SELECT COUNT(*) FROM Employee e WHERE e.branch_id = b.branch_id) AS employees,
                COUNT(a.account_id) AS accounts,
                IFNULL(SUM(a.balance), 0) AS total_balance,
                (SELECT COUNT(*)
                 FROM Transaction t
                 JOIN Account a2 ON t.account_id = a2.account_id
                 WHERE a2.branch_id = b.branch_id) AS transactions
         FROM Branch b
         LEFT JOIN Account a ON a.branch_id = b.branch_id
         GROUP BY b.branch_id, b.branch_name
         ORDER BY total_balance DESC"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $branch_report[] = $row;
        }
    }

    // =========================================================
    //  FRAUD REPORT
    // =========================================================
    $result = $conn->query(
        "SELECT f.status, COUNT(*) AS total,
                IFNULL(SUM(t.amount), 0) AS total_amount
         FROM Fraud f
         JOIN Transaction t ON f.transaction_id = t.transaction_id
         GROUP BY f.status
         ORDER BY f.status"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $fraud_report[] = $row;
        }
    }

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TakaKoi - Reports &amp; Analytics</title>
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
            <a href="closure_requests.php">Closures</a>
            <a href="cheque_requests.php">Cheque Books</a>
            <a href="reports.php" class="nav-active">Reports</a>
            <a href="employee_login.php">Logout</a>
        </div>
    </nav>

    <div style="max-width: 1100px; margin: 0 auto; padding: 28px 20px;">

        <h1 class="mb-16">Reports &amp; Analytics</h1>

        <?php foreach ($errors as $error) : ?>
            <div class="message message-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>

        <!-- ---------- Overview cards ---------- -->
        <h2 class="mb-16">Overview</h2>
        <div style="display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 24px;">
            <div class="card" style="flex: 1; min-width: 150px;">
                <p class="small-text">Customers</p>
                <h2><?php echo $customer_report["total_customers"] ?? 0; ?></h2>
            </div>
            <div class="card" style="flex: 1; min-width: 150px;">
                <p class="small-text">Accounts</p>
                <h2><?php echo $customer_report["total_accounts"] ?? 0; ?></h2>
            </div>
            <div class="card" style="flex: 1; min-width: 150px;">
                <p class="small-text">Employees</p>
                <h2><?php echo $customer_report["total_employees"] ?? 0; ?></h2>
            </div>
            <div class="card" style="flex: 1; min-width: 150px;">
                <p class="small-text">Branches</p>
                <h2><?php echo $customer_report["total_branches"] ?? 0; ?></h2>
            </div>
        </div>

        <!-- ---------- Account report ---------- -->
        <h2 class="mb-16">Account Report</h2>
        <div class="card mb-16">
            <?php if (empty($account_report)) : ?>
                <p>No accounts on record.</p>
            <?php else : ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Status</th>
                        <th style="padding: 8px 6px;">Type</th>
                        <th style="padding: 8px 6px;">Accounts</th>
                        <th style="padding: 8px 6px;">Total Balance</th>
                        <th style="padding: 8px 6px;">Average Balance</th>
                    </tr>
                    <?php foreach ($account_report as $row) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($row["status"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($row["account_type"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($row["total"]); ?></td>
                            <td style="padding: 8px 6px;">Tk. <?php echo number_format($row["total_balance"], 2); ?></td>
                            <td style="padding: 8px 6px;">Tk. <?php echo number_format($row["average_balance"], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

        <!-- ---------- Transaction report ---------- -->
        <h2 class="mb-16">Transaction Report</h2>

        <div class="card mb-16">
            <form action="reports.php" method="GET" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
                <div class="form-group" style="margin-bottom:0;">
                    <label for="from_date">From</label>
                    <input type="date" id="from_date" name="from_date"
                           value="<?php echo htmlspecialchars($from_date); ?>"
                           style="padding:10px 12px; border-radius:8px;
                                  border:1px solid rgba(48,27,7,0.25); font-size:14px;">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label for="to_date">To</label>
                    <input type="date" id="to_date" name="to_date"
                           value="<?php echo htmlspecialchars($to_date); ?>"
                           style="padding:10px 12px; border-radius:8px;
                                  border:1px solid rgba(48,27,7,0.25); font-size:14px;">
                </div>
                <button type="submit" class="btn btn-primary"
                        style="width:auto; padding:10px 20px; margin-bottom:0;">Filter</button>
                <a href="reports.php" class="btn btn-outline"
                   style="width:auto; padding:10px 20px; margin-bottom:0;">Clear</a>
            </form>
            <?php if ($use_filter) : ?>
                <p class="small-text mt-16">
                    Showing transactions from <?php echo htmlspecialchars($from_date); ?>
                    to <?php echo htmlspecialchars($to_date); ?>.
                </p>
            <?php endif; ?>
        </div>

        <div style="display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 24px;">
            <div class="card" style="flex: 1; min-width: 150px;">
                <p class="small-text">Transactions</p>
                <h2><?php echo $txn_summary["total_count"] ?? 0; ?></h2>
            </div>
            <div class="card" style="flex: 1; min-width: 150px;">
                <p class="small-text">Total Value</p>
                <h2>Tk. <?php echo number_format($txn_summary["total_amount"] ?? 0, 2); ?></h2>
            </div>
            <div class="card" style="flex: 1; min-width: 150px;">
                <p class="small-text">Average Value</p>
                <h2>Tk. <?php echo number_format($txn_summary["average_amount"] ?? 0, 2); ?></h2>
            </div>
            <div class="card" style="flex: 1; min-width: 150px;">
                <p class="small-text">Largest Single</p>
                <h2>Tk. <?php echo number_format($txn_summary["largest_amount"] ?? 0, 2); ?></h2>
            </div>
        </div>

        <div class="card mb-16">
            <h3>By Type</h3>
            <?php if (empty($txn_by_type)) : ?>
                <p>No transactions in this period.</p>
            <?php else : ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Type</th>
                        <th style="padding: 8px 6px;">Count</th>
                        <th style="padding: 8px 6px;">Total Amount</th>
                    </tr>
                    <?php foreach ($txn_by_type as $row) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($row["transaction_type"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($row["total"]); ?></td>
                            <td style="padding: 8px 6px;">Tk. <?php echo number_format($row["total_amount"], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

        <div class="card mb-16">
            <h3>Daily Activity</h3>
            <?php if (empty($txn_by_date)) : ?>
                <p>No transactions in this period.</p>
            <?php else : ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Date</th>
                        <th style="padding: 8px 6px;">Transactions</th>
                        <th style="padding: 8px 6px;">Total Amount</th>
                    </tr>
                    <?php foreach ($txn_by_date as $row) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($row["t_date"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($row["total"]); ?></td>
                            <td style="padding: 8px 6px;">Tk. <?php echo number_format($row["total_amount"], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

        <!-- ---------- Loan report ---------- -->
        <h2 class="mb-16">Loan Report</h2>

        <div style="display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 24px;">
            <div class="card" style="flex: 1; min-width: 150px;">
                <p class="small-text">Total Loans</p>
                <h2><?php echo $loan_totals["total_loans"] ?? 0; ?></h2>
            </div>
            <div class="card" style="flex: 1; min-width: 150px;">
                <p class="small-text">Total Lent</p>
                <h2>Tk. <?php echo number_format($loan_totals["total_lent"] ?? 0, 2); ?></h2>
            </div>
            <div class="card" style="flex: 1; min-width: 150px;">
                <p class="small-text">Total Repaid</p>
                <h2>Tk. <?php echo number_format($loan_totals["total_repaid"] ?? 0, 2); ?></h2>
            </div>
            <div class="card" style="flex: 1; min-width: 150px;">
                <p class="small-text">Payments Made</p>
                <h2><?php echo $loan_totals["total_payments"] ?? 0; ?></h2>
            </div>
        </div>

        <div class="card mb-16">
            <?php if (empty($loan_report)) : ?>
                <p>No loans on record.</p>
            <?php else : ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Status</th>
                        <th style="padding: 8px 6px;">Loans</th>
                        <th style="padding: 8px 6px;">Total Amount</th>
                        <th style="padding: 8px 6px;">Average Rate</th>
                    </tr>
                    <?php foreach ($loan_report as $row) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($row["status"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($row["total"]); ?></td>
                            <td style="padding: 8px 6px;">Tk. <?php echo number_format($row["total_amount"], 2); ?></td>
                            <td style="padding: 8px 6px;"><?php echo number_format($row["average_rate"], 2); ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

        <!-- ---------- Branch report ---------- -->
        <h2 class="mb-16">Branch Report</h2>
        <div class="card mb-16">
            <?php if (empty($branch_report)) : ?>
                <p>No branches on record.</p>
            <?php else : ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Branch</th>
                        <th style="padding: 8px 6px;">Employees</th>
                        <th style="padding: 8px 6px;">Accounts</th>
                        <th style="padding: 8px 6px;">Transactions</th>
                        <th style="padding: 8px 6px;">Total Balance</th>
                    </tr>
                    <?php foreach ($branch_report as $row) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($row["branch_name"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($row["employees"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($row["accounts"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($row["transactions"]); ?></td>
                            <td style="padding: 8px 6px;">Tk. <?php echo number_format($row["total_balance"], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

        <!-- ---------- Fraud report ---------- -->
        <h2 class="mb-16">Fraud Report</h2>
        <div class="card mb-16">
            <?php if (empty($fraud_report)) : ?>
                <p>No fraud alerts on record.</p>
            <?php else : ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Status</th>
                        <th style="padding: 8px 6px;">Alerts</th>
                        <th style="padding: 8px 6px;">Value Flagged</th>
                    </tr>
                    <?php foreach ($fraud_report as $row) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($row["status"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($row["total"]); ?></td>
                            <td style="padding: 8px 6px;">Tk. <?php echo number_format($row["total_amount"], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

    </div>

</body>
</html>
