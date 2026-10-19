<?php
require_once __DIR__ . '/includes/config.php';

// Unset drone-specific keys
unset($_SESSION['drone_user_id']);
unset($_SESSION['drone_username']);
unset($_SESSION['drone_role']);
unset($_SESSION['drone_logged_in']);

// Expire the drone session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

session_destroy();

header('Location: /drone/login.php');
exit;
