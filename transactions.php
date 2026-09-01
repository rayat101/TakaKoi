<?php
// transactions.php - Deposits and withdrawals for a customer's own accounts
// Updates the Account balance and records a row in the Transaction table.

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
$history         = [];

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    $errors[] = "Could not connect to the database. Please try again later.";
} else {

    // ---------- Handle deposit / withdrawal ----------
    if ($_SERVER["REQUEST_METHOD"] === "POST") {

        $account_id       = $_POST["account_id"] ?? "";
        $transaction_type = $_POST["transaction_type"] ?? "";
        $amount           = $_POST["amount"] ?? "";
        $description      = trim($_POST["description"] ?? "");

        if ($account_id === "" || !ctype_digit($account_id)) {
            $errors[] = "Please choose an account.";
        }

        if ($transaction_type !== "Deposit" && $transaction_type !== "Withdrawal") {
            $errors[] = "Please choose Deposit or Withdrawal.";
        }

        if (!is_numeric($amount) || $amount <= 0) {
            $errors[] = "Amount must be a number greater than 0.";
        }

        if ($description === "") {
            $description = $transaction_type;   // description is NOT NULL in the schema
        }

        if (empty($errors)) {

            $amount = (float) $amount;

            // --- Make sure the account belongs to this customer and is Active ---
            $stmt = $conn->prepare(
                "SELECT balance, status FROM Account WHERE account_id = ? AND customer_id = ?"
            );
            $stmt->bind_param("ii", $account_id, $customer_id);
            $stmt->execute();
            $account = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$account) {
                $errors[] = "That account was not found.";
            } elseif ($account["status"] !== "Active") {
                $errors[] = "This account is " . $account["status"] .
                            ". Only Active accounts can be used.";
            } elseif ($transaction_type === "Withdrawal" && $account["balance"] < $amount) {
                $errors[] = "Not enough balance for this withdrawal.";
            }
        }

        if (empty($errors)) {

            // --- Generate a unique 7-digit transaction_id ---
            $transaction_id = null;

            for ($attempt = 0; $attempt < 20; $attempt++) {
                $candidate = random_int(1000000, 9999999);

                $check = $conn->prepare("SELECT transaction_id FROM Transaction WHERE transaction_id = ?");
                $check->bind_param("i", $candidate);
                $check->execute();
                $exists = $check->get_result()->num_rows > 0;
                $check->close();

                if (!$exists) {
                    $transaction_id = $candidate;
                    break;
                }
            }

            if ($transaction_id === null) {
                $errors[] = "Could not generate a transaction ID. Please try again.";
            } else {

                $t_date = date("Y-m-d");
                $t_time = date("H:i:s");

                // Balance update and transaction record must both succeed
                $conn->begin_transaction();

                try {
                    if ($transaction_type === "Deposit") {
                        $update = $conn->prepare(
                            "UPDATE Account SET balance = balance + ? WHERE account_id = ?"
                        );
                    } else {
                        $update = $conn->prepare(
                            "UPDATE Account SET balance = balance - ? WHERE account_id = ?"
                        );
                    }
                    $update->bind_param("di", $amount, $account_id);
                    $update->execute();
                    $update->close();

                    $insert = $conn->prepare(
                        "INSERT INTO Transaction
                         (transaction_id, transaction_type, amount, t_date, t_time, description, account_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );
                    $insert->bind_param(
                        "isdsssi",
                        $transaction_id, $transaction_type, $amount,
                        $t_date, $t_time, $description, $account_id
                    );
                    $insert->execute();
                    $insert->close();

                    $conn->commit();

                    $success_message = $transaction_type . " of Tk. " .
                        number_format($amount, 2) . " completed successfully.";

                } catch (Exception $e) {
                    $conn->rollback();
                    $errors[] = "Something went wrong. The transaction was cancelled.";
                }
            }
        }
    }

    // ---------- Load this customer's accounts ----------
    $stmt = $conn->prepare(
        "SELECT account_id, account_type, balance, status
         FROM Account
         WHERE customer_id = ?
         ORDER BY account_id"
    );
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $accounts[] = $row;
    }
    $stmt->close();

    // ---------- Load transaction history ----------
    $stmt = $conn->prepare(
        "SELECT t.* FROM Transaction t
         JOIN Account a ON t.account_id = a.account_id
         WHERE a.customer_id = ?
         ORDER BY t.t_date DESC, t.t_time DESC
         LIMIT 15"
    );
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
    }
    $stmt->close();

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TakaKoi - Transactions</title>
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
            <a href="transactions.php" class="nav-active">Transactions</a>
            <a href="transfer.php">Transfer</a>
            <a href="loans.php">Loans</a>
            <a href="cheque_book.php">Cheque Book</a>
            <a href="closure_request.php">Close Account</a>
            <a href="login.php">Logout</a>
        </div>
    </nav>

    <div style="max-width: 900px; margin: 0 auto; padding: 28px 20px;">

        <h1 class="mb-16">Deposit / Withdraw</h1>

        <?php foreach ($errors as $error) : ?>
            <div class="message message-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>

        <?php if ($success_message !== "") : ?>
            <div class="message message-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <div class="card mb-16">
            <?php if (empty($accounts)) : ?>
                <p>You have no bank accounts yet. <a href="accounts.php">Open one first</a>.</p>
            <?php else : ?>
                <form action="transactions.php" method="POST">

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
                        <label for="transaction_type">Transaction Type</label>
                        <select id="transaction_type" name="transaction_type" required
                                style="width:100%; padding:10px 12px; border-radius:8px;
                                       border:1px solid rgba(48,27,7,0.25); font-size:14px;">
                            <option value="Deposit">Deposit</option>
                            <option value="Withdrawal">Withdrawal</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="amount">Amount (Tk.)</label>
                        <input type="text" id="amount" name="amount" required>
                    </div>

                    <div class="form-group">
                        <label for="description">Description</label>
                        <input type="text" id="description" name="description" maxlength="200">
                    </div>

                    <button type="submit" class="btn btn-primary">Submit</button>

                </form>
            <?php endif; ?>
        </div>

        <h2 class="mb-16">Transaction History</h2>
        <div class="card mb-16">
            <?php if (empty($history)) : ?>
                <p>No transactions yet.</p>
            <?php else : ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Date</th>
                        <th style="padding: 8px 6px;">Time</th>
                        <th style="padding: 8px 6px;">Type</th>
                        <th style="padding: 8px 6px;">Amount</th>
                        <th style="padding: 8px 6px;">Account</th>
                        <th style="padding: 8px 6px;">Description</th>
                    </tr>
                    <?php foreach ($history as $t) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($t["t_date"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($t["t_time"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($t["transaction_type"]); ?></td>
                            <td style="padding: 8px 6px;">Tk. <?php echo number_format($t["amount"], 2); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($t["account_id"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($t["description"]); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

    </div>

</body>
</html>
