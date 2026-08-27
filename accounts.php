<?php
set_exception_handler(function($e) {
    die("<b>Crash Report:</b> " . $e->getMessage() . " on line " . $e->getLine());
});
// accounts.php - Customer bank account management
// Customer can view their bank accounts and open a new one.
// New accounts are created with status 'Suspended' (an employee activates them later).

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

$errors        = [];
$success_message = "";
$accounts      = [];
$branches      = [];

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    $errors[] = "Could not connect to the database. Please try again later.";
} else {

    // ---------- Handle new bank account creation ----------
    if ($_SERVER["REQUEST_METHOD"] === "POST") {

        $account_type = $_POST["account_type"] ?? "";
        $branch_id    = $_POST["branch_id"] ?? "";

        // Only these two types exist in the Account table
        if ($account_type !== "Saving" && $account_type !== "Current") {
            $errors[] = "Please choose a valid account type.";
        }

        if ($branch_id === "" || !ctype_digit($branch_id)) {
            $errors[] = "Please choose a branch.";
        }

        if (empty($errors)) {

            // --- Generate a unique 10-digit account_id ---
            // The schema requires account_id BETWEEN 1000000000 AND 2147483647
            $account_id = null;

            for ($attempt = 0; $attempt < 20; $attempt++) {
                $candidate = random_int(1000000000, 2147483647);

                $check = $conn->prepare("SELECT account_id FROM Account WHERE account_id = ?");
                $check->bind_param("i", $candidate);
                $check->execute();
                $exists = $check->get_result()->num_rows > 0;
                $check->close();

                if (!$exists) {
                    $account_id = $candidate;
                    break;
                }
            }

            if ($account_id === null) {
                $errors[] = "Could not generate an account number. Please try again.";
            } else {

                $today = date("Y-m-d");

                $stmt = $conn->prepare(
                    "INSERT INTO Account
                     (account_id, balance, opening_date, status, account_type, customer_id, branch_id)
                     VALUES (?, 0.00, ?, 'Suspended', ?, ?, ?)"
                );
                $stmt->bind_param("issii", $account_id, $today, $account_type, $customer_id, $branch_id);

                if ($stmt->execute()) {
                    $success_message = "Bank account " . $account_id .
                        " created successfully. It is Suspended until an employee activates it.";
                } else {
                    $errors[] = "Could not create the account. Please try again.";
                }

                $stmt->close();
            }
        }
    }

    // ---------- Load this customer's accounts ----------
    $stmt = $conn->prepare(
        "SELECT a.*, b.branch_name
         FROM Account a
         JOIN Branch b ON a.branch_id = b.branch_id
         WHERE a.customer_id = ?
         ORDER BY a.opening_date DESC"
    );
    if (!$stmt) {
        die("Database Error: " . $conn->error);
    }
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $accounts[] = $row;
    }
    $stmt->close();

    // ---------- Load branches for the dropdown ----------
    $result = $conn->query("SELECT branch_id, branch_name FROM Branch ORDER BY branch_name");
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
    <title>TakaKoi - My Accounts</title>
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
            <a href="accounts.php" class="nav-active">Accounts</a>
            <a href="transactions.php">Transactions</a>
            <a href="transfer.php">Transfer</a>
            <a href="loans.php">Loans</a>
            <a href="login.php">Logout</a>
        </div>
    </nav>

    <div style="max-width: 900px; margin: 0 auto; padding: 28px 20px;">

        <h1 class="mb-16">My Bank Accounts</h1>

        <?php foreach ($errors as $error) : ?>
            <div class="message message-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>

        <?php if ($success_message !== "") : ?>
            <div class="message message-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <h2 class="mb-16">Open a New Bank Account</h2>
        <div class="card mb-16">
            <?php if (empty($branches)) : ?>
                <p>No branches available. Please contact the bank.</p>
            <?php else : ?>
                <form action="accounts.php" method="POST">

                    <div class="form-group">
                        <label for="account_type">Account Type</label>
                        <select id="account_type" name="account_type" required
                                style="width:100%; padding:10px 12px; border-radius:8px;
                                       border:1px solid rgba(48,27,7,0.25); font-size:14px;">
                            <option value="Saving">Saving</option>
                            <option value="Current">Current</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="branch_id">Branch</label>
                        <select id="branch_id" name="branch_id" required
                                style="width:100%; padding:10px 12px; border-radius:8px;
                                       border:1px solid rgba(48,27,7,0.25); font-size:14px;">
                            <?php foreach ($branches as $branch) : ?>
                                <option value="<?php echo htmlspecialchars($branch["branch_id"]); ?>">
                                    <?php echo htmlspecialchars($branch["branch_name"]); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <p class="small-text mb-16">
                        New accounts start as <strong>Suspended</strong>.
                        A bank employee will activate the account for you.
                    </p>

                    <button type="submit" class="btn btn-primary">Create Bank Account</button>

                </form>
            <?php endif; ?>
        </div>

        <h2 class="mb-16">My Accounts</h2>
        <div class="card mb-16">
            <?php if (empty($accounts)) : ?>
                <p>You have no bank accounts yet.</p>
            <?php else : ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Account No</th>
                        <th style="padding: 8px 6px;">Type</th>
                        <th style="padding: 8px 6px;">Branch</th>
                        <th style="padding: 8px 6px;">Balance</th>
                        <th style="padding: 8px 6px;">Opened</th>
                        <th style="padding: 8px 6px;">Status</th>
                    </tr>
                    <?php foreach ($accounts as $account) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($account["account_id"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($account["account_type"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($account["branch_name"]); ?></td>
                            <td style="padding: 8px 6px;">Tk. <?php echo number_format($account["balance"], 2); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($account["opening_date"]); ?></td>
                            <td style="padding: 8px 6px;">
                                <?php
                                    $status = $account["status"];
                                    $status_class = "message-success";
                                    if ($status === "Suspended") $status_class = "message-warning";
                                    if ($status === "Closed")    $status_class = "message-danger";
                                ?>
                                <span class="message <?php echo $status_class; ?>"
                                      style="display:inline; padding: 2px 10px;">
                                    <?php echo htmlspecialchars($status); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

    </div>

</body>
</html>
