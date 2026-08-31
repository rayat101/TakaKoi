<?php
// fraud_alert.php - Feature 5: single fraud alert detail page
// Opened from fraud_monitoring.php as fraud_alert.php?alert_id=...
// Shows the full alert with its transaction, account and customer, and lets
// an employee change the alert status.

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

$errors          = [];
$success_message = "";
$alert           = null;

// The alert can arrive from the link (GET) or from the status form (POST)
$alert_id = $_POST["alert_id"] ?? $_GET["alert_id"] ?? "";

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    $errors[] = "Could not connect to the database. Please try again later.";
} elseif ($alert_id === "" || !ctype_digit($alert_id)) {
    $errors[] = "No alert was selected.";
} else {

    // ---------- Handle a status change ----------
    if ($_SERVER["REQUEST_METHOD"] === "POST") {

        $new_status = $_POST["new_status"] ?? "";
        $allowed_status = ["Pending", "Investigating", "Confirmed", "Dismissed"];

        if (!in_array($new_status, $allowed_status)) {
            $errors[] = "Invalid status.";
        } else {
            $stmt = $conn->prepare("UPDATE Fraud SET status = ? WHERE alert_id = ?");
            $stmt->bind_param("si", $new_status, $alert_id);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                $success_message = "Alert status changed to " . $new_status . ".";
            } else {
                $errors[] = "The status was not changed. It may already be set to that value.";
            }
            $stmt->close();
        }
    }

    // ---------- Load the full alert ----------
    // Fraud -> Transaction -> Account -> Customer
    $stmt = $conn->prepare(
        "SELECT f.alert_id, f.reason, f.alert_date, f.status,
                t.transaction_id, t.transaction_type, t.amount,
                t.t_date, t.t_time, t.description, t.sent_to_id,
                a.account_id, a.account_type, a.balance, a.status AS account_status,
                c.customer_id, c.first_name, c.last_name, c.email,
                b.branch_name
         FROM Fraud f
         JOIN Transaction t ON f.transaction_id = t.transaction_id
         JOIN Account a     ON t.account_id = a.account_id
         JOIN Customer c    ON a.customer_id = c.customer_id
         JOIN Branch b      ON a.branch_id = b.branch_id
         WHERE f.alert_id = ?"
    );
    $stmt->bind_param("i", $alert_id);
    $stmt->execute();
    $alert = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$alert) {
        $errors[] = "That fraud alert was not found.";
    }

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TakaKoi - Fraud Alert</title>
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

    <div style="max-width: 900px; margin: 0 auto; padding: 28px 20px;">

        <h1 class="mb-16">Fraud Alert Details</h1>

        <p class="small-text mb-16">
            <a href="fraud_monitoring.php">&larr; Back to Fraud Monitoring</a>
        </p>

        <?php foreach ($errors as $error) : ?>
            <div class="message message-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>

        <?php if ($success_message !== "") : ?>
            <div class="message message-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <?php if ($alert) : ?>

            <h2 class="mb-16">Alert</h2>
            <div class="card mb-16">
                <p><strong>Alert ID:</strong> <?php echo htmlspecialchars($alert["alert_id"]); ?></p>
                <p><strong>Reason:</strong> <?php echo htmlspecialchars($alert["reason"]); ?></p>
                <p><strong>Alert Date:</strong> <?php echo htmlspecialchars($alert["alert_date"]); ?></p>
                <p>
                    <strong>Status:</strong>
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
                </p>
            </div>

            <h2 class="mb-16">Transaction</h2>
            <div class="card mb-16">
                <p><strong>Transaction ID:</strong> <?php echo htmlspecialchars($alert["transaction_id"]); ?></p>
                <p><strong>Type:</strong> <?php echo htmlspecialchars($alert["transaction_type"]); ?></p>
                <p><strong>Amount:</strong> Tk. <?php echo number_format($alert["amount"], 2); ?></p>
                <p><strong>Date:</strong> <?php echo htmlspecialchars($alert["t_date"]); ?></p>
                <p><strong>Time:</strong> <?php echo htmlspecialchars($alert["t_time"]); ?></p>
                <p><strong>Description:</strong> <?php echo htmlspecialchars($alert["description"]); ?></p>
                <p><strong>Source Account:</strong> <?php echo htmlspecialchars($alert["account_id"]); ?></p>
                <p>
                    <strong>Destination Account:</strong>
                    <?php
                        // sent_to_id is only filled in for transfers
                        echo $alert["sent_to_id"] === null
                            ? "Not applicable"
                            : htmlspecialchars($alert["sent_to_id"]);
                    ?>
                </p>
            </div>

            <h2 class="mb-16">Account &amp; Customer</h2>
            <div class="card mb-16">
                <p><strong>Customer:</strong>
                    <?php echo htmlspecialchars($alert["first_name"] . " " . $alert["last_name"]); ?>
                    (ID: <?php echo htmlspecialchars($alert["customer_id"]); ?>)
                </p>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($alert["email"]); ?></p>
                <p><strong>Account Type:</strong> <?php echo htmlspecialchars($alert["account_type"]); ?></p>
                <p><strong>Account Status:</strong> <?php echo htmlspecialchars($alert["account_status"]); ?></p>
                <p><strong>Current Balance:</strong> Tk. <?php echo number_format($alert["balance"], 2); ?></p>
                <p><strong>Branch:</strong> <?php echo htmlspecialchars($alert["branch_name"]); ?></p>
            </div>

            <h2 class="mb-16">Update Status</h2>
            <div class="card mb-16">
                <form action="fraud_alert.php" method="POST">
                    <input type="hidden" name="alert_id"
                           value="<?php echo htmlspecialchars($alert["alert_id"]); ?>">

                    <div class="form-group">
                        <label for="new_status">New Status</label>
                        <select id="new_status" name="new_status" required
                                style="width:100%; padding:10px 12px; border-radius:8px;
                                       border:1px solid rgba(48,27,7,0.25); font-size:14px;">
                            <option value="Pending">Pending</option>
                            <option value="Investigating">Investigating</option>
                            <option value="Confirmed">Confirmed</option>
                            <option value="Dismissed">Dismissed</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary">Update Status</button>
                </form>
            </div>

        <?php endif; ?>

    </div>

</body>
</html>
