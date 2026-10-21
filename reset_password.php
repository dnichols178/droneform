<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

$pdo = getDB();

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$err = '';
$validToken = false;
$resetRecord = null;
$user = null;

if (!empty($token)) {
    $tokenHash = hash('sha256', $token);
    $localNow = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        SELECT pr.*, u.id AS user_id, u.username, u.full_name, u.email, u.is_active
        FROM password_resets pr
        JOIN users u ON pr.user_id = u.id
        WHERE pr.token_hash = ? AND pr.expires_at > ?
        LIMIT 1
    ");
    $stmt->execute([$tokenHash, $localNow]);
    $resetRecord = $stmt->fetch();

    if ($resetRecord && (int)$resetRecord['is_active'] === 1) {
        $validToken = true;
        $user = $resetRecord;
    } else {
        $err = 'This password reset link is invalid, expired, or has already been used.';
    }
} else {
    $err = 'No password reset token was provided.';
}

// Handle password update submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    $newPassword = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($newPassword) || empty($confirmPassword)) {
        $err = 'Please enter and confirm your new password.';
    } elseif ($newPassword !== $confirmPassword) {
        $err = 'Passwords do not match. Please try again.';
    } elseif (strlen($newPassword) < 8) {
        $err = 'Password must be at least 8 characters in length.';
    } else {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        try {
            // Update user's password
            $updateStmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $updateStmt->execute([$newHash, $user['user_id']]);

            // Delete all reset tokens for this user
            $delStmt = $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?");
            $delStmt->execute([$user['user_id']]);

            header('Location: login.php?msg=password_reset');
            exit;
        } catch (PDOException $e) {
            $err = 'An error occurred while resetting your password: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-header">
            <img width="100%" src="./assets/frnp_logo.png" alt="FRNP Logo">
            <p>Drone Flight Management and Notification System</p>
        </div>

        <h3 style="margin-top: 0; font-size: 1.15rem; color: var(--text-primary); text-align: center;">Set New Password</h3>

        <?php if ($err): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
        <?php endif; ?>

        <?php if ($validToken): ?>
            <p style="font-size: 0.85rem; color: var(--text-muted); text-align: center; margin-bottom: 1.25rem;">
                Resetting password for <strong><?= htmlspecialchars($user['username']) ?></strong> (<?= htmlspecialchars($user['full_name']) ?>).
            </p>

            <form method="POST" action="reset_password.php">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                <div class="form-group">
                    <label for="password">New Password</label>
                    <input type="password" id="password" name="password" class="form-control" placeholder="At least 8 characters" required autofocus>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Re-type new password" required>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Update Password</button>
            </form>
        <?php else: ?>
            <div style="text-align: center; margin-top: 1.5rem;">
                <a href="forgot_password.php" class="btn btn-primary btn-sm">Request New Reset Link</a>
                <div style="margin-top: 1rem;">
                    <a href="login.php" style="font-size: 0.9rem; text-decoration: none;">&larr; Back to Login</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
