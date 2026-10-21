<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/mailer.php';

$pdo = getDB();
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Please enter a valid email address.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Generate a secure raw token and its cryptographic hash
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Invalidate any older pending reset tokens for this user
            $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$user['id']]);

            // Save new token
            $insertStmt = $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
            $insertStmt->execute([$user['id'], $tokenHash, $expiresAt]);

            // Build fully qualified URL for the reset link
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
            $resetUrl = "{$scheme}://{$host}{$dir}/reset_password.php?token={$rawToken}";

            $subject = "FRNP Drone Operations - Password Reset Request";
            $htmlBody = "
                <div style='font-family: Segoe UI, Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; color: #1e293b;'>
                    <h2 style='color: #0f172a; margin-top: 0;'>Password Reset Request</h2>
                    <p>Hello <strong>" . htmlspecialchars($user['full_name']) . "</strong>,</p>
                    <p>We received a request to reset the password for your account (<strong>" . htmlspecialchars($user['username']) . "</strong>).</p>
                    <p style='margin: 25px 0;'>
                        <a href='" . htmlspecialchars($resetUrl) . "' style='background-color: #2563eb; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: 600; display: inline-block;'>Reset Password</a>
                    </p>
                    <p style='font-size: 0.9rem; color: #64748b;'>This link will remain valid for 1 hour. If you did not initiate this request, you can safely disregard this message.</p>
                    <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;'>
                    <p style='font-size: 0.8rem; color: #94a3b8;'>If the button above does not work, copy and paste this link into your browser:<br>" . htmlspecialchars($resetUrl) . "</p>
                </div>
            ";
            $textBody = "Password Reset Request\r\n\r\n"
                      . "Hello " . $user['full_name'] . ",\r\n\r\n"
                      . "Use the following link to reset your password:\r\n"
                      . $resetUrl . "\r\n\r\n"
                      . "This link expires in 1 hour. If you did not make this request, ignore this email.";

            $smtpError = null;
            sendSmtpMail([$user['email']], $subject, $htmlBody, $textBody, $smtpError);
        }

        // Generic notification to prevent username/email enumeration
        $msg = 'If an active account exists for that email address, a password reset link has been dispatched. Please check your inbox.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-header">
            <img width="100%" src="./assets/frnp_logo.png" alt="FRNP Logo">
            <p>Drone Flight Management and Notification System</p>
        </div>

        <h3 style="margin-top: 0; font-size: 1.15rem; color: var(--text-primary); text-align: center;">Reset Account Password</h3>
        <p style="font-size: 0.85rem; color: var(--text-muted); text-align: center; margin-bottom: 1.25rem;">
            Enter your registered email address to receive a secure password recovery link.
        </p>

        <?php if ($msg): ?>
            <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <?php if ($err): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
        <?php endif; ?>

        <form method="POST" action="forgot_password.php">
            <div class="form-group">
                <label for="email">Account Email Address</label>
                <input type="email" id="email" name="email" class="form-control" placeholder="user@example.com" required autofocus>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Send Reset Link</button>
            
            <div style="text-align: center; margin-top: 1.25rem;">
                <a href="login.php" style="font-size: 0.9rem; text-decoration: none;">&larr; Back to Login</a>
            </div>
        </form>
    </div>
</body>
</html>
