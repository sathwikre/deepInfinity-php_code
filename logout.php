<?php
// ============================================================
// logout.php — Destroys the session and redirects to login
//
// This page has no HTML — it just performs the logout action
// and immediately redirects. The user never sees this page.
// ============================================================

// Start the session so we can access and destroy it
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Remove all session variables (clears the login state)
$_SESSION = [];

// Destroy the session cookie in the browser
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy the server-side session data
session_destroy();

// Send the user back to the login page
header('Location: login.php');
exit;
