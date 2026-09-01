<?php
// loans.php - Customer loan application and loan list
// Customer applies for a loan (status starts as 'Pending').
// An employee later approves or rejects it from admin_loans.php.

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

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    $errors[] = "Could not connect to the database. Please try again later.";
} else {

    // ---------- Handle loan application ----------
    if ($_SERVER["REQUEST_METHOD"] === "POST") {

        $loan_type     = trim($_POST["loan_type"] ?? "");
        $amount        = $_POST["amount"] ?? "";
        $interest_rate = $_POST["interest_rate"] ?? "";
        $duration      = $_POST["duration"] ?? "";
        $reason        = trim($_POST["reason"] ?? "");

        if ($loan_type === "") {
            $errors[] = "Please choose a loan type.";
        }

        if (!is_numeric($amount) || $amount <= 0) {
            $errors[] = "Loan amount must be greater than 0.";
        }

        if (!is_numeric($interest_rate) || $interest_rate <= 0) {
            $errors[] = "Interest rate must be greater than 0.";
        }

        if (!ctype_digit($duration) || $duration <= 0) {
            $errors[] = "Duration must be a whole number of months greater than 0.";
        }

        if ($reason === "") {
            $errors[] = "Please write a reason for the loan.";
        }

        // The Loan table requires employee_id NOT NULL, so we assign a
        // Loan Officer (or any employee) as the reviewer when applying.
        $assigned_employee = null;

        if (empty($errors)) {
            $result = $conn->query(
                "SELECT employee_id FROM Employee
                 ORDER BY (employee_type = 'Loan Officer') DESC, employee_id
                 LIMIT 1"
            );
            if ($result && $result->num_rows === 1) {
                $assigned_employee = $result->fetch_assoc()["employee_id"];
            } else {
                $errors[] = "No employee is available to review loans right now.";
            }
        }

        if (empty($errors)) {

            // --- Generate a unique 6-digit loan_id ---
            $loan_id = null;

            for ($attempt = 0; $attempt < 20; $attempt++) {
                $candidate = random_int(100000, 999999);

                $check = $conn->prepare("SELECT loan_id FROM Loan WHERE loan_id = ?");
                $check->bind_param("i", $candidate);
                $check->execute();
                $exists = $check->get_result()->num_rows > 0;
                $check->close();

                if (!$exists) {
                    $loan_id = $candidate;
                    break;
                }
            }

            if ($loan_id === null) {
                $errors[] = "Could not generate a loan ID. Please try again.";
            } else {

                $amount        = (float) $amount;
                $interest_rate = (float) $interest_rate;
                $duration      = (int) $duration;
                $start_date    = date("Y-m-d");

                $stmt = $conn->prepare(
                    "INSERT INTO Loan
                     (loan_id, loan_type, amount, interest_rate, duration, start_date,
                      status, reason, customer_id, employee_id)
                     VALUES (?, ?, ?, ?, ?, ?, 'Pending', ?, ?, ?)"
                );
                $stmt->bind_param(
                    "isddissii",
                    $loan_id, $loan_type, $amount, $interest_rate, $duration,
                    $start_date, $reason, $customer_id, $assigned_employee
                );

                if ($stmt->execute()) {
                    $success_message = "Loan application " . $loan_id .
                        " submitted. It is Pending review by a loan officer.";
                } else {
                    $errors[] = "Could not submit the loan application. Please try again.";
                }

                $stmt->close();
            }
        }
    }

    // ---------- Load this customer's loans ----------
    $stmt = $conn->prepare(
        "SELECT l.*,
                (SELECT IFNULL(SUM(p.amount), 0)
                 FROM Loan_payment p WHERE p.loan_id = l.loan_id) AS paid_so_far
         FROM Loan l
         WHERE l.customer_id = ?
         ORDER BY l.start_date DESC"
    );
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
    <title>TakaKoi - My Loans</title>
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

        <h1 class="mb-16">My Loans</h1>

        <?php foreach ($errors as $error) : ?>
            <div class="message message-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>

        <?php if ($success_message !== "") : ?>
            <div class="message message-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <h2 class="mb-16">Apply for a Loan</h2>
        <div class="card mb-16">
            <form action="loans.php" method="POST">

                <div class="form-group">
                    <label for="loan_type">Loan Type</label>
                    <select id="loan_type" name="loan_type" required
                            style="width:100%; padding:10px 12px; border-radius:8px;
                                   border:1px solid rgba(48,27,7,0.25); font-size:14px;">
                        <option value="Personal">Personal</option>
                        <option value="Home">Home</option>
                        <option value="Car">Car</option>
                        <option value="Education">Education</option>
                        <option value="Business">Business</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="amount">Loan Amount (Tk.)</label>
                    <input type="text" id="amount" name="amount" required>
                </div>

                <div class="form-group">
                    <label for="interest_rate">Interest Rate (%)</label>
                    <input type="text" id="interest_rate" name="interest_rate" value="10" required>
                </div>

                <div class="form-group">
                    <label for="duration">Duration (months)</label>
                    <input type="text" id="duration" name="duration" value="12" required>
                </div>

                <div class="form-group">
                    <label for="reason">Reason</label>
                    <input type="text" id="reason" name="reason" maxlength="500" required>
                </div>

                <button type="submit" class="btn btn-primary">Apply for Loan</button>

            </form>
        </div>

        <h2 class="mb-16">My Loan Applications</h2>
        <div class="card mb-16">
            <?php if (empty($loans)) : ?>
                <p>No loans on record.</p>
            <?php else : ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Loan ID</th>
                        <th style="padding: 8px 6px;">Type</th>
                        <th style="padding: 8px 6px;">Amount</th>
                        <th style="padding: 8px 6px;">Rate</th>
                        <th style="padding: 8px 6px;">Months</th>
                        <th style="padding: 8px 6px;">Paid</th>
                        <th style="padding: 8px 6px;">Status</th>
                        <th style="padding: 8px 6px;">Action</th>
                    </tr>
                    <?php foreach ($loans as $loan) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($loan["loan_id"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($loan["loan_type"]); ?></td>
                            <td style="padding: 8px 6px;">Tk. <?php echo number_format($loan["amount"], 2); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($loan["interest_rate"]); ?>%</td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($loan["duration"]); ?></td>
                            <td style="padding: 8px 6px;">Tk. <?php echo number_format($loan["paid_so_far"], 2); ?></td>
                            <td style="padding: 8px 6px;">
                                <?php
                                    $status = $loan["status"];
                                    $status_class = "message-warning";
                                    if ($status === "Approved" || $status === "Active" || $status === "Paid") {
                                        $status_class = "message-success";
                                    }
                                    if ($status === "Rejected" || $status === "Defaulted") {
                                        $status_class = "message-danger";
                                    }
                                ?>
                                <span class="message <?php echo $status_class; ?>"
                                      style="display:inline; padding: 2px 10px;">
                                    <?php echo htmlspecialchars($status); ?>
                                </span>
                            </td>
                            <td style="padding: 8px 6px;">
                                <?php if ($loan["status"] === "Approved" || $loan["status"] === "Active") : ?>
                                    <a href="loan_payment.php?loan_id=<?php echo htmlspecialchars($loan["loan_id"]); ?>">
                                        Make Payment
                                    </a>
                                <?php else : ?>
                                    -
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
