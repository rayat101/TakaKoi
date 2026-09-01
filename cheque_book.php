<?php
// cheque_book.php - Feature 8: customer side of Cheque Book Management
// The customer requests a cheque book. The individual cheques are only created
// when an employee issues the book from cheque_requests.php.

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
$books           = [];
$cheques         = [];

// If the customer clicked "View Cheques" on one of their books
$view_book = $_GET["book_id"] ?? "";

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    $errors[] = "Could not connect to the database. Please try again later.";
} else {

    // ---------- Handle a new cheque book request ----------
    if ($_SERVER["REQUEST_METHOD"] === "POST") {

        $account_id   = $_POST["account_id"] ?? "";
        $total_leaves = $_POST["total_leaves"] ?? "";

        $allowed_leaves = ["10", "25", "50"];

        if ($account_id === "" || !ctype_digit($account_id)) {
            $errors[] = "Please choose an account.";
        }

        if (!in_array($total_leaves, $allowed_leaves)) {
            $errors[] = "Please choose a valid number of leaves.";
        }

        // --- Rule 1: the account must belong to this customer and be Active ---
        if (empty($errors)) {

            $stmt = $conn->prepare(
                "SELECT status FROM Account WHERE account_id = ? AND customer_id = ?"
            );
            $stmt->bind_param("ii", $account_id, $customer_id);
            $stmt->execute();
            $account = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$account) {
                $errors[] = "That account was not found.";
            } elseif ($account["status"] !== "Active") {
                $errors[] = "Only Active accounts can request a cheque book.";
            }
        }

        // --- Rule 2: one outstanding request per account at a time ---
        if (empty($errors)) {

            $stmt = $conn->prepare(
                "SELECT book_id FROM Cheque_book
                 WHERE account_id = ? AND status = 'Requested'"
            );
            $stmt->bind_param("i", $account_id);
            $stmt->execute();
            $already = $stmt->get_result()->num_rows > 0;
            $stmt->close();

            if ($already) {
                $errors[] = "That account already has a cheque book request waiting.";
            }
        }

        if (empty($errors)) {

            $today  = date("Y-m-d");
            $leaves = (int) $total_leaves;

            $stmt = $conn->prepare(
                "INSERT INTO Cheque_book (total_leaves, request_date, status, account_id)
                 VALUES (?, ?, 'Requested', ?)"
            );
            $stmt->bind_param("isi", $leaves, $today, $account_id);

            if ($stmt->execute()) {
                $success_message = "Cheque book requested for account " . $account_id .
                    ". An employee will issue it shortly.";
            } else {
                $errors[] = "Could not submit the request. Please try again.";
            }
            $stmt->close();
        }
    }

    // ---------- Accounts eligible for a cheque book ----------
    $stmt = $conn->prepare(
        "SELECT account_id, account_type, status
         FROM Account
         WHERE customer_id = ? AND status = 'Active'
         ORDER BY account_id"
    );
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $accounts[] = $row;
    }
    $stmt->close();

    // ---------- This customer's cheque books ----------
    // The join to Account is what limits the list to books this customer owns.
    $stmt = $conn->prepare(
        "SELECT cb.*, a.account_type,
                (SELECT COUNT(*) FROM Cheque ch
                 WHERE ch.book_id = cb.book_id AND ch.status = 'Unused') AS unused_leaves
         FROM Cheque_book cb
         JOIN Account a ON cb.account_id = a.account_id
         WHERE a.customer_id = ?
         ORDER BY cb.request_date DESC, cb.book_id DESC"
    );
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $books[] = $row;
    }
    $stmt->close();

    // ---------- Cheques inside one book (only if it belongs to this customer) ----------
    if ($view_book !== "" && ctype_digit($view_book)) {

        $stmt = $conn->prepare(
            "SELECT ch.*
             FROM Cheque ch
             JOIN Cheque_book cb ON ch.book_id = cb.book_id
             JOIN Account a      ON cb.account_id = a.account_id
             WHERE ch.book_id = ? AND a.customer_id = ?
             ORDER BY ch.cheque_no"
        );
        $stmt->bind_param("ii", $view_book, $customer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $cheques[] = $row;
        }
        $stmt->close();

        if (empty($cheques)) {
            $errors[] = "No cheques found for that book.";
        }
    }

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TakaKoi - Cheque Book</title>
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
            <a href="loans.php">Loans</a>
            <a href="cheque_book.php" class="nav-active">Cheque Book</a>
            <a href="closure_request.php">Close Account</a>
            <a href="login.php">Logout</a>
        </div>
    </nav>

    <div style="max-width: 900px; margin: 0 auto; padding: 28px 20px;">

        <h1 class="mb-16">Cheque Books</h1>

        <?php foreach ($errors as $error) : ?>
            <div class="message message-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>

        <?php if ($success_message !== "") : ?>
            <div class="message message-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <h2 class="mb-16">Request a Cheque Book</h2>
        <div class="card mb-16">
            <?php if (empty($accounts)) : ?>
                <p>You need an Active account before you can request a cheque book.</p>
            <?php else : ?>
                <form action="cheque_book.php" method="POST">

                    <div class="form-group">
                        <label for="account_id">Account</label>
                        <select id="account_id" name="account_id" required
                                style="width:100%; padding:10px 12px; border-radius:8px;
                                       border:1px solid rgba(48,27,7,0.25); font-size:14px;">
                            <?php foreach ($accounts as $account) : ?>
                                <option value="<?php echo htmlspecialchars($account["account_id"]); ?>">
                                    <?php
                                        echo htmlspecialchars(
                                            $account["account_id"] . " (" . $account["account_type"] . ")"
                                        );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="total_leaves">Number of Leaves</label>
                        <select id="total_leaves" name="total_leaves" required
                                style="width:100%; padding:10px 12px; border-radius:8px;
                                       border:1px solid rgba(48,27,7,0.25); font-size:14px;">
                            <option value="10">10</option>
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary">Request Cheque Book</button>

                </form>
            <?php endif; ?>
        </div>

        <h2 class="mb-16">My Cheque Books</h2>
        <div class="card mb-16">
            <?php if (empty($books)) : ?>
                <p>You have not requested any cheque books yet.</p>
            <?php else : ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Book ID</th>
                        <th style="padding: 8px 6px;">Account</th>
                        <th style="padding: 8px 6px;">Leaves</th>
                        <th style="padding: 8px 6px;">Unused</th>
                        <th style="padding: 8px 6px;">Requested</th>
                        <th style="padding: 8px 6px;">Issued</th>
                        <th style="padding: 8px 6px;">Status</th>
                        <th style="padding: 8px 6px;">Cheques</th>
                    </tr>
                    <?php foreach ($books as $b) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($b["book_id"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($b["account_id"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($b["total_leaves"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($b["unused_leaves"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($b["request_date"]); ?></td>
                            <td style="padding: 8px 6px;">
                                <?php echo $b["issue_date"] === null ? "-" : htmlspecialchars($b["issue_date"]); ?>
                            </td>
                            <td style="padding: 8px 6px;">
                                <?php
                                    $status = $b["status"];
                                    $status_class = "message-warning";
                                    if ($status === "Issued")    $status_class = "message-success";
                                    if ($status === "Rejected")  $status_class = "message-danger";
                                ?>
                                <span class="message <?php echo $status_class; ?>"
                                      style="display:inline; padding: 2px 10px;">
                                    <?php echo htmlspecialchars($status); ?>
                                </span>
                            </td>
                            <td style="padding: 8px 6px;">
                                <?php if ($b["status"] === "Issued" || $b["status"] === "Exhausted") : ?>
                                    <a href="cheque_book.php?book_id=<?php echo htmlspecialchars($b["book_id"]); ?>">
                                        View
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

        <?php if (!empty($cheques)) : ?>
            <h2 class="mb-16">Cheques in Book <?php echo htmlspecialchars($view_book); ?></h2>
            <div class="card mb-16">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Cheque No</th>
                        <th style="padding: 8px 6px;">Status</th>
                        <th style="padding: 8px 6px;">Amount</th>
                        <th style="padding: 8px 6px;">Issued To</th>
                        <th style="padding: 8px 6px;">Cheque Date</th>
                    </tr>
                    <?php foreach ($cheques as $c) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($c["cheque_no"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($c["status"]); ?></td>
                            <td style="padding: 8px 6px;">
                                <?php echo $c["amount"] === null ? "-" : "Tk. " . number_format($c["amount"], 2); ?>
                            </td>
                            <td style="padding: 8px 6px;">
                                <?php echo $c["issued_to"] === null ? "-" : htmlspecialchars($c["issued_to"]); ?>
                            </td>
                            <td style="padding: 8px 6px;">
                                <?php echo $c["cheque_date"] === null ? "-" : htmlspecialchars($c["cheque_date"]); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>

    </div>

</body>
</html>
