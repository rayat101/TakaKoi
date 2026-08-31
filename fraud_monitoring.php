<?php
// fraud_monitoring.php - Feature 5: Fraud Detection and Transaction Monitoring
// Employees can run a rule-based scan over the Transaction table, create Fraud
// alerts for suspicious transactions, and change the status of existing alerts.

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

// --- Detection thresholds (kept as variables so they are easy to explain/change) ---
$LARGE_AMOUNT       = 200000;   // any transaction above this is unusual for this bank
$LARGE_WITHDRAWAL   = 100000;   // withdrawals are riskier, so a lower limit is used
$MAX_DAILY_COUNT    = 4;        // more than this many transactions in one day on one account

$errors          = [];
$success_message = "";
$alerts          = [];
$stats           = [
    "total"         => 0,
    "Pending"       => 0,
    "Investigating" => 0,
    "Confirmed"     => 0,
    "Dismissed"     => 0,
];

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    $errors[] = "Could not connect to the database. Please try again later.";
} else {

    // =========================================================
    //  ACTION 1 - Update the status of an existing alert
    // =========================================================
    if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_status"])) {

        $alert_id   = $_POST["alert_id"] ?? "";
        $new_status = $_POST["new_status"] ?? "";

        $allowed_status = ["Pending", "Investigating", "Confirmed", "Dismissed"];

        if ($alert_id === "" || !ctype_digit($alert_id)) {
            $errors[] = "Invalid alert.";
        }

        if (!in_array($new_status, $allowed_status)) {
            $errors[] = "Invalid status.";
        }

        if (empty($errors)) {
            $stmt = $conn->prepare("UPDATE Fraud SET status = ? WHERE alert_id = ?");
            $stmt->bind_param("si", $new_status, $alert_id);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                $success_message = "Alert " . $alert_id . " is now " . $new_status . ".";
            } else {
                $errors[] = "That alert could not be updated.";
            }
            $stmt->close();
        }
    }

    // =========================================================
    //  ACTION 2 - Scan transactions and create new alerts
    // =========================================================
    if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["run_scan"])) {

        $suspicious = [];   // transaction_id => reason

        // ---- Rule 1: very large transaction of any type ----
        $stmt = $conn->prepare(
            "SELECT transaction_id, amount
             FROM Transaction
             WHERE amount > ?
               AND transaction_id NOT IN (SELECT transaction_id FROM Fraud)"
        );
        $stmt->bind_param("d", $LARGE_AMOUNT);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $suspicious[$row["transaction_id"]] =
                "Unusually large transaction of Tk. " . number_format($row["amount"], 2);
        }
        $stmt->close();

        // ---- Rule 2: large withdrawal (lower threshold than rule 1) ----
        $stmt = $conn->prepare(
            "SELECT transaction_id, amount
             FROM Transaction
             WHERE transaction_type = 'Withdrawal'
               AND amount > ?
               AND transaction_id NOT IN (SELECT transaction_id FROM Fraud)"
        );
        $stmt->bind_param("d", $LARGE_WITHDRAWAL);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $suspicious[$row["transaction_id"]] =
                "Large withdrawal of Tk. " . number_format($row["amount"], 2);
        }
        $stmt->close();

        // ---- Rule 3: too many transactions by one account on one day ----
        // The inner query finds the busy (account, date) pairs.
        // The outer query then lists the transactions that belong to them.
        $stmt = $conn->prepare(
            "SELECT t.transaction_id, busy.total
             FROM Transaction t
             JOIN (
                    SELECT account_id, t_date, COUNT(*) AS total
                    FROM Transaction
                    GROUP BY account_id, t_date
                    HAVING COUNT(*) > ?
                  ) AS busy
               ON t.account_id = busy.account_id AND t.t_date = busy.t_date
             WHERE t.transaction_id NOT IN (SELECT transaction_id FROM Fraud)"
        );
        $stmt->bind_param("i", $MAX_DAILY_COUNT);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            // Only set it if no stronger reason was already found
            if (!isset($suspicious[$row["transaction_id"]])) {
                $suspicious[$row["transaction_id"]] =
                    "High activity: " . $row["total"] . " transactions on this account in one day";
            }
        }
        $stmt->close();

        // ---- Insert an alert for each suspicious transaction ----
        $created = 0;
        $today   = date("Y-m-d");

        foreach ($suspicious as $transaction_id => $reason) {

            // Generate a unique 8-digit alert_id (schema: 10000000 - 99999999)
            $alert_id = null;
            for ($attempt = 0; $attempt < 20; $attempt++) {
                $candidate = random_int(10000000, 99999999);

                $check = $conn->prepare("SELECT alert_id FROM Fraud WHERE alert_id = ?");
                $check->bind_param("i", $candidate);
                $check->execute();
                $exists = $check->get_result()->num_rows > 0;
                $check->close();

                if (!$exists) {
                    $alert_id = $candidate;
                    break;
                }
            }

            if ($alert_id === null) {
                continue;   // skip this one rather than crash the whole scan
            }

            $stmt = $conn->prepare(
                "INSERT INTO Fraud (alert_id, reason, alert_date, status, transaction_id)
                 VALUES (?, ?, ?, 'Pending', ?)"
            );
            $stmt->bind_param("issi", $alert_id, $reason, $today, $transaction_id);

            if ($stmt->execute()) {
                $created++;
            }
            $stmt->close();
        }

        if ($created > 0) {
            $success_message = "Scan complete. " . $created . " new fraud alert(s) created.";
        } else {
            $success_message = "Scan complete. No new suspicious transactions found.";
        }
    }

    // =========================================================
    //  Statistics cards
    // =========================================================
    $result = $conn->query(
        "SELECT status, COUNT(*) AS total FROM Fraud GROUP BY status"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $stats[$row["status"]] = (int) $row["total"];
            $stats["total"] += (int) $row["total"];
        }
    }

    // =========================================================
    //  Alert list (Fraud -> Transaction -> Account -> Customer)
    // =========================================================
    $result = $conn->query(
        "SELECT f.alert_id, f.reason, f.alert_date, f.status,
                t.transaction_id, t.transaction_type, t.amount, t.t_date, t.t_time,
                a.account_id,
                c.first_name, c.last_name
         FROM Fraud f
         JOIN Transaction t ON f.transaction_id = t.transaction_id
         JOIN Account a     ON t.account_id = a.account_id
         JOIN Customer c    ON a.customer_id = c.customer_id
         ORDER BY FIELD(f.status, 'Pending', 'Investigating', 'Confirmed', 'Dismissed'),
                  f.alert_date DESC"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $alerts[] = $row;
        }
    }

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TakaKoi - Fraud Monitoring</title>
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
            <a href="fraud_monitoring.php" class="nav-active">Fraud Monitoring</a>
            <a href="admin_branches.php">Branches</a>
            <a href="closure_requests.php">Closures</a>
            <a href="cheque_requests.php">Cheque Books</a>
            <a href="reports.php">Reports</a>
            <a href="employee_login.php">Logout</a>
        </div>
    </nav>

    <div style="max-width: 1100px; margin: 0 auto; padding: 28px 20px;">

        <h1 class="mb-16">Fraud Monitoring</h1>

        <?php foreach ($errors as $error) : ?>
            <div class="message message-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>

        <?php if ($success_message !== "") : ?>
            <div class="message message-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <h2 class="mb-16">Alert Summary</h2>
        <div style="display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 24px;">
            <div class="card" style="flex: 1; min-width: 150px;">
                <p class="small-text">Total Alerts</p>
                <h2><?php echo $stats["total"]; ?></h2>
            </div>
            <div class="card" style="flex: 1; min-width: 150px;">
                <p class="small-text">Pending</p>
                <h2><?php echo $stats["Pending"]; ?></h2>
            </div>
            <div class="card" style="flex: 1; min-width: 150px;">
                <p class="small-text">Investigating</p>
                <h2><?php echo $stats["Investigating"]; ?></h2>
            </div>
            <div class="card" style="flex: 1; min-width: 150px;">
                <p class="small-text">Confirmed</p>
                <h2><?php echo $stats["Confirmed"]; ?></h2>
            </div>
            <div class="card" style="flex: 1; min-width: 150px;">
                <p class="small-text">Dismissed</p>
                <h2><?php echo $stats["Dismissed"]; ?></h2>
            </div>
        </div>

        <h2 class="mb-16">Run Detection Scan</h2>
        <div class="card mb-16">
            <p class="small-text mb-16">
                The scan checks every transaction that does not already have an alert against
                three rules:
                amount above Tk. <?php echo number_format($LARGE_AMOUNT); ?>,
                withdrawal above Tk. <?php echo number_format($LARGE_WITHDRAWAL); ?>,
                or more than <?php echo $MAX_DAILY_COUNT; ?> transactions on one account in a single day.
            </p>
            <form action="fraud_monitoring.php" method="POST">
                <button type="submit" name="run_scan" value="1" class="btn btn-primary"
                        style="width:auto; padding:10px 20px; margin-bottom:0;">
                    Scan Transactions
                </button>
            </form>
        </div>

        <h2 class="mb-16">Fraud Alerts</h2>
        <div class="card mb-16">
            <?php if (empty($alerts)) : ?>
                <p>No fraud alerts yet. Run a scan to check for suspicious transactions.</p>
            <?php else : ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Alert ID</th>
                        <th style="padding: 8px 6px;">Txn ID</th>
                        <th style="padding: 8px 6px;">Type</th>
                        <th style="padding: 8px 6px;">Amount</th>
                        <th style="padding: 8px 6px;">Customer</th>
                        <th style="padding: 8px 6px;">Reason</th>
                        <th style="padding: 8px 6px;">Status</th>
                        <th style="padding: 8px 6px;">Change Status</th>
                        <th style="padding: 8px 6px;">Details</th>
                    </tr>
                    <?php foreach ($alerts as $alert) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($alert["alert_id"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($alert["transaction_id"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($alert["transaction_type"]); ?></td>
                            <td style="padding: 8px 6px;">Tk. <?php echo number_format($alert["amount"], 2); ?></td>
                            <td style="padding: 8px 6px;">
                                <?php echo htmlspecialchars($alert["first_name"] . " " . $alert["last_name"]); ?>
                            </td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($alert["reason"]); ?></td>
                            <td style="padding: 8px 6px;">
                                <?php
                                    $status = $alert["status"];
                                    $status_class = "message-warning";
                                    if ($status === "Confirmed") $status_class = "message-danger";
                                    if ($status === "Dismissed") $status_class = "message-success";
                                ?>
                                <span class="message <?php echo $status_class; ?>"
                                      style="display:inline; padding: 2px 10px;">
                                    <?php echo htmlspecialchars($status); ?>
                                </span>
                            </td>
                            <td style="padding: 8px 6px;">
                                <form action="fraud_monitoring.php" method="POST" style="display:flex; gap:6px;">
                                    <input type="hidden" name="alert_id"
                                           value="<?php echo htmlspecialchars($alert["alert_id"]); ?>">
                                    <select name="new_status"
                                            style="padding:6px 8px; border-radius:8px;
                                                   border:1px solid rgba(48,27,7,0.25); font-size:13px;">
                                        <option value="Pending">Pending</option>
                                        <option value="Investigating">Investigating</option>
                                        <option value="Confirmed">Confirmed</option>
                                        <option value="Dismissed">Dismissed</option>
                                    </select>
                                    <button type="submit" name="update_status" value="1" class="btn btn-secondary"
                                            style="width:auto; padding:6px 12px; margin-bottom:0; font-size:13px;">
                                        Save
                                    </button>
                                </form>
                            </td>
                            <td style="padding: 8px 6px;">
                                <a href="fraud_alert.php?alert_id=<?php echo htmlspecialchars($alert["alert_id"]); ?>">
                                    View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

    </div>

</body>
</html>
