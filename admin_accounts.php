<?php
// admin_accounts.php - Employee view of all bank accounts
// Employees can activate, suspend or close a customer's bank account.

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
$accounts        = [];

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    $errors[] = "Could not connect to the database. Please try again later.";
} else {

    // ---------- Handle status change ----------
    if ($_SERVER["REQUEST_METHOD"] === "POST") {

        $account_id = $_POST["account_id"] ?? "";
        $new_status = $_POST["new_status"] ?? "";

        $allowed_status = ["Active", "Suspended", "Closed"];

        if ($account_id === "" || !ctype_digit($account_id)) {
            $errors[] = "Invalid account.";
        }

        if (!in_array($new_status, $allowed_status)) {
            $errors[] = "Invalid status.";
        }

        if (empty($errors)) {

            if ($new_status === "Closed") {
                // The schema requires closed_date when status is 'Closed'
                $today = date("Y-m-d");
                $stmt = $conn->prepare(
                    "UPDATE Account SET status = 'Closed', closed_date = ? WHERE account_id = ?"
                );
                $stmt->bind_param("si", $today, $account_id);
            } else {
                // Re-opening an account clears the closed date
                $stmt = $conn->prepare(
                    "UPDATE Account SET status = ?, closed_date = NULL WHERE account_id = ?"
                );
                $stmt->bind_param("si", $new_status, $account_id);
            }

            if ($stmt->execute()) {
                $success_message = "Account " . $account_id . " is now " . $new_status . ".";
            } else {
                $errors[] = "Could not update the account status.";
            }

            $stmt->close();
        }
    }

    // ---------- Load all accounts with customer and branch info ----------
    $result = $conn->query(
        "SELECT a.*, c.first_name, c.last_name, b.branch_name
         FROM Account a
         JOIN Customer c ON a.customer_id = c.customer_id
         JOIN Branch b   ON a.branch_id = b.branch_id
         ORDER BY a.status, a.account_id"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $accounts[] = $row;
        }
    }

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TakaKoi - Manage Accounts</title>
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
            <a href="admin_accounts.php" class="nav-active">Accounts</a>
            <a href="admin_loans.php">Loans</a>
            <a href="admin_employees.php">Employees</a>
            <a href="admin_branches.php">Branches</a>
            <a href="login.php">Logout</a>
        </div>
    </nav>

    <div style="max-width: 1000px; margin: 0 auto; padding: 28px 20px;">

        <h1 class="mb-16">Manage Bank Accounts</h1>

        <?php foreach ($errors as $error) : ?>
            <div class="message message-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>

        <?php if ($success_message !== "") : ?>
            <div class="message message-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <div class="card mb-16">
            <?php if (empty($accounts)) : ?>
                <p>No accounts available.</p>
            <?php else : ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Account No</th>
                        <th style="padding: 8px 6px;">Customer</th>
                        <th style="padding: 8px 6px;">Type</th>
                        <th style="padding: 8px 6px;">Branch</th>
                        <th style="padding: 8px 6px;">Balance</th>
                        <th style="padding: 8px 6px;">Status</th>
                        <th style="padding: 8px 6px;">Change Status</th>
                    </tr>
                    <?php foreach ($accounts as $account) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($account["account_id"]); ?></td>
                            <td style="padding: 8px 6px;">
                                <?php echo htmlspecialchars($account["first_name"] . " " . $account["last_name"]); ?>
                            </td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($account["account_type"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($account["branch_name"]); ?></td>
                            <td style="padding: 8px 6px;">Tk. <?php echo number_format($account["balance"], 2); ?></td>
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
                            <td style="padding: 8px 6px;">
                                <form action="admin_accounts.php" method="POST" style="display:flex; gap:6px;">
                                    <input type="hidden" name="account_id"
                                           value="<?php echo htmlspecialchars($account["account_id"]); ?>">
                                    <select name="new_status"
                                            style="padding:6px 8px; border-radius:8px;
                                                   border:1px solid rgba(48,27,7,0.25); font-size:13px;">
                                        <option value="Active">Active</option>
                                        <option value="Suspended">Suspended</option>
                                        <option value="Closed">Closed</option>
                                    </select>
                                    <button type="submit" class="btn btn-secondary"
                                            style="width:auto; padding:6px 12px; margin-bottom:0; font-size:13px;">
                                        Save
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

    </div>

</body>
</html>
