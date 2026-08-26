<?php
// Login.php -- Login page for customers

session_start();

$error_message = "";

// --- Database connection  ---
$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "Banking_software";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($username === "" || $password === "") {
        $error_message = "Please enter both username and password.";
    } else {

        // Connect to the database
        $conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

        if ($conn->connect_error) {
            $error_message = "Could not connect to the database. Please try again later.";
        } else {

            // Looking up customer
            $stmt = $conn->prepare("SELECT customer_id, password FROM Customer WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $customer = $result->fetch_assoc();

                // Password verify
                if (password_verify($password, $customer["password"])) {
                    $_SESSION["customer_id"] = $customer["customer_id"];
                    header("Location: customer_dashboard.php");
                    exit();
                } else {
                    $error_message = "Incorrect username or password.";
                }
            } else {
                $error_message = "Incorrect username or password.";
            }

            $stmt->close();
            $conn->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TakaKoi - Customer Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

    <div class="page-wrapper">
        <div class="container">

            <div class="logo-wrapper">
                <img src="img/logo.png" alt="TakaKoi logo">
            </div>

            <div class="card">
                <h2 class="text-center mb-16">Customer Login</h2>

                <?php if ($error_message !== "") : ?>
                    <div class="message message-danger"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>

                <form action="login.php" method="POST">

                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" required>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required>
                    </div>

                    <button type="submit" class="btn btn-primary">Login</button>

                </form>

                <p class="small-text text-center mt-16">
                    New to TakaKoi?
                    <a href="create_account.php">Create a free account</a>
                </p>

                <p class="small-text text-center">
                    <a href="employee_login.php">Login as Employee</a>
                </p>
            </div>

        </div>
    </div>

</body>
</html>