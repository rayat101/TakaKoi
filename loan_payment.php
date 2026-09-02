<?php
// loan_payment.php - Feature 3: customer makes a payment towards a loan
// Rules:
//   - Payment cannot be more than the remaining amount (no overpaying)
//   - Partial payments are allowed and add up over time
//   - When the total paid reaches the loan amount, the loan becomes 'Paid'
//   - A loan that is already 'Paid' cannot accept any more payments

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
$loans           = [];
$payments        = [];

// Loan chosen from the link on loans.php (optional)
$selected_loan = $_GET["loan_id"] ?? "";

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    $errors[] = "Could not connect to the database. Please try again later.";
} else {

    // ---------- Handle a new payment ----------
    if ($_SERVER["REQUEST_METHOD"] === "POST") {

        $loan_id        = $_POST["loan_id"] ?? "";
        $amount         = $_POST["amount"] ?? "";
        $payment_method = $_POST["payment_method"] ?? "";

        $allowed_methods = ["Cash", "Bank Transfer", "Card", "Cheque"];

        if ($loan_id === "" || !ctype_digit($loan_id)) {
            $errors[] = "Please choose a loan.";
        }

        if (!is_numeric($amount) || $amount <= 0) {
            $errors[] = "Payment amount must be greater than 0.";
        }

        if (!in_array($payment_method, $allowed_methods)) {
            $errors[] = "Please choose a valid payment method.";
        }

        $loan      = null;
        $remaining = 0;

        // --- Load the loan with how much has already been paid ---
        // The subquery adds up every previous payment for this loan.
        if (empty($errors)) {

            $stmt = $conn->prepare(
                "SELECT l.loan_id, l.amount, l.status,
                        (SELECT IFNULL(SUM(p.amount), 0)
                         FROM Loan_payment p WHERE p.loan_id = l.loan_id) AS paid_so_far
                 FROM Loan l
                 WHERE l.loan_id = ? AND l.customer_id = ?"
            );
            $stmt->bind_param("ii", $loan_id, $customer_id);
            $stmt->execute();
            $loan = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$loan) {
                $errors[] = "That loan was not found.";
            } elseif ($loan["status"] === "Paid") {
                // Rule: a fully paid loan is closed for good
                $errors[] = "Loan " . $loan_id . " is already fully paid. No more payments are accepted.";
            } elseif ($loan["status"] !== "Approved" && $loan["status"] !== "Active") {
                $errors[] = "This loan is " . $loan["status"] . " and cannot accept payments.";
            } else {
                $remaining = (float) $loan["amount"] - (float) $loan["paid_so_far"];
            }
        }

        // --- Rule: cannot pay more than what is left ---
        if (empty($errors) && $amount > $remaining) {
            $errors[] = "You are trying to pay Tk. " . number_format((float) $amount, 2) .
                        " but only Tk. " . number_format($remaining, 2) .
                        " is remaining on this loan. Please enter Tk. " .
                        number_format($remaining, 2) . " or less.";
        }

        // ---------- Save the payment ----------
        if (empty($errors)) {

            $amount       = (float) $amount;
            $payment_date = date("Y-m-d");

            // How much will be paid in total once this payment goes in
            $new_total = (float) $loan["paid_so_far"] + $amount;

            // A transaction is used because the payment row and the loan status
            // must both be saved together.
            $conn->begin_transaction();

            try {
                $stmt = $conn->prepare(
                    "INSERT INTO Loan_payment (amount, payment_date, payment_method, loan_id)
                     VALUES (?, ?, ?, ?)"
                );
                $stmt->bind_param("dssi", $amount, $payment_date, $payment_method, $loan_id);
                $stmt->execute();
                $stmt->close();

                if ($new_total >= (float) $loan["amount"]) {

                    // The loan is now fully repaid
                    $stmt = $conn->prepare("UPDATE Loan SET status = 'Paid' WHERE loan_id = ?");
                    $stmt->bind_param("i", $loan_id);
                    $stmt->execute();
                    $stmt->close();

                    $success_message = "Payment of Tk. " . number_format($amount, 2) .
                        " recorded. This loan is now fully PAID.";

                } else {

                    // Still money left - a loan that was only Approved now becomes Active
                    $stmt = $conn->prepare(
                        "UPDATE Loan SET status = 'Active' WHERE loan_id = ? AND status = 'Approved'"
                    );
                    $stmt->bind_param("i", $loan_id);
                    $stmt->execute();
                    $stmt->close();

                    $left = (float) $loan["amount"] - $new_total;
                    $success_message = "Payment of Tk. " . number_format($amount, 2) .
                        " recorded. Tk. " . number_format($left, 2) . " is still remaining.";
                }

                $conn->commit();

            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = "Could not record the payment. Nothing was saved.";
            }
        }
    }

    // ---------- Load payable loans with their remaining amount ----------
    $stmt = $conn->prepare(
        "SELECT l.loan_id, l.loan_type, l.amount, l.status,
                (SELECT IFNULL(SUM(p.amount), 0)
                 FROM Loan_payment p WHERE p.loan_id = l.loan_id) AS paid_so_far,
                l.amount - (SELECT IFNULL(SUM(p.amount), 0)
                            FROM Loan_payment p WHERE p.loan_id = l.loan_id) AS remaining
         FROM Loan l
         WHERE l.customer_id = ? AND l.status IN ('Approved', 'Active')
         ORDER BY l.loan_id"
    );
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $loans[] = $row;
    }
    $stmt->close();

    // ---------- Payment history ----------
    $stmt = $conn->prepare(
        "SELECT p.*, l.loan_type, l.status AS loan_status
         FROM Loan_payment p
         JOIN Loan l ON p.loan_id = l.loan_id
         WHERE l.customer_id = ?
         ORDER BY p.payment_date DESC, p.payment_id DESC
         LIMIT 15"
    );
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $payments[] = $row;
    }
    $stmt->close();

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TakaKoi - Loan Payment</title>
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
            <a href="transfer.php">Transfer</a>
            <a href="loans.php" class="nav-active">Loans</a>
            <a href="cheque_book.php">Cheque Book</a>
            <a href="closure_request.php">Close Account</a>
            <a href="login.php">Logout</a>
        </div>
    </nav>

    <div style="max-width: 900px; margin: 0 auto; padding: 28px 20px;">

        <h1 class="mb-16">Make a Loan Payment</h1>

        <?php foreach ($errors as $error) : ?>
            <div class="message message-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>

        <?php if ($success_message !== "") : ?>
            <div class="message message-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <div class="card mb-16">
            <?php if (empty($loans)) : ?>
                <p>You have no loans that need payment. <a href="loans.php">View my loans</a>.</p>
            <?php else : ?>
                <form action="loan_payment.php" method="POST">

                    <div class="form-group">
                        <label for="loan_id">Loan</label>
                        <select id="loan_id" name="loan_id" required
                                style="width:100%; padding:10px 12px; border-radius:8px;
                                       border:1px solid rgba(48,27,7,0.25); font-size:14px;">
                            <?php foreach ($loans as $loan) : ?>
                                <option value="<?php echo htmlspecialchars($loan["loan_id"]); ?>"
                                    <?php if ((string) $loan["loan_id"] === (string) $selected_loan) echo "selected"; ?>>
                                    <?php
                                        echo htmlspecialchars(
                                            $loan["loan_id"] . " - " . $loan["loan_type"] .
                                            " - Remaining: Tk. " . number_format($loan["remaining"], 2)
                                        );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="amount">Payment Amount (Tk.)</label>
                        <input type="text" id="amount" name="amount" required>
                    </div>

                    <div class="form-group">
                        <label for="payment_method">Payment Method</label>
                        <select id="payment_method" name="payment_method" required
                                style="width:100%; padding:10px 12px; border-radius:8px;
                                       border:1px solid rgba(48,27,7,0.25); font-size:14px;">
                            <option value="Cash">Cash</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Card">Card</option>
                            <option value="Cheque">Cheque</option>
                        </select>
                    </div>

                    <p class="small-text mb-16">
                        You can pay part of the loan at a time. You cannot pay more than
                        the remaining amount. Once the full amount is paid, the loan is
                        marked as Paid and no further payments are accepted.
                    </p>

                    <button type="submit" class="btn btn-primary">Record Payment</button>

                </form>
            <?php endif; ?>
        </div>

        <h2 class="mb-16">Loan Balance</h2>
        <div class="card mb-16">
            <?php if (empty($loans)) : ?>
                <p>No loans waiting for payment.</p>
            <?php else : ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Loan ID</th>
                        <th style="padding: 8px 6px;">Type</th>
                        <th style="padding: 8px 6px;">Loan Amount</th>
                        <th style="padding: 8px 6px;">Paid</th>
                        <th style="padding: 8px 6px;">Remaining</th>
                        <th style="padding: 8px 6px;">Status</th>
                    </tr>
                    <?php foreach ($loans as $loan) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($loan["loan_id"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($loan["loan_type"]); ?></td>
                            <td style="padding: 8px 6px;">Tk. <?php echo number_format($loan["amount"], 2); ?></td>
                            <td style="padding: 8px 6px;">Tk. <?php echo number_format($loan["paid_so_far"], 2); ?></td>
                            <td style="padding: 8px 6px;">
                                <strong>Tk. <?php echo number_format($loan["remaining"], 2); ?></strong>
                            </td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($loan["status"]); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

        <h2 class="mb-16">Payment History</h2>
        <div class="card mb-16">
            <?php if (empty($payments)) : ?>
                <p>No payments yet.</p>
            <?php else : ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Payment ID</th>
                        <th style="padding: 8px 6px;">Loan ID</th>
                        <th style="padding: 8px 6px;">Type</th>
                        <th style="padding: 8px 6px;">Amount</th>
                        <th style="padding: 8px 6px;">Method</th>
                        <th style="padding: 8px 6px;">Date</th>
                        <th style="padding: 8px 6px;">Loan Status</th>
                    </tr>
                    <?php foreach ($payments as $p) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($p["payment_id"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($p["loan_id"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($p["loan_type"]); ?></td>
                            <td style="padding: 8px 6px;">Tk. <?php echo number_format($p["amount"], 2); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($p["payment_method"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($p["payment_date"]); ?></td>
                            <td style="padding: 8px 6px;">
                                <?php
                                    $ls = $p["loan_status"];
                                    $ls_class = "message-warning";
                                    if ($ls === "Paid")   $ls_class = "message-success";
                                    if ($ls === "Active") $ls_class = "message-success";
                                ?>
                                <span class="message <?php echo $ls_class; ?>"
                                      style="display:inline; padding: 2px 10px;">
                                    <?php echo htmlspecialchars($ls); ?>
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
