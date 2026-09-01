<?php
// admin_customer_view.php - Full overview of one customer
// Reached from admin_customers.php as admin_customer_view.php?customer_id=...
// Read only, apart from one bulk action: suspend or reactivate every account
// belonging to this customer.

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
$customer        = null;
$phones          = [];
$accounts        = [];
$loans           = [];
$transactions    = [];
$closures        = [];
$cheque_books    = [];

// The customer can arrive from the link (GET) or the action form (POST)
$customer_id = $_POST["customer_id"] ?? $_GET["customer_id"] ?? "";

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    $errors[] = "Could not connect to the database. Please try again later.";
} elseif ($customer_id === "" || !ctype_digit($customer_id)) {
    $errors[] = "No customer was selected.";
} else {

    // =========================================================
    //  Bulk action: suspend or reactivate all accounts
    //  The Customer table has no status column, so "suspending a customer"
    //  is done by suspending every account they own. That is where
    //  transactions are actually blocked.
    // =========================================================
    if ($_SERVER["REQUEST_METHOD"] === "POST") {

        $action = $_POST["action"] ?? "";

        if ($action === "suspend_all") {

            // Closed accounts are left alone - they should stay closed
            $stmt = $conn->prepare(
                "UPDATE Account SET status = 'Suspended'
                 WHERE customer_id = ? AND status = 'Active'"
            );
            $stmt->bind_param("i", $customer_id);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();

            if ($changed > 0) {
                $success_message = $changed . " account(s) suspended.";
            } else {
                $errors[] = "There were no Active accounts to suspend.";
            }

        } elseif ($action === "activate_all") {

            $stmt = $conn->prepare(
                "UPDATE Account SET status = 'Active'
                 WHERE customer_id = ? AND status = 'Suspended'"
            );
            $stmt->bind_param("i", $customer_id);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();

            if ($changed > 0) {
                $success_message = $changed . " account(s) reactivated.";
            } else {
                $errors[] = "There were no Suspended accounts to reactivate.";
            }

        } else {
            $errors[] = "Unknown action.";
        }
    }

    // ---------- Customer details ----------
    $stmt = $conn->prepare(
        "SELECT customer_id, username, first_name, last_name, email,
                house_no, area, district
         FROM Customer WHERE customer_id = ?"
    );
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$customer) {
        $errors[] = "That customer was not found.";
    } else {

        // ---------- Phone numbers (a customer may have several) ----------
        $stmt = $conn->prepare("SELECT phone FROM Customer_phone WHERE customer_id = ?");
        $stmt->bind_param("i", $customer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $phones[] = $row["phone"];
        }
        $stmt->close();

        // ---------- Accounts ----------
        $stmt = $conn->prepare(
            "SELECT a.*, b.branch_name
             FROM Account a
             JOIN Branch b ON a.branch_id = b.branch_id
             WHERE a.customer_id = ?
             ORDER BY a.status, a.account_id"
        );
        $stmt->bind_param("i", $customer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $accounts[] = $row;
        }
        $stmt->close();

        // ---------- Loans with how much has been repaid ----------
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

        // ---------- Recent transactions across all their accounts ----------
        $stmt = $conn->prepare(
            "SELECT t.*
             FROM Transaction t
             JOIN Account a ON t.account_id = a.account_id
             WHERE a.customer_id = ?
             ORDER BY t.t_date DESC, t.t_time DESC
             LIMIT 15"
        );
        $stmt->bind_param("i", $customer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $transactions[] = $row;
        }
        $stmt->close();

        // ---------- Closure requests ----------
        $stmt = $conn->prepare(
            "SELECT * FROM Closure_request
             WHERE customer_id = ?
             ORDER BY request_date DESC"
        );
        $stmt->bind_param("i", $customer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $closures[] = $row;
        }
        $stmt->close();

        // ---------- Cheque books (reached through their accounts) ----------
        $stmt = $conn->prepare(
            "SELECT cb.*
             FROM Cheque_book cb
             JOIN Account a ON cb.account_id = a.account_id
             WHERE a.customer_id = ?
             ORDER BY cb.request_date DESC"
        );
        $stmt->bind_param("i", $customer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $cheque_books[] = $row;
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
    <title>TakaKoi - Customer Details</title>
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
            <a href="admin_customers.php" class="nav-active">Customers</a>
            <a href="admin_accounts.php">Accounts</a>
            <a href="admin_transactions.php">Transactions</a>
            <a href="admin_loans.php">Loans</a>
            <a href="admin_employees.php">Employees</a>
            <a href="fraud_monitoring.php">Fraud Monitoring</a>
            <a href="admin_branches.php">Branches</a>
            <a href="closure_requests.php">Closures</a>
            <a href="cheque_requests.php">Cheque Books</a>
            <a href="reports.php">Reports</a>
            <a href="employee_login.php">Logout</a>
        </div>
    </nav>

    <div style="max-width: 1000px; margin: 0 auto; padding: 28px 20px;">

        <h1 class="mb-16">Customer Details</h1>

        <p class="small-text mb-16">
            <a href="admin_customers.php">&larr; Back to Customers</a>
        </p>

        <?php foreach ($errors as $error) : ?>
            <div class="message message-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>

        <?php if ($success_message !== "") : ?>
            <div class="message message-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <?php if ($customer) : ?>

            <!-- ---------- Personal details ---------- -->
            <h2 class="mb-16">
                <?php echo htmlspecialchars($customer["first_name"] . " " . $customer["last_name"]); ?>
            </h2>
            <div class="card mb-16">
                <p><strong>Customer ID:</strong> <?php echo htmlspecialchars($customer["customer_id"]); ?></p>
                <p><strong>Username:</strong> <?php echo htmlspecialchars($customer["username"]); ?></p>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($customer["email"]); ?></p>
                <p><strong>Address:</strong>
                    <?php
                        echo htmlspecialchars(
                            $customer["house_no"] . ", " . $customer["area"] . ", " . $customer["district"]
                        );
                    ?>
                </p>
                <p><strong>Phone:</strong>
                    <?php echo empty($phones) ? "-" : htmlspecialchars(implode(", ", $phones)); ?>
                </p>
            </div>

            <!-- ---------- Bulk account action ---------- -->
            <h2 class="mb-16">Account Access</h2>
            <div class="card mb-16">
                <p class="small-text mb-16">
                    This bank stores status on the account, not the customer.
                    Suspending here suspends every Active account this customer owns,
                    which blocks all of their deposits, withdrawals and transfers.
                    Closed accounts are not affected.
                </p>
                <form action="admin_customer_view.php" method="POST" style="display:flex; gap:10px;">
                    <input type="hidden" name="customer_id"
                           value="<?php echo htmlspecialchars($customer["customer_id"]); ?>">
                    <button type="submit" name="action" value="suspend_all" class="btn btn-secondary"
                            style="width:auto; padding:10px 20px; margin-bottom:0;">
                        Suspend All Accounts
                    </button>
                    <button type="submit" name="action" value="activate_all" class="btn btn-primary"
                            style="width:auto; padding:10px 20px; margin-bottom:0;">
                        Reactivate All Accounts
                    </button>
                </form>
            </div>

            <!-- ---------- Accounts ---------- -->
            <h2 class="mb-16">Accounts</h2>
            <div class="card mb-16">
                <?php if (empty($accounts)) : ?>
                    <p>This customer has no accounts.</p>
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
                        <?php foreach ($accounts as $a) : ?>
                            <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($a["account_id"]); ?></td>
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($a["account_type"]); ?></td>
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($a["branch_name"]); ?></td>
                                <td style="padding: 8px 6px;">Tk. <?php echo number_format($a["balance"], 2); ?></td>
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($a["opening_date"]); ?></td>
                                <td style="padding: 8px 6px;">
                                    <?php
                                        $status = $a["status"];
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

            <!-- ---------- Loans ---------- -->
            <h2 class="mb-16">Loans</h2>
            <div class="card mb-16">
                <?php if (empty($loans)) : ?>
                    <p>This customer has no loans.</p>
                <?php else : ?>
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                            <th style="padding: 8px 6px;">Loan ID</th>
                            <th style="padding: 8px 6px;">Type</th>
                            <th style="padding: 8px 6px;">Amount</th>
                            <th style="padding: 8px 6px;">Paid</th>
                            <th style="padding: 8px 6px;">Status</th>
                            <th style="padding: 8px 6px;">Start Date</th>
                        </tr>
                        <?php foreach ($loans as $l) : ?>
                            <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($l["loan_id"]); ?></td>
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($l["loan_type"]); ?></td>
                                <td style="padding: 8px 6px;">Tk. <?php echo number_format($l["amount"], 2); ?></td>
                                <td style="padding: 8px 6px;">Tk. <?php echo number_format($l["paid_so_far"], 2); ?></td>
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($l["status"]); ?></td>
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($l["start_date"]); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>

            <!-- ---------- Recent transactions ---------- -->
            <h2 class="mb-16">Recent Transactions</h2>
            <div class="card mb-16">
                <?php if (empty($transactions)) : ?>
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
                        <?php foreach ($transactions as $t) : ?>
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

            <!-- ---------- Closure requests ---------- -->
            <h2 class="mb-16">Closure Requests</h2>
            <div class="card mb-16">
                <?php if (empty($closures)) : ?>
                    <p>No closure requests.</p>
                <?php else : ?>
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                            <th style="padding: 8px 6px;">Request ID</th>
                            <th style="padding: 8px 6px;">Account</th>
                            <th style="padding: 8px 6px;">Requested</th>
                            <th style="padding: 8px 6px;">Reason</th>
                            <th style="padding: 8px 6px;">Status</th>
                        </tr>
                        <?php foreach ($closures as $r) : ?>
                            <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($r["request_id"]); ?></td>
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($r["account_id"]); ?></td>
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($r["request_date"]); ?></td>
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($r["reason"]); ?></td>
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($r["status"]); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>

            <!-- ---------- Cheque books ---------- -->
            <h2 class="mb-16">Cheque Books</h2>
            <div class="card mb-16">
                <?php if (empty($cheque_books)) : ?>
                    <p>No cheque books.</p>
                <?php else : ?>
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                            <th style="padding: 8px 6px;">Book ID</th>
                            <th style="padding: 8px 6px;">Account</th>
                            <th style="padding: 8px 6px;">Leaves</th>
                            <th style="padding: 8px 6px;">Requested</th>
                            <th style="padding: 8px 6px;">Issued</th>
                            <th style="padding: 8px 6px;">Status</th>
                        </tr>
                        <?php foreach ($cheque_books as $b) : ?>
                            <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($b["book_id"]); ?></td>
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($b["account_id"]); ?></td>
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($b["total_leaves"]); ?></td>
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($b["request_date"]); ?></td>
                                <td style="padding: 8px 6px;">
                                    <?php echo $b["issue_date"] === null ? "-" : htmlspecialchars($b["issue_date"]); ?>
                                </td>
                                <td style="padding: 8px 6px;"><?php echo htmlspecialchars($b["status"]); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>

        <?php endif; ?>

    </div>

</body>
</html>
