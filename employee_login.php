<?php
// employee_login.php - Login page for employees

session_start();

$error_message = "";

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

        $conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

        if ($conn->connect_error) {
            $error_message = "Could not connect to the database. Please try again later.";
        } else {

            $stmt = $conn->prepare(
                "SELECT employee_id, first_name, last_name, password, employee_type
                 FROM Employee
                 WHERE username = ?"
            );

            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {

                $employee = $result->fetch_assoc();

                if (password_verify($password, $employee["password"])) {

                    $_SESSION["employee_id"] = $employee["employee_id"];
                    $_SESSION["employee_name"] =
                        $employee["first_name"] . " " . $employee["last_name"];
                    $_SESSION["employee_type"] = $employee["employee_type"];

                    $stmt->close();
                    $conn->close();

                    header("Location: admin_dashboard.php");
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
    <title>TakaKoi - Employee Login</title>
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

                <h2 class="text-center mb-16">Employee Login</h2>

                <?php if ($error_message !== "") : ?>
                    <div class="message message-danger">
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <form action="employee_login.php" method="POST">

                    <div class="form-group">
                        <label for="username">Username</label>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            required
                        >
                    </div>

                    <button type="submit" class="btn btn-primary">
                        Login
                    </button>

                </form>

                <p class="small-text text-center mt-16">
                    Not an employee?
                    <a href="login.php">Customer Login</a>
                </p>

            </div>

        </div>
    </div>

</body>
</html>