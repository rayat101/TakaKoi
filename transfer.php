<?php
// transfer.php - Transfer money from a customer's account to another account
// Uses a database transaction so both balance updates and the Transaction row
// either all succeed or all fail together.

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
$transfers       = [];

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    $errors[] = "Could not connect to the database. Please try again later.";
} else {

    // ---------- Handle transfer ----------
    if ($_SERVER["REQUEST_METHOD"] === "POST") {

        $from_account = $_POST["from_account"] ?? "";
        $to_account   = trim($_POST["to_account"] ?? "");
        $amount       = $_POST["amount"] ?? "";
        $description  = trim($_POST["description"] ?? "");

        if ($from_account === "" || !ctype_digit($from_account)) {
            $errors[] = "Please choose the account to send from.";
        }

        if ($to_account === "" || !ctype_digit($to_account)) {
            $errors[] = "Please enter a valid receiver account number.";
        }

        if (!is_numeric($amount) || $amount <= 0) {
            $errors[] = "Amount must be a number greater than 0.";
        }

        if ($from_account === $to_account) {
            $errors[] = "You cannot transfer money to the same account.";
        }

        if ($description === "") {
            $description = "Transfer";   // description is NOT NULL in the schema
        }

        // --- Check the sender account ---
        if (empty($errors)) {

            $amount = (float) $amount;

            $stmt = $conn->prepare(
                "SELECT balance, status FROM Account WHERE account_id = ? AND customer_id = ?"
            );
            $stmt->bind_param("ii", $from_account, $customer_id);
            $stmt->execute();
            $sender = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$sender) {
                $errors[] = "Sender account not found.";
            } elseif ($sender["status"] !== "Active") {
                $errors[] = "Your account is " . $sender["status"] .
                            ". Only Active accounts can send money.";
            } elseif ($sender["balance"] < $amount) {
                $errors[] = "Not enough balance for this transfer.";
            }
        }

        // --- Check the receiver account ---
        if (empty($errors)) {

            $stmt = $conn->prepare("SELECT status FROM Account WHERE account_id = ?");
            $stmt->bind_param("i", $to_account);
            $stmt->execute();
            $receiver = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$receiver) {
                $errors[] = "Receiver account number does not exist.";
            } elseif ($receiver["status"] !== "Active") {
                $errors[] = "The receiver account is not Active.";
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

                $conn->begin_transaction();

                try {
                    // Take money out of the sender account
                    $take = $conn->prepare(
                        "UPDATE Account SET balance = balance - ? WHERE account_id = ?"
                    );
                    $take->bind_param("di", $amount, $from_account);
                    $take->execute();
                    $take->close();

                    // Put money into the receiver account
                    $give = $conn->prepare(
                        "UPDATE Account SET balance = balance + ? WHERE account_id = ?"
                    );
                    $give->bind_param("di", $amount, $to_account);
                    $give->execute();
                    $give->close();

                    // Record the transfer (sent_to_id is required for transfers)
                    $insert = $conn->prepare(
                        "INSERT INTO Transaction
                         (transaction_id, transaction_type, amount, t_date, t_time,
                          description, sent_to_id, account_id)
                         VALUES (?, 'Transfer', ?, ?, ?, ?, ?, ?)"
                    );
                    $insert->bind_param(
                        "idsssii",
                        $transaction_id, $amount, $t_date, $t_time,
                        $description, $to_account, $from_account
                    );
                    $insert->execute();
                    $insert->close();

                    $conn->commit();

                    $success_message = "Transferred Tk. " . number_format($amount, 2) .
                        " to account " . $to_account . " successfully.";

                } catch (Exception $e) {
                    $conn->rollback();
                    $errors[] = "Something went wrong. The transfer was cancelled.";
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

    // ---------- Load past transfers ----------
    $stmt = $conn->prepare(
        "SELECT t.* FROM Transaction t
         JOIN Account a ON t.account_id = a.account_id
         WHERE a.customer_id = ? AND t.transaction_type = 'Transfer'
         ORDER BY t.t_date DESC, t.t_time DESC
         LIMIT 10"
    );
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $transfers[] = $row;
    }
    $stmt->close();

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TakaKoi - Transfer</title>
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
            <a href="transactions.php">Transactions</a>
            <a href="transfer.php" class="nav-active">Transfer</a>
            <a href="loans.php">Loans</a>
            <a href="login.php">Logout</a>
        </div>
    </nav>

    <div style="max-width: 900px; margin: 0 auto; padding: 28px 20px;">

        <h1 class="mb-16">Transfer Money</h1>

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
                <form action="transfer.php" method="POST">

                    <div class="form-group">
                        <label for="from_account">From My Account</label>
                        <select id="from_account" name="from_account" required
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
                        <label for="to_account">Receiver Account Number</label>
                        <input type="text" id="to_account" name="to_account" maxlength="10" required>
                    </div>

                    <div class="form-group">
                        <label for="amount">Amount (Tk.)</label>
                        <input type="text" id="amount" name="amount" required>
                    </div>

                    <div class="form-group">
                        <label for="description">Description</label>
                        <input type="text" id="description" name="description" maxlength="200">
                    </div>

                    <button type="submit" class="btn btn-primary">Send Money</button>

                </form>
            <?php endif; ?>
        </div>

        <h2 class="mb-16">Recent Transfers</h2>
        <div class="card mb-16">
            <?php if (empty($transfers)) : ?>
                <p>No transfers yet.</p>
            <?php else : ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Date</th>
                        <th style="padding: 8px 6px;">From</th>
                        <th style="padding: 8px 6px;">To</th>
                        <th style="padding: 8px 6px;">Amount</th>
                        <th style="padding: 8px 6px;">Description</th>
                    </tr>
                    <?php foreach ($transfers as $t) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($t["t_date"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($t["account_id"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($t["sent_to_id"]); ?></td>
                            <td style="padding: 8px 6px;">Tk. <?php echo number_format($t["amount"], 2); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($t["description"]); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

    </div>

</body>
</html>
