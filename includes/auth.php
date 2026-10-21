<?php
require_once __DIR__ . '/db.php';

function getCurrentUser(): ?array {
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function isAdmin(): bool {
    if (!isLoggedIn()) {
        return false;
    }
    $user = getCurrentUser();
    return $user && strtolower(trim($user['role'] ?? '')) === 'admin';
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function requireAdmin(): void {
    requireLogin();
    $user = getCurrentUser();
    
    // Validate role directly against the database (case-insensitive)
    if (!$user || strtolower(trim($user['role'] ?? '')) !== 'admin') {
        header('Location: dashboard.php?error=unauthorized');
        exit;
    }

    // Keep session in sync
    $_SESSION['user_role'] = $user['role'];
}
