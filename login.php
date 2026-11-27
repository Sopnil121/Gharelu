<?php

require_once __DIR__ . '/includes/config.php';

$is_logged_in = !empty($_SESSION['user_id']);
$session_user_type = $_SESSION['user_type'] ?? '';

if (!empty($_SESSION['user_id']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_role_home();
}

$errors = [];
$login_id = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    session_regenerate_id(true);
    $_SESSION = [];

    $login_id = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($login_id === '' || $password === '') {
        $errors[] = 'Please enter both username/email and password.';
    } else {
        $conn = db_connect();
        $stmt = mysqli_prepare($conn, 'SELECT id, username, password, user_type FROM users WHERE username = ? OR email = ? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'ss', $login_id, $login_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if ($result && mysqli_num_rows($result) === 1) {
            $user = mysqli_fetch_assoc($result);

            if ($user['user_type'] === 'landlord') {
                $verifyStmt = mysqli_prepare($conn, 'SELECT verification_status FROM landlord WHERE user_id = ? LIMIT 1');
                if ($verifyStmt) {
                    mysqli_stmt_bind_param($verifyStmt, 'i', $user['id']);
                    mysqli_stmt_execute($verifyStmt);
                    $verifyResult = mysqli_stmt_get_result($verifyStmt);
                    $landlordRow = mysqli_fetch_assoc($verifyResult);
                } else {
                    $landlordRow = null;
                }

                if (!$landlordRow || $landlordRow['verification_status'] !== 'verified') {
                    $errors[] = 'Your landlord account is pending verification. Please wait for admin approval.';
                    mysqli_close($conn);
                } elseif (password_verify($password, $user['password'])) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['user_type'] = $user['user_type'];
                    mysqli_close($conn);
                    redirect_role_home();
                } else {
                    $errors[] = 'Incorrect password.';
                    mysqli_close($conn);
                }
            } elseif (password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_type'] = $user['user_type'];
                mysqli_close($conn);
                redirect_role_home();
            } else {
                $errors[] = 'Incorrect password.';
                mysqli_close($conn);
            }
        } else {
            $errors[] = 'User not found.';
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GHARELU - Login</title>
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

    <div class="login-container">

        <div class="top-section">

            <h1>Welcome Back</h1>

            <p>Login to your GHARELU account</p>

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
                    <label>Username or Email</label>
                    <input type="text" name="username" placeholder="Enter username or email" value="<?php echo htmlspecialchars($login_id); ?>" required>
                </div>

                <div class="form-group">

                    <label>Password</label>

                    <input type="password" name="password" required>

                </div>

                <button type="submit" class="login-btn">
                    LOGIN
                </button>

            </form>

            <div class="register-text">

                Don't have an account?

                <a href="signup.php">Register here</a>

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