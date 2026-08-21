<?php
// ============================================================
// login.php — Login page
//
// Flow:
//   1. User opens this page in the browser (GET request)
//   2. User types username + password and clicks Login
//   3. Browser sends a POST request back to this same file
//   4. PHP calls the Azure Function POST /api/login with JSON
//   5. If success=true  → save username in session, go to dashboard
//   6. If success=false → show the error message from the backend
// ============================================================

require_once 'config.php';

// Start the session so we can store login state
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If the user is already logged in, send them straight to the dashboard
if (!empty($_SESSION['username'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';   // Will hold any error message to show on the form

// ---- Handle the form submission (POST) ---------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Read and trim what the user typed
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // Basic client-side-style validation before hitting the backend
    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        // Call the Azure Function /api/login with JSON body
        // call_api_json() is defined in config.php
        $result = call_api_json('/login', [
            'username' => $username,
            'password' => $password,
        ]);

        if ($result['success'] === true) {
            // Login succeeded — store the username in the session
            // and redirect to the dashboard
            $_SESSION['username'] = $username;
            header('Location: dashboard.php');
            exit;
        } else {
            // Login failed — show the message from the backend
            // e.g. "Invalid Credentials" or a network error
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
        /* Centre the login card vertically on the page */
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
            <!-- Show the error message if login failed -->
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>

        <!--
            The form posts back to this same file (action="login.php").
            method="post" sends the data in the request body, not the URL.
        -->
        <form method="post" action="login.php" autocomplete="off">

            <div class="form-group">
                <label for="username">Username</label>
                <!-- h() escapes the value to prevent XSS -->
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
