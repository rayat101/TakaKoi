<?php
// customer_dashboard.php

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

$customer   = null;
$accounts   = [];
$transactions = [];
$loans      = [];
$db_error   = "";

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    $db_error = "Could not connect to the database. Please try again later.";
} else {

    // --- Customer info ---
    $stmt = $conn->prepare("SELECT * FROM Customer WHERE customer_id = ?");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // --- Accounts ---
    $stmt = $conn->prepare("SELECT * FROM Account WHERE customer_id = ?");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $accounts[] = $row;
    }
    $stmt->close();

    // --- Recent transactions ---
    $stmt = $conn->prepare(
        "SELECT t.* FROM Transaction t
         JOIN Account a ON t.account_id = a.account_id
         WHERE a.customer_id = ?
         ORDER BY t.t_date DESC, t.t_time DESC
         LIMIT 5"
    );
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
    $stmt->close();

    // --- Loans ---
    $stmt = $conn->prepare("SELECT * FROM Loan WHERE customer_id = ? ORDER BY start_date DESC");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $loans[] = $row;
    }
    $stmt->close();

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TakaKoi - Customer Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

    <nav class="navbar">
        <div class="logo-wrapper" style="flex-direction: row; align-items: center; margin-bottom: 0;">
            <img src="img/logo.png" alt="TakaKoi logo" style="width: 36px; margin-bottom: 0; margin-right: 10px;">
        </div>
        <div>
            <a href="customer_dashboard.php" class="nav-active">Dashboard</a>
            <a href="#">Accounts</a>
            <a href="#">Transactions</a>
            <a href="#">Transfer</a>
            <a href="#">Loans</a>
            <a href="login.php">Logout</a>
        </div>
    </nav>

    <div style="max-width: 900px; margin: 0 auto; padding: 28px 20px;">

        <?php if ($db_error !== "") : ?>
            <div class="message message-danger"><?php echo htmlspecialchars($db_error); ?></div>
        <?php else : ?>

            <h1 class="mb-16">
                Welcome, <?php echo htmlspecialchars($customer["first_name"] . " " . $customer["last_name"]); ?>
            </h1>
            <p class="small-text mb-16">Customer ID: <?php echo htmlspecialchars($customer["customer_id"]); ?></p>

            <h2 class="mb-16">Account Overview</h2>

            <?php if (empty($accounts)) : ?>
                <div class="card mb-16">
                    <p>No accounts found yet.</p>
                </div>
            <?php else : ?>
                <div style="display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 24px;">
                    <?php foreach ($accounts as $account) : ?>
                        <div class="card" style="flex: 1; min-width: 220px;">
                            <h3>Account #<?php echo htmlspecialchars($account["account_id"]); ?></h3>
                            <p><strong>Balance:</strong> Tk. <?php echo number_format($account["balance"], 2); ?></p>
                            <p><strong>Type:</strong> <?php echo htmlspecialchars($account["account_type"]); ?></p>
                            <p>
                                <strong>Status:</strong>
                                <?php
                                    $status = $account["status"];
                                    $status_class = "message-success";
                                    if ($status === "Suspended") $status_class = "message-warning";
                                    if ($status === "Closed") $status_class = "message-danger";
                                ?>
                                <span class="message <?php echo $status_class; ?>" style="display:inline; padding: 2px 10px;">
                                    <?php echo htmlspecialchars($status); ?>
                                </span>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h2 class="mb-16">Recent Transactions</h2>
            <div class="card mb-16">
                <?php if (empty($transactions)) : ?>
                    <p>No transactions yet.</p>
                <?php else : ?>
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                            <th style="padding: 8px 6px;">Date</th>
                            <th style="padding: 8px 6px;">Type</th>
                            <th style="padding: 8px 6px;">Amount</th>
                            <th style="padding: 8px 6px;">Description</th>
                        </tr>
                        <?php foreach ($transactions as $t) : ?>
                            <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($t["t_date"]); ?></td>
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($t["transaction_type"]); ?></td>
                                <td style="padding: 8px 6px;">Tk. <?php echo number_format($t["amount"], 2); ?></td>
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($t["description"]); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>

            <h2 class="mb-16">Loan Overview</h2>
            <div class="card mb-16">
                <?php if (empty($loans)) : ?>
                    <p>No loans on record.</p>
                <?php else : ?>
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                            <th style="padding: 8px 6px;">Loan Type</th>
                            <th style="padding: 8px 6px;">Amount</th>
                            <th style="padding: 8px 6px;">Status</th>
                            <th style="padding: 8px 6px;">Start Date</th>
                        </tr>
                        <?php foreach ($loans as $loan) : ?>
                            <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($loan["loan_type"]); ?></td>
                                <td style="padding: 8px 6px;">Tk. <?php echo number_format($loan["amount"], 2); ?></td>
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($loan["status"]); ?></td>
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($loan["start_date"]); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>

        <?php endif; ?>

    </div>

</body>
</html>
