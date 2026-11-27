<?php

require_once __DIR__ . '/includes/config.php';

$is_logged_in = !empty($_SESSION['user_id']);
$session_user_type = $_SESSION['user_type'] ?? '';

$errors = [];
$full_name = '';
$username = '';
$email = '';
$phone_number = '';
$citizenship_id = '';
$user_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $citizenship_id = trim($_POST['citizenship_id'] ?? '');
    $user_type = trim($_POST['user_type'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($full_name === '' || $username === '' || $email === '' || $citizenship_id === '' || $user_type === '' || $password === '') {
        $errors[] = 'Please fill in all required fields.';
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (!in_array($user_type, ['tenant', 'landlord'], true)) {
        $errors[] = 'Please select a valid user type.';
    }

    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }

    if ($phone_number !== '' && !preg_match('/^[0-9]{10}$/', $phone_number)) {
        $errors[] = 'Phone number must be exactly 10 digits.';
    }

    if (empty($errors)) {
        $conn = db_connect();

        if ($phone_number !== '') {
            $stmt = mysqli_prepare($conn, 'SELECT username, email, phone_number FROM users WHERE username = ? OR email = ? OR phone_number = ? LIMIT 1');
            mysqli_stmt_bind_param($stmt, 'sss', $username, $email, $phone_number);
        } else {
            $stmt = mysqli_prepare($conn, 'SELECT username, email FROM users WHERE username = ? OR email = ? LIMIT 1');
            mysqli_stmt_bind_param($stmt, 'ss', $username, $email);
        }
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if ($result && mysqli_num_rows($result) > 0) {
            $existing = mysqli_fetch_assoc($result);
            if ($existing['username'] === $username) {
                $errors[] = 'Username already exists.';
            } elseif ($existing['email'] === $email) {
                $errors[] = 'Email address already exists.';
            } elseif ($phone_number !== '' && $existing['phone_number'] === $phone_number) {
                $errors[] = 'Phone number already exists.';
            }
        }

        if (empty($errors)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $verification_status = $user_type === 'tenant' ? 'verified' : 'pending';
            $stmt = mysqli_prepare($conn, 'INSERT INTO users (full_name, username, email, phone_number, citizenship_id, user_type, password) VALUES (?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($stmt, 'sssssss', $full_name, $username, $email, $phone_number, $citizenship_id, $user_type, $hashed_password);
            if (mysqli_stmt_execute($stmt)) {
                $newUserId = mysqli_insert_id($conn);

                if ($user_type === 'landlord') {
                    $landlordStmt = mysqli_prepare($conn, 'INSERT INTO landlord (user_id, verification_status) VALUES (?, ?)');
                    if ($landlordStmt) {
                        mysqli_stmt_bind_param($landlordStmt, 'is', $newUserId, $verification_status);
                        mysqli_stmt_execute($landlordStmt);
                    }
                } elseif ($user_type === 'tenant') {
                    $tenantStmt = mysqli_prepare($conn, 'INSERT INTO tenant (user_id) VALUES (?)');
                    if ($tenantStmt) {
                        mysqli_stmt_bind_param($tenantStmt, 'i', $newUserId);
                        mysqli_stmt_execute($tenantStmt);
                    }
                }

                mysqli_close($conn);
                header('Location: login.php');
                exit();
            }

            $errors[] = 'Registration failed. Please try again.';
        }

        mysqli_close($conn);
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GHARELU - Signup</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/auth.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>

<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container">
            <div class="nav-brand">
                <a href="index.php">
                    Gharelu
                </a>
            </div>
            <div class="nav-content">
                <div class="nav-links">
                    <a href="index.php">Home</a>
                    <a href="index.php#properties-section">Listings</a>
                    <a href="index.php#platform-section">About</a>
                    <a href="index.php#contact-us-section">Contact</a>
                    <?php if ($is_logged_in): ?>
                        <?php if ($session_user_type === 'tenant'): ?>
                            <a href="tenant/dashboard.php">Dashboard</a>
                        <?php elseif ($session_user_type === 'landlord'): ?>
                            <a href="landlord/dashboard.php">Dashboard</a>
                        <?php elseif ($session_user_type === 'admin'): ?>
                            <a href="admin/dashboard.php">Dashboard</a>
                        <?php endif; ?>
                        <a href="profile.php">Profile</a>
                    <?php endif; ?>
                </div>
                <div class="auth-buttons">
                    <?php if ($is_logged_in): ?>
                        <span class="user-greeting" style="font-weight: 500; font-size: 0.9rem; color: var(--text-light); align-self: center; margin-right: 0.5rem;">
                            Hello, <?php echo htmlspecialchars($_SESSION['username']); ?>
                        </span>
                        <a href="logout.php" class="btn-outline"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    <?php else: ?>
                        <a href="login.php" class="btn-primary">Login</a>
                        <a href="signup.php" class="btn-primary">Register</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="mobile-menu" onclick="toggleMobileMenu()">
                <i class="fas fa-bars"></i>
            </div>
        </div>
    </nav>

    <main class="auth-main">

    <div class="register-container">

        <div class="top-section">

            <h1>Join GHARELU</h1>

            <p>Create your account and start renting</p>

        </div>

        <div class="form-section">

            <?php if (!empty($errors)): ?>
                <div class="error-message">
                    <ul>
                        <?php foreach ($errors as $err): ?>
                            <li><?php echo htmlspecialchars($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST">

                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="full_name" placeholder="Enter full name" value="<?php echo htmlspecialchars($full_name); ?>" required>
                </div>

                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" placeholder="Enter username" value="<?php echo htmlspecialchars($username); ?>" required>
                </div>

                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" placeholder="Enter email address" value="<?php echo htmlspecialchars($email); ?>" required>
                </div>

                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="text" name="phone_number" minlength="10" maxlength="10" placeholder="98XXXXXXXX" value="<?php echo htmlspecialchars($phone_number); ?>">
                </div>

                <div class="form-group">
                    <label>Citizenship ID</label>
                    <input type="text" name="citizenship_id" placeholder="Eg:04-01-73-02257" minlength="9" required value="<?php echo htmlspecialchars($citizenship_id); ?>">
                </div>

                <div class="form-group">
                    <label>User Type</label>

                    <select name="user_type" required>

                        <option value="">Select User Type</option>

                        <option value="tenant" <?php echo $user_type === 'tenant' ? 'selected' : ''; ?>>Tenant</option>

                        <option value="landlord" <?php echo $user_type === 'landlord' ? 'selected' : ''; ?>>Landlord</option>



                    </select>
                </div>

                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" placeholder="Enter your password (minimum 8 characters)" required minlength="8">
                </div>

                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" placeholder="Confirm your password" minlength="8">
                </div>

                <button type="submit" class="register-btn">
                    REGISTER
                </button>

            </form>

            <div class="login-text">

                Already have an account?

                <a href="login.php">Login here</a>

            </div>

        </div>

    </div>

    </main>

    <script>
    function toggleMobileMenu() {
        const navContent = document.querySelector('.nav-content');
        if (navContent) {
            navContent.classList.toggle('open');
        }
    }
    </script>

</body>

</html>