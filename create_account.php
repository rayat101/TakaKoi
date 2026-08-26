<?php
// create_account.php - customer registration

session_start();

// --- Database connection ---
$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "Banking_software";

$errors = [];
$username_error = "";
$email_error = "";
$success = false;


$first_name = $last_name = $username = $email = $house_no = $area = $district = $mobile = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $first_name = trim($_POST["first_name"] ?? "");
    $last_name  = trim($_POST["last_name"] ?? "");
    $username   = trim($_POST["username"] ?? "");
    $password   = $_POST["password"] ?? "";
    $email      = trim($_POST["email"] ?? "");
    $house_no   = trim($_POST["house_no"] ?? "");
    $area       = trim($_POST["area"] ?? "");
    $district   = trim($_POST["district"] ?? "");
    $mobile     = trim($_POST["mobile"] ?? "");

    // --- Basic check ---
    if ($first_name === "" || $last_name === "" || $username === "" || $password === "" ||
        $email === "" || $house_no === "" || $area === "" || $district === "" || $mobile === "") {
        $errors[] = "Please fill in all fields.";
    }

    // --- Mobile number constraint of being 11 digits ---
    if ($mobile !== "" && !preg_match('/^[0-9]{11}$/', $mobile)) {
        $errors[] = "Mobile number must be exactly 11 digits.";
    }

    // Database hit
    if (empty($errors)) {

        $conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

        if ($conn->connect_error) {
            $errors[] = "Could not connect to the database. Please try again later.";
        } else {

            // --- Customer username check ---
            $stmt = $conn->prepare("SELECT customer_id FROM Customer WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $username_error = "This username is already taken.";
            }
            $stmt->close();

            // --- Employee username check ---
            if ($username_error === "") {
                $stmt = $conn->prepare("SELECT employee_id FROM Employee WHERE username = ?");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $username_error = "This username is already taken.";
                }
                $stmt->close();
            }

            // --- Check email being unique of customer ---
            $stmt = $conn->prepare("SELECT customer_id FROM Customer WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $email_error = "This email is already registered.";
            }
            $stmt->close();

            if ($username_error === "" && $email_error === "") {

                // --- Generate a unique 8-digit customer_id ---
                $customer_id = null;
                for ($attempt = 0; $attempt < 20; $attempt++) {
                    $candidate = random_int(10000000, 99999999);

                    $check = $conn->prepare("SELECT customer_id FROM Customer WHERE customer_id = ?");
                    $check->bind_param("i", $candidate);
                    $check->execute();
                    $exists = $check->get_result()->num_rows > 0;
                    $check->close();

                    if (!$exists) {
                        $customer_id = $candidate;
                        break;
                    }
                }

                if ($customer_id === null) {
                    $errors[] = "Could not generate a unique customer ID. Please try again.";
                } else {

                    $password_hash = password_hash($password, PASSWORD_DEFAULT);

                    // --- Simple transaction: Customer + Customer_phone ---
                    $conn->begin_transaction();

                    try {
                        $stmt1 = $conn->prepare(
                            "INSERT INTO Customer
                             (customer_id, username, password, first_name, last_name, email, house_no, area, district)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                        );
                        $stmt1->bind_param(
                            "issssssss",
                            $customer_id, $username, $password_hash, $first_name,
                            $last_name, $email, $house_no, $area, $district
                        );
                        $stmt1->execute();
                        $stmt1->close();

                        $stmt2 = $conn->prepare(
                            "INSERT INTO Customer_phone (customer_id, phone) VALUES (?, ?)"
                        );
                        $stmt2->bind_param("is", $customer_id, $mobile);
                        $stmt2->execute();
                        $stmt2->close();

                        $conn->commit();

                        $_SESSION["customer_id"] = $customer_id;
                        $success = true;

                    } catch (Exception $e) {
                        $conn->rollback();
                        $errors[] = "Something went wrong while creating your account. Please try again.";
                    }
                }
            }

            $conn->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TakaKoi - Create Free Account</title>
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

                <?php if ($success) : ?>

                    <h2 class="text-center mb-16">Account Created!</h2>
                    <div class="message message-success">
                        Welcome to TakaKoi! Your account has been created successfully.
                    </div>
                    <a href="customer_dashboard.php" class="btn btn-primary">Go to Dashboard</a>

                <?php else : ?>

                    <h2 class="text-center mb-16">Create Free Account</h2>

                    <?php foreach ($errors as $error) : ?>
                        <div class="message message-danger"><?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>

                    <form action="create_account.php" method="POST">

                        <div class="form-group">
                            <label for="first_name">First Name</label>
                            <input type="text" id="first_name" name="first_name"
                                   value="<?php echo htmlspecialchars($first_name); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="last_name">Last Name</label>
                            <input type="text" id="last_name" name="last_name"
                                   value="<?php echo htmlspecialchars($last_name); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" id="username" name="username"
                                   value="<?php echo htmlspecialchars($username); ?>" required>
                            <?php if ($username_error !== "") : ?>
                                <div class="message message-warning mt-16">
                                    <?php echo htmlspecialchars($username_error); ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password" required>
                        </div>

                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email"
                                   value="<?php echo htmlspecialchars($email); ?>" required>
                            <?php if ($email_error !== "") : ?>
                                <div class="message message-warning mt-16">
                                    <?php echo htmlspecialchars($email_error); ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="house_no">House Number</label>
                            <input type="text" id="house_no" name="house_no"
                                   value="<?php echo htmlspecialchars($house_no); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="area">Area</label>
                            <input type="text" id="area" name="area"
                                   value="<?php echo htmlspecialchars($area); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="district">District</label>
                            <input type="text" id="district" name="district"
                                   value="<?php echo htmlspecialchars($district); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="mobile">Mobile Number</label>
                            <input type="text" id="mobile" name="mobile" maxlength="11"
                                   value="<?php echo htmlspecialchars($mobile); ?>" required>
                        </div>

                        <button type="submit" class="btn btn-primary">Create Account</button>

                    </form>

                    <p class="small-text text-center mt-16">
                        Already have an account?
                        <a href="login.php">Login here</a>
                    </p>

                <?php endif; ?>

            </div>

        </div>
    </div>

</body>
</html>
