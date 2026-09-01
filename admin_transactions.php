<?php
// admin_transactions.php - Every transaction in the bank (read only)
// Employees can filter by type, date range and minimum amount, and sort the results.

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

$errors       = [];
$transactions = [];
$summary      = [];

// --- Filter values from the URL ---
$type       = $_GET["type"]       ?? "";
$from_date  = $_GET["from_date"]  ?? "";
$to_date    = $_GET["to_date"]    ?? "";
$min_amount = $_GET["min_amount"] ?? "";
$sort       = $_GET["sort"]       ?? "date_desc";

// Sort choices are whitelisted because a column name cannot be bound as a
// prepared statement parameter.
$sort_options = [
    "date_desc"   => "t.t_date DESC, t.t_time DESC",
    "date_asc"    => "t.t_date ASC, t.t_time ASC",
    "amount_desc" => "t.amount DESC",
    "amount_asc"  => "t.amount ASC",
];

if (!array_key_exists($sort, $sort_options)) {
    $sort = "date_desc";
}

$order_by = $sort_options[$sort];

$allowed_types = ["Deposit", "Withdrawal", "Transfer"];

function valid_date($d) {
    return $d !== "" && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
}

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    $errors[] = "Could not connect to the database. Please try again later.";
} else {

    // ---------- Build the WHERE clause piece by piece ----------
    // Each accepted filter adds a condition plus its bound value, so the
    // query stays a prepared statement no matter which filters are used.
    $where  = [];
    $params = [];
    $types  = "";

    if ($type !== "") {
        if (in_array($type, $allowed_types)) {
            $where[]  = "t.transaction_type = ?";
            $params[] = $type;
            $types   .= "s";
        } else {
            $errors[] = "Unknown transaction type. Showing all types.";
            $type = "";
        }
    }

    if ($from_date !== "") {
        if (valid_date($from_date)) {
            $where[]  = "t.t_date >= ?";
            $params[] = $from_date;
            $types   .= "s";
        } else {
            $errors[] = "The From date was ignored - use YYYY-MM-DD.";
            $from_date = "";
        }
    }

    if ($to_date !== "") {
        if (valid_date($to_date)) {
            $where[]  = "t.t_date <= ?";
            $params[] = $to_date;
            $types   .= "s";
        } else {
            $errors[] = "The To date was ignored - use YYYY-MM-DD.";
            $to_date = "";
        }
    }

    if ($min_amount !== "") {
        if (is_numeric($min_amount) && $min_amount >= 0) {
            $where[]  = "t.amount >= ?";
            $params[] = (float) $min_amount;
            $types   .= "d";
        } else {
            $errors[] = "The minimum amount was ignored - it must be a number.";
            $min_amount = "";
        }
    }

    $where_sql = empty($where) ? "" : " WHERE " . implode(" AND ", $where);

    // ---------- Summary for the current filter ----------
    $sql = "SELECT COUNT(*) AS total_count,
                   IFNULL(SUM(t.amount), 0) AS total_amount,
                   IFNULL(AVG(t.amount), 0) AS average_amount,
                   IFNULL(MAX(t.amount), 0) AS largest_amount
            FROM Transaction t" . $where_sql;

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // ---------- The transaction list ----------
    // Joined out to Account and Customer so the employee sees who it belongs to.
    // Fraud is LEFT JOINed so flagged transactions can be marked.
    $sql = "SELECT t.*, a.account_type, a.status AS account_status,
                   c.customer_id, c.first_name, c.last_name,
                   b.branch_name,
                   f.alert_id, f.status AS fraud_status
            FROM Transaction t
            JOIN Account a       ON t.account_id = a.account_id
            JOIN Customer c      ON a.customer_id = c.customer_id
            JOIN Branch b        ON a.branch_id = b.branch_id
            LEFT JOIN Fraud f    ON t.transaction_id = f.transaction_id"
            . $where_sql .
          " ORDER BY " . $order_by . " LIMIT 100";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
    $stmt->close();

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TakaKoi - All Transactions</title>
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
            <a href="admin_transactions.php" class="nav-active">Transactions</a>
            <a href="admin_loans.php">Loans</a>
            <a href="admin_employees.php">Employees</a>
            <a href="fraud_monitoring.php">Fraud Monitoring</a>
            <a href="admin_branches.php">Branches</a>
            <a href="closure_requests.php">Closures</a>
            <a href="cheque_requests.php">Cheque Books</a>
            <a href="reports.php">Reports</a>
            <a href="employee_login.php">Logout</a>
        </div>
    </nav>

    <div style="max-width: 1150px; margin: 0 auto; padding: 28px 20px;">

        <h1 class="mb-16">All Transactions</h1>

        <?php foreach ($errors as $error) : ?>
            <div class="message message-warning"><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>

        <h2 class="mb-16">Filter</h2>
        <div class="card mb-16">
            <form action="admin_transactions.php" method="GET"
                  style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">

                <div class="form-group" style="margin-bottom:0;">
                    <label for="type">Type</label>
                    <select id="type" name="type"
                            style="padding:10px 12px; border-radius:8px;
                                   border:1px solid rgba(48,27,7,0.25); font-size:14px;">
                        <option value="">All</option>
                        <option value="Deposit"    <?php if ($type === "Deposit")    echo "selected"; ?>>Deposit</option>
                        <option value="Withdrawal" <?php if ($type === "Withdrawal") echo "selected"; ?>>Withdrawal</option>
                        <option value="Transfer"   <?php if ($type === "Transfer")   echo "selected"; ?>>Transfer</option>
                    </select>
                </div>

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

                <div class="form-group" style="margin-bottom:0;">
                    <label for="min_amount">Min Amount</label>
                    <input type="text" id="min_amount" name="min_amount" placeholder="0"
                           value="<?php echo htmlspecialchars($min_amount); ?>"
                           style="width:110px;">
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label for="sort">Sort By</label>
                    <select id="sort" name="sort"
                            style="padding:10px 12px; border-radius:8px;
                                   border:1px solid rgba(48,27,7,0.25); font-size:14px;">
                        <option value="date_desc"   <?php if ($sort === "date_desc")   echo "selected"; ?>>Newest First</option>
                        <option value="date_asc"    <?php if ($sort === "date_asc")    echo "selected"; ?>>Oldest First</option>
                        <option value="amount_desc" <?php if ($sort === "amount_desc") echo "selected"; ?>>Largest Amount</option>
                        <option value="amount_asc"  <?php if ($sort === "amount_asc")  echo "selected"; ?>>Smallest Amount</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary"
                        style="width:auto; padding:10px 20px; margin-bottom:0;">Apply</button>
                <a href="admin_transactions.php" class="btn btn-outline"
                   style="width:auto; padding:10px 20px; margin-bottom:0;">Clear</a>
            </form>
        </div>

        <h2 class="mb-16">Summary</h2>
        <div style="display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 24px;">
            <div class="card" style="flex: 1; min-width: 150px;">
                <p class="small-text">Matching Transactions</p>
                <h2><?php echo $summary["total_count"] ?? 0; ?></h2>
            </div>
            <div class="card" style="flex: 1; min-width: 150px;">
                <p class="small-text">Total Value</p>
                <h2>Tk. <?php echo number_format($summary["total_amount"] ?? 0, 2); ?></h2>
            </div>
            <div class="card" style="flex: 1; min-width: 150px;">
                <p class="small-text">Average</p>
                <h2>Tk. <?php echo number_format($summary["average_amount"] ?? 0, 2); ?></h2>
            </div>
            <div class="card" style="flex: 1; min-width: 150px;">
                <p class="small-text">Largest</p>
                <h2>Tk. <?php echo number_format($summary["largest_amount"] ?? 0, 2); ?></h2>
            </div>
        </div>

        <h2 class="mb-16">Transactions</h2>
        <div class="card mb-16">
            <?php if (empty($transactions)) : ?>
                <p>No transactions match these filters.</p>
            <?php else : ?>
                <p class="small-text mb-16">
                    Showing <?php echo count($transactions); ?> transaction(s).
                    The list is capped at the 100 most relevant rows.
                </p>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Txn ID</th>
                        <th style="padding: 8px 6px;">Date</th>
                        <th style="padding: 8px 6px;">Time</th>
                        <th style="padding: 8px 6px;">Type</th>
                        <th style="padding: 8px 6px;">Amount</th>
                        <th style="padding: 8px 6px;">From Account</th>
                        <th style="padding: 8px 6px;">To Account</th>
                        <th style="padding: 8px 6px;">Customer</th>
                        <th style="padding: 8px 6px;">Branch</th>
                        <th style="padding: 8px 6px;">Flagged</th>
                    </tr>
                    <?php foreach ($transactions as $t) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($t["transaction_id"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($t["t_date"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($t["t_time"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($t["transaction_type"]); ?></td>
                            <td style="padding: 8px 6px;">Tk. <?php echo number_format($t["amount"], 2); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($t["account_id"]); ?></td>
                            <td style="padding: 8px 6px;">
                                <?php echo $t["sent_to_id"] === null ? "-" : htmlspecialchars($t["sent_to_id"]); ?>
                            </td>
                            <td style="padding: 8px 6px;">
                                <a href="admin_customer_view.php?customer_id=<?php echo htmlspecialchars($t["customer_id"]); ?>">
                                    <?php echo htmlspecialchars($t["first_name"] . " " . $t["last_name"]); ?>
                                </a>
                            </td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($t["branch_name"]); ?></td>
                            <td style="padding: 8px 6px;">
                                <?php if ($t["alert_id"] === null) : ?>
                                    -
                                <?php else : ?>
                                    <a href="fraud_alert.php?alert_id=<?php echo htmlspecialchars($t["alert_id"]); ?>">
                                        <span class="message message-danger"
                                              style="display:inline; padding: 2px 10px;">
                                            <?php echo htmlspecialchars($t["fraud_status"]); ?>
                                        </span>
                                    </a>
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
