<?php
// ============================================================
// login.php — Login page
//
// Authentication is handled LOCALLY — no external API needed.
// Credentials are validated by local_check_login() in config.php.
//
// Default credentials: admin / 1234
//
// Flow:
//   1. User opens this page (GET request)
//   2. User enters username + password and clicks Login
//   3. Browser sends POST back to this file
//   4. PHP calls local_check_login() — purely local check
//   5. If success → save username in session → go to dashboard
//   6. If failure → show error message on the form
// ============================================================

require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Already logged in? Go straight to the dashboard.
if (!empty($_SESSION['username'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

// ---- Handle form submission (POST) -------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        // Validate credentials locally — no cURL, no Azure, no network call.
        // local_check_login() is defined in config.php.
        $result = local_check_login($username, $password);

        if ($result['success'] === true) {
            $_SESSION['username'] = $username;
            header('Location: dashboard.php');
            exit;
        } else {
            $error = $result['message'] ?? 'Login failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — LoginSample</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        body { display: flex; align-items: center; justify-content: center; }
        .login-card { width: 100%; max-width: 420px; }
        .login-card h1 { text-align: center; margin-bottom: 1.8rem; }
        .login-footer { text-align: center; margin-top: 1rem; font-size: 0.85rem; color: #6b7280; }
    </style>
</head>
<body>
    <div class="card login-card">
        <h1>🔐 LoginSample</h1>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php" autocomplete="off">

            <div class="form-group">
                <label for="username">Username</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    value="<?= h($_POST['username'] ?? '') ?>"
                    placeholder="Enter your username"
                    autofocus
                    required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Enter your password"
                    required>
            </div>

            <div class="mt-2">
                <button type="submit" class="btn btn-primary" style="width:100%">
                    Login
                </button>
            </div>

        </form>

        <p class="login-footer">Default credentials: <strong>admin / 1234</strong></p>
    </div>
</body>
</html>
