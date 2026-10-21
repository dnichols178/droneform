<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$user = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Operations') ?> - <?= APP_NAME ?></title>
    <!-- Leaflet Map CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <!-- Platform Stylesheet with Automatic Cache Busting -->
    <link rel="stylesheet" href="assets/style.css?v=<?= file_exists(BASE_PATH . '/assets/style.css') ? filemtime(BASE_PATH . '/assets/style.css') : time() ?>">
</head>
<body>

<nav class="navbar">
    <div class="nav-brand">
        <strong>FRNP Drone Flight Management System</strong>
    </div>
    <ul class="nav-links">
        <li><a href="dashboard.php" class="<?= ($currentPage ?? '') === 'dashboard' ? 'active' : '' ?>">Dashboard</a></li>
        <li><a href="flights.php" class="<?= ($currentPage ?? '') === 'flights' ? 'active' : '' ?>">Flight Log</a></li>
        <?php if (isAdmin()): ?>
            <li><a href="users.php" class="<?= ($currentPage ?? '') === 'users' ? 'active' : '' ?>">User Administration</a></li>
            <li><a href="settings.php" class="<?= ($currentPage ?? '') === 'settings' ? 'active' : '' ?>">Settings</a></li>
        <?php endif; ?>
        <?php if (isLoggedIn()): ?>
            <li><a href="flight_create.php" class="<?= ($currentPage ?? '') === 'create_flight' ? 'active' : '' ?>">New Flight</a></li>
        <?php endif; ?>
    </ul>
    <div class="nav-user">
        <?php if (isLoggedIn() && $user): ?>
            <span><strong><?= htmlspecialchars($user['full_name']) ?></strong> (<?= strtoupper($user['role']) ?>)</span>
            <a href="logout.php" class="btn btn-sm btn-outline">Log Out</a>
        <?php else: ?>
            <a href="login.php" class="btn btn-sm btn-outline">Log In</a>
        <?php endif; ?>
    </div>
</nav>

<main class="main-content">
