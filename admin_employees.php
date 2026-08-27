<?php
// admin_employees.php - Employee directory (read only)
// Shows all employees with their branch. Employees are inserted manually
// into the database, so there is no registration here.

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

$errors    = [];
$employees = [];
$by_type   = [];

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    $errors[] = "Could not connect to the database. Please try again later.";
} else {

    // ---------- All employees with their branch ----------
    $result = $conn->query(
        "SELECT e.employee_id, e.first_name, e.last_name, e.email,
                e.salary, e.employee_type, b.branch_name
         FROM Employee e
         JOIN Branch b ON e.branch_id = b.branch_id
         ORDER BY e.employee_type, e.employee_id"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
    }

    // ---------- Count of employees per type ----------
    $result = $conn->query(
        "SELECT employee_type, COUNT(*) AS total
         FROM Employee
         GROUP BY employee_type
         ORDER BY employee_type"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $by_type[] = $row;
        }
    }

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TakaKoi - Employees</title>
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
            <a href="admin_employees.php" class="nav-active">Employees</a>
            <a href="#">Fraud Monitoring</a>
            <a href="admin_branches.php">Branches</a>
            <a href="#">Reports</a>
            <a href="employee_login.php">Logout</a>
        </div>
    </nav>

    <div style="max-width: 1000px; margin: 0 auto; padding: 28px 20px;">

        <h1 class="mb-16">Employees</h1>

        <?php foreach ($errors as $error) : ?>
            <div class="message message-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>

        <h2 class="mb-16">Employees by Role</h2>
        <div style="display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 24px;">
            <?php if (empty($by_type)) : ?>
                <div class="card" style="flex: 1;">
                    <p>No data available.</p>
                </div>
            <?php else : ?>
                <?php foreach ($by_type as $row) : ?>
                    <div class="card" style="flex: 1; min-width: 150px;">
                        <p class="small-text"><?php echo htmlspecialchars($row["employee_type"]); ?></p>
                        <h2><?php echo htmlspecialchars($row["total"]); ?></h2>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <h2 class="mb-16">Employee List</h2>
        <div class="card mb-16">
            <?php if (empty($employees)) : ?>
                <p>No data available.</p>
            <?php else : ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Employee ID</th>
                        <th style="padding: 8px 6px;">Name</th>
                        <th style="padding: 8px 6px;">Role</th>
                        <th style="padding: 8px 6px;">Email</th>
                        <th style="padding: 8px 6px;">Branch</th>
                        <th style="padding: 8px 6px;">Salary</th>
                    </tr>
                    <?php foreach ($employees as $e) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($e["employee_id"]); ?></td>
                            <td style="padding: 8px 6px;">
                                <?php echo htmlspecialchars($e["first_name"] . " " . $e["last_name"]); ?>
                            </td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($e["employee_type"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($e["email"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($e["branch_name"]); ?></td>
                            <td style="padding: 8px 6px;">Tk. <?php echo number_format($e["salary"], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

    </div>

</body>
</html>
