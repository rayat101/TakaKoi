<?php
// cheque_requests.php - Feature 8: employee side of Cheque Book Management
// Employees issue or reject cheque book requests.
// Issuing a book also creates its individual Cheque rows, inside one transaction.

session_start();

if (!isset($_SESSION["employee_id"])) {
    header("Location: employee_login.php");
    exit();
}

$employee_id = $_SESSION["employee_id"];   // taken from session, never from the form

// --- Database connection ---
$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "Banking_software";

$errors          = [];
$success_message = "";
$requested       = [];
$processed       = [];
$cheques         = [];

// If the employee clicked "View Cheques"
$view_book = $_GET["book_id"] ?? "";

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    $errors[] = "Could not connect to the database. Please try again later.";
} else {

    // ---------- Handle issue / reject ----------
    if ($_SERVER["REQUEST_METHOD"] === "POST") {

        $book_id  = $_POST["book_id"] ?? "";
        $decision = $_POST["decision"] ?? "";

        if ($book_id === "" || !ctype_digit($book_id)) {
            $errors[] = "Invalid cheque book.";
        }

        if ($decision !== "Issued" && $decision !== "Rejected") {
            $errors[] = "Invalid decision.";
        }

        // --- Make sure the book is still waiting to be processed ---
        $book = null;
        if (empty($errors)) {

            $stmt = $conn->prepare(
                "SELECT book_id, total_leaves, status FROM Cheque_book WHERE book_id = ?"
            );
            $stmt->bind_param("i", $book_id);
            $stmt->execute();
            $book = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$book) {
                $errors[] = "That cheque book was not found.";
            } elseif ($book["status"] !== "Requested") {
                $errors[] = "That book has already been " . strtolower($book["status"]) . ".";
            }
        }

        // ---------- Reject: just update the status, no cheques created ----------
        if (empty($errors) && $decision === "Rejected") {

            $stmt = $conn->prepare(
                "UPDATE Cheque_book SET status = 'Rejected', issued_by = ?
                 WHERE book_id = ? AND status = 'Requested'"
            );
            $stmt->bind_param("ii", $employee_id, $book_id);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                $success_message = "Cheque book " . $book_id . " was rejected.";
            } else {
                $errors[] = "That book could not be updated.";
            }
            $stmt->close();
        }

        // ---------- Issue: update the book AND create every cheque leaf ----------
        if (empty($errors) && $decision === "Issued") {

            $today  = date("Y-m-d");
            $leaves = (int) $book["total_leaves"];

            // A transaction is essential here. Without it a crash halfway through
            // the loop would leave an "Issued" book holding only some of its cheques.
            $conn->begin_transaction();

            try {
                $stmt = $conn->prepare(
                    "UPDATE Cheque_book
                     SET status = 'Issued', issue_date = ?, issued_by = ?
                     WHERE book_id = ? AND status = 'Requested'"
                );
                $stmt->bind_param("sii", $today, $employee_id, $book_id);
                $stmt->execute();
                $changed = $stmt->affected_rows;
                $stmt->close();

                if ($changed === 0) {
                    throw new Exception("Book was already processed.");
                }

                // Cheque numbers run 1..total_leaves within this book.
                // (book_id, cheque_no) is the composite primary key.
                $stmt = $conn->prepare(
                    "INSERT INTO Cheque (book_id, cheque_no, status) VALUES (?, ?, 'Unused')"
                );
                for ($no = 1; $no <= $leaves; $no++) {
                    $stmt->bind_param("ii", $book_id, $no);
                    $stmt->execute();
                }
                $stmt->close();

                $conn->commit();

                $success_message = "Cheque book " . $book_id . " issued with " .
                    $leaves . " cheques.";

            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = "Could not issue the cheque book. Nothing was changed.";
            }
        }
    }

    // ---------- Books waiting to be processed ----------
    $result = $conn->query(
        "SELECT cb.*, a.account_type, c.first_name, c.last_name
         FROM Cheque_book cb
         JOIN Account a  ON cb.account_id = a.account_id
         JOIN Customer c ON a.customer_id = c.customer_id
         WHERE cb.status = 'Requested'
         ORDER BY cb.request_date"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $requested[] = $row;
        }
    }

    // ---------- Books already issued / rejected / exhausted ----------
    $result = $conn->query(
        "SELECT cb.*, a.account_type, c.first_name, c.last_name,
                e.first_name AS emp_first, e.last_name AS emp_last,
                (SELECT COUNT(*) FROM Cheque ch WHERE ch.book_id = cb.book_id) AS cheque_count
         FROM Cheque_book cb
         JOIN Account a       ON cb.account_id = a.account_id
         JOIN Customer c      ON a.customer_id = c.customer_id
         LEFT JOIN Employee e ON cb.issued_by = e.employee_id
         WHERE cb.status <> 'Requested'
         ORDER BY cb.issue_date DESC, cb.book_id DESC
         LIMIT 20"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $processed[] = $row;
        }
    }

    // ---------- Cheques inside one book ----------
    if ($view_book !== "" && ctype_digit($view_book)) {

        $stmt = $conn->prepare(
            "SELECT * FROM Cheque WHERE book_id = ? ORDER BY cheque_no"
        );
        $stmt->bind_param("i", $view_book);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $cheques[] = $row;
        }
        $stmt->close();
    }

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TakaKoi - Cheque Book Requests</title>
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
            <a href="fraud_monitoring.php">Fraud Monitoring</a>
            <a href="admin_branches.php">Branches</a>
            <a href="closure_requests.php">Closures</a>
            <a href="cheque_requests.php" class="nav-active">Cheque Books</a>
            <a href="reports.php">Reports</a>
            <a href="employee_login.php">Logout</a>
        </div>
    </nav>

    <div style="max-width: 1100px; margin: 0 auto; padding: 28px 20px;">

        <h1 class="mb-16">Cheque Book Requests</h1>

        <?php foreach ($errors as $error) : ?>
            <div class="message message-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>

        <?php if ($success_message !== "") : ?>
            <div class="message message-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <h2 class="mb-16">Pending Requests</h2>
        <div class="card mb-16">
            <?php if (empty($requested)) : ?>
                <p>No cheque book requests waiting.</p>
            <?php else : ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Book ID</th>
                        <th style="padding: 8px 6px;">Customer</th>
                        <th style="padding: 8px 6px;">Account</th>
                        <th style="padding: 8px 6px;">Type</th>
                        <th style="padding: 8px 6px;">Leaves</th>
                        <th style="padding: 8px 6px;">Requested</th>
                        <th style="padding: 8px 6px;">Decision</th>
                    </tr>
                    <?php foreach ($requested as $b) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($b["book_id"]); ?></td>
                            <td style="padding: 8px 6px;">
                                <?php echo htmlspecialchars($b["first_name"] . " " . $b["last_name"]); ?>
                            </td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($b["account_id"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($b["account_type"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($b["total_leaves"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($b["request_date"]); ?></td>
                            <td style="padding: 8px 6px;">
                                <form action="cheque_requests.php" method="POST" style="display:flex; gap:6px;">
                                    <input type="hidden" name="book_id"
                                           value="<?php echo htmlspecialchars($b["book_id"]); ?>">
                                    <button type="submit" name="decision" value="Issued"
                                            class="btn btn-primary"
                                            style="width:auto; padding:6px 12px; margin-bottom:0; font-size:13px;">
                                        Issue
                                    </button>
                                    <button type="submit" name="decision" value="Rejected"
                                            class="btn btn-secondary"
                                            style="width:auto; padding:6px 12px; margin-bottom:0; font-size:13px;">
                                        Reject
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

        <h2 class="mb-16">Processed Cheque Books</h2>
        <div class="card mb-16">
            <?php if (empty($processed)) : ?>
                <p>No processed cheque books yet.</p>
            <?php else : ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Book ID</th>
                        <th style="padding: 8px 6px;">Customer</th>
                        <th style="padding: 8px 6px;">Account</th>
                        <th style="padding: 8px 6px;">Leaves</th>
                        <th style="padding: 8px 6px;">Status</th>
                        <th style="padding: 8px 6px;">Issued</th>
                        <th style="padding: 8px 6px;">Issued By</th>
                        <th style="padding: 8px 6px;">Cheques</th>
                    </tr>
                    <?php foreach ($processed as $b) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($b["book_id"]); ?></td>
                            <td style="padding: 8px 6px;">
                                <?php echo htmlspecialchars($b["first_name"] . " " . $b["last_name"]); ?>
                            </td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($b["account_id"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($b["total_leaves"]); ?></td>
                            <td style="padding: 8px 6px;">
                                <?php
                                    $status = $b["status"];
                                    $status_class = "message-warning";
                                    if ($status === "Issued")   $status_class = "message-success";
                                    if ($status === "Rejected") $status_class = "message-danger";
                                ?>
                                <span class="message <?php echo $status_class; ?>"
                                      style="display:inline; padding: 2px 10px;">
                                    <?php echo htmlspecialchars($status); ?>
                                </span>
                            </td>
                            <td style="padding: 8px 6px;">
                                <?php echo $b["issue_date"] === null ? "-" : htmlspecialchars($b["issue_date"]); ?>
                            </td>
                            <td style="padding: 8px 6px;">
                                <?php echo htmlspecialchars(($b["emp_first"] ?? "") . " " . ($b["emp_last"] ?? "")); ?>
                            </td>
                            <td style="padding: 8px 6px;">
                                <?php if ((int) $b["cheque_count"] > 0) : ?>
                                    <a href="cheque_requests.php?book_id=<?php echo htmlspecialchars($b["book_id"]); ?>">
                                        View (<?php echo htmlspecialchars($b["cheque_count"]); ?>)
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
                        <th style="padding: 8px 6px;">Transaction ID</th>
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
                            <td style="padding: 8px 6px;">
                                <?php echo $c["transaction_id"] === null ? "-" : htmlspecialchars($c["transaction_id"]); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>

    </div>

</body>
</html>
