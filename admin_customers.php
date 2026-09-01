<?php
// admin_customers.php - Employee view of all customers (read only)
// Supports a simple search box and a few sort options.
// Clicking a customer opens admin_customer_view.php for the full picture.

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
$customers = [];
$totals    = [];

// --- Search box and sort dropdown come in through the URL (GET) ---
$search = trim($_GET["search"] ?? "");
$sort   = $_GET["sort"] ?? "name";

// Only these sort choices are allowed. The ORDER BY clause is built from this
// whitelist, never from the raw value, because column names cannot be bound
// with a prepared statement placeholder.
$sort_options = [
    "name"     => "c.first_name, c.last_name",
    "id"       => "c.customer_id",
    "accounts" => "account_count DESC",
    "balance"  => "total_balance DESC",
];

if (!array_key_exists($sort, $sort_options)) {
    $sort = "name";
}

$order_by = $sort_options[$sort];

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    $errors[] = "Could not connect to the database. Please try again later.";
} else {

    // ---------- Small summary numbers ----------
    $result = $conn->query(
        "SELECT
            (SELECT COUNT(*) FROM Customer) AS total_customers,
            (SELECT COUNT(*) FROM Account)  AS total_accounts,
            (SELECT IFNULL(SUM(balance), 0) FROM Account) AS total_money,
            (SELECT COUNT(*) FROM Customer c
             WHERE NOT EXISTS (SELECT 1 FROM Account a WHERE a.customer_id = c.customer_id)
            ) AS without_accounts"
    );
    if ($result) {
        $totals = $result->fetch_assoc();
    }

    // ---------- Customer list ----------
    // Subqueries give each customer their account count, money held and phone.
    $sql =
        "SELECT c.customer_id, c.first_name, c.last_name, c.username, c.email,
                c.area, c.district,
                (SELECT COUNT(*) FROM Account a WHERE a.customer_id = c.customer_id)
                    AS account_count,
                (SELECT COUNT(*) FROM Account a
                 WHERE a.customer_id = c.customer_id AND a.status = 'Active')
                    AS active_count,
                (SELECT IFNULL(SUM(a.balance), 0) FROM Account a WHERE a.customer_id = c.customer_id)
                    AS total_balance,
                (SELECT p.phone FROM Customer_phone p
                 WHERE p.customer_id = c.customer_id LIMIT 1)
                    AS phone
         FROM Customer c";

    if ($search !== "") {
        // Search across name, username, email and ID
        $sql .= " WHERE c.first_name LIKE ? OR c.last_name LIKE ?
                     OR c.username LIKE ?   OR c.email LIKE ?
                     OR c.customer_id LIKE ?";
    }

    $sql .= " ORDER BY " . $order_by;

    if ($search !== "") {
        $stmt = $conn->prepare($sql);
        $like = "%" . $search . "%";
        $stmt->bind_param("sssss", $like, $like, $like, $like, $like);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $customers[] = $row;
        }
        $stmt->close();
    } else {
        $result = $conn->query($sql);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $customers[] = $row;
            }
        }
    }

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TakaKoi - Customers</title>
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

    <div style="max-width: 1100px; margin: 0 auto; padding: 28px 20px;">

        <h1 class="mb-16">Customers</h1>

        <?php foreach ($errors as $error) : ?>
            <div class="message message-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endforeach; ?>

        <div style="display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 24px;">
            <div class="card" style="flex: 1; min-width: 150px;">
                <p class="small-text">Total Customers</p>
                <h2><?php echo $totals["total_customers"] ?? 0; ?></h2>
            </div>
            <div class="card" style="flex: 1; min-width: 150px;">
                <p class="small-text">Total Accounts</p>
                <h2><?php echo $totals["total_accounts"] ?? 0; ?></h2>
            </div>
            <div class="card" style="flex: 1; min-width: 150px;">
                <p class="small-text">Money Held</p>
                <h2>Tk. <?php echo number_format($totals["total_money"] ?? 0, 2); ?></h2>
            </div>
            <div class="card" style="flex: 1; min-width: 150px;">
                <p class="small-text">No Accounts Yet</p>
                <h2><?php echo $totals["without_accounts"] ?? 0; ?></h2>
            </div>
        </div>

        <h2 class="mb-16">Search &amp; Sort</h2>
        <div class="card mb-16">
            <form action="admin_customers.php" method="GET"
                  style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">

                <div class="form-group" style="margin-bottom:0; flex:1; min-width:200px;">
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search"
                           placeholder="Name, username, email or ID"
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label for="sort">Sort By</label>
                    <select id="sort" name="sort"
                            style="padding:10px 12px; border-radius:8px;
                                   border:1px solid rgba(48,27,7,0.25); font-size:14px;">
                        <option value="name"     <?php if ($sort === "name")     echo "selected"; ?>>Name</option>
                        <option value="id"       <?php if ($sort === "id")       echo "selected"; ?>>Customer ID</option>
                        <option value="accounts" <?php if ($sort === "accounts") echo "selected"; ?>>Most Accounts</option>
                        <option value="balance"  <?php if ($sort === "balance")  echo "selected"; ?>>Highest Balance</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary"
                        style="width:auto; padding:10px 20px; margin-bottom:0;">Apply</button>
                <a href="admin_customers.php" class="btn btn-outline"
                   style="width:auto; padding:10px 20px; margin-bottom:0;">Clear</a>
            </form>
        </div>

        <h2 class="mb-16">
            Customer List
            <?php if ($search !== "") : ?>
                <span class="small-text">
                    (<?php echo count($customers); ?> result(s) for
                    "<?php echo htmlspecialchars($search); ?>")
                </span>
            <?php endif; ?>
        </h2>
        <div class="card mb-16">
            <?php if (empty($customers)) : ?>
                <p>No customers found.</p>
            <?php else : ?>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="text-align: left; border-bottom: 1px solid rgba(48,27,7,0.15);">
                        <th style="padding: 8px 6px;">Customer ID</th>
                        <th style="padding: 8px 6px;">Name</th>
                        <th style="padding: 8px 6px;">Username</th>
                        <th style="padding: 8px 6px;">Phone</th>
                        <th style="padding: 8px 6px;">District</th>
                        <th style="padding: 8px 6px;">Accounts</th>
                        <th style="padding: 8px 6px;">Active</th>
                        <th style="padding: 8px 6px;">Total Balance</th>
                        <th style="padding: 8px 6px;">Details</th>
                    </tr>
                    <?php foreach ($customers as $c) : ?>
                        <tr style="border-bottom: 1px solid rgba(48,27,7,0.08);">
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($c["customer_id"]); ?></td>
                            <td style="padding: 8px 6px;">
                                <?php echo htmlspecialchars($c["first_name"] . " " . $c["last_name"]); ?>
                            </td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($c["username"]); ?></td>
                            <td style="padding: 8px 6px;">
                                <?php echo $c["phone"] === null ? "-" : htmlspecialchars($c["phone"]); ?>
                            </td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($c["district"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($c["account_count"]); ?></td>
                            <td style="padding: 8px 6px;"><?php echo htmlspecialchars($c["active_count"]); ?></td>
                            <td style="padding: 8px 6px;">Tk. <?php echo number_format($c["total_balance"], 2); ?></td>
                            <td style="padding: 8px 6px;">
                                <a href="admin_customer_view.php?customer_id=<?php echo htmlspecialchars($c["customer_id"]); ?>">
                                    View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

    </div>

</body>
</html>
