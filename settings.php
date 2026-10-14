<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';

// Enforce site administrator privileges
requireAdmin();

$pageTitle = 'System & Notification Settings';
$currentPage = 'settings';
require_once __DIR__ . '/includes/header.php';
$pdo = getDB();

$msg = '';
$err = '';

// 1. Handle SMTP Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_smtp') {
    $settingsToSave = [
        'smtp_host' => trim($_POST['smtp_host'] ?? ''),
        'smtp_port' => trim($_POST['smtp_port'] ?? '25'),
        'smtp_auth' => isset($_POST['smtp_auth']) ? '1' : '0',
        'smtp_user' => trim($_POST['smtp_user'] ?? ''),
        'smtp_pass' => trim($_POST['smtp_pass'] ?? ''),
        'smtp_secure' => $_POST['smtp_secure'] ?? 'none',
        'mail_from' => trim($_POST['mail_from'] ?? ''),
        'mail_from_name' => trim($_POST['mail_from_name'] ?? '')
    ];

    $stmt = $pdo->prepare("INSERT OR REPLACE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($settingsToSave as $k => $v) {
        $stmt->execute([$k, $v]);
    }
    $msg = "SMTP and email server settings saved successfully.";
}

// 2. Handle Live Test Email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_smtp') {
    $testTo = trim($_POST['test_email'] ?? '');
    if (!filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
        $err = "Please enter a valid email address for the test.";
    } else {
        $testSubject = "UAS Part 107 Ops Platform: SMTP Test Connection";
        $testBody = "This is a test notification verifying the SMTP relay settings on the Part 107 Flight Operations Platform.\r\n" .
                    "Timestamp: " . date('Y-m-d H:i:s') . "\r\n" .
                    "Host: " . getSystemSetting('smtp_host') . ":" . getSystemSetting('smtp_port') . "\r\n";
        $testError = null;
        if (sendSmtpMail([$testTo], $testSubject, $testBody, $testError)) {
            $msg = "Test email successfully sent to {$testTo}!";
        } else {
            $err = "SMTP Test Failed: " . htmlspecialchars($testError);
        }
    }
}

// 3. Handle Add Mandatory Recipient
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_recipient') {
    $name = trim($_POST['full_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $org = trim($_POST['organization'] ?? '');

    if ($name && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO distribution_recipients (full_name, email, organization) VALUES (?, ?, ?)");
            $stmt->execute([$name, $email, $org]);
            $msg = "Recipient '{$name}' added to procedural distribution list.";
        } catch (PDOException $e) {
            $err = "Error adding recipient: " . $e->getMessage();
        }
    } else {
        $err = "Please provide a valid name and email address.";
    }
}

// 4. Handle Delete Mandatory Recipient
if (isset($_GET['delete_recipient'])) {
    $delId = (int)$_GET['delete_recipient'];
    $stmt = $pdo->prepare("DELETE FROM distribution_recipients WHERE id = ?");
    $stmt->execute([$delId]);
    header('Location: settings.php?deleted=1');
    exit;
}

// Fetch current values
$smtpHost = getSystemSetting('smtp_host', '127.0.0.1');
$smtpPort = getSystemSetting('smtp_port', '25');
$smtpAuth = getSystemSetting('smtp_auth', '0') === '1';
$smtpUser = getSystemSetting('smtp_user', '');
$smtpPass = getSystemSetting('smtp_pass', '');
$smtpSecure = getSystemSetting('smtp_secure', 'none');
$mailFrom = getSystemSetting('mail_from', 'flight-ops@uas-part107.com');
$mailFromName = getSystemSetting('mail_from_name', 'FAA Part 107 Flight Operations');

$recipients = $pdo->query("SELECT * FROM distribution_recipients ORDER BY id ASC")->fetchAll();
?>

<?php if (isset($_GET['deleted'])): ?><div class="alert alert-success">Recipient removed from distribution list.</div><?php endif; ?>
<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="user-admin-split" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; align-items: start; width: 100%;">

    <!-- LEFT COLUMN: MANDATORY PROCEDURAL DISTRIBUTION LIST -->
    <div style="min-width: 0;">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Mandatory Procedural Distribution List</h2>
                <span class="badge badge-neutral"><?= count($recipients) ?> Required Contacts</span>
            </div>
            <div class="card-body">
                <p class="form-hint" style="margin-bottom: 1.25rem;">
                    These contacts are required by flight safety procedures. They automatically receive official flight plan notifications once LAANC authorization is recorded. End users and pilots cannot alter or remove these recipients.
                </p>

                <div class="table-responsive" style="margin-bottom: 1.5rem;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name / Role</th>
                                <th>Email</th>
                                <th>Organization</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recipients)): ?>
                                <tr><td colspan="4" style="text-align: center; color: var(--text-muted);">No procedural recipients configured.</td></tr>
                            <?php else: foreach ($recipients as $r): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($r['full_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($r['email']) ?></td>
                                    <td><small style="color:var(--text-muted);"><?= htmlspecialchars($r['organization'] ?: 'Standard') ?></small></td>
                                    <td>
                                        <a href="settings.php?delete_recipient=<?= $r['id'] ?>" 
                                           class="btn btn-outline btn-sm" 
                                           style="color: var(--danger); border-color: var(--border);"
                                           onclick="return confirm('Remove this mandatory contact from future flight dispatches?');">
                                            Remove
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card" style="border: 1px solid var(--border-light); background: #f8fafc;">
                    <div class="card-header" style="background: transparent;">
                        <span class="card-title" style="font-size: 1rem;">Add Required Procedural Contact</span>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="settings.php">
                            <input type="hidden" name="action" value="add_recipient">
                            <div class="form-group">
                                <label for="full_name">Full Name or Functional Title</label>
                                <input type="text" name="full_name" id="full_name" class="form-control" placeholder="e.g. Site Security Command" required>
                            </div>
                            <div class="form-group">
                                <label for="email">Email Address</label>
                                <input type="email" name="email" id="email" class="form-control" placeholder="soc@site.local" required>
                            </div>
                            <div class="form-group">
                                <label for="organization">Organization / Department (Optional)</label>
                                <input type="text" name="organization" id="organization" class="form-control" placeholder="e.g. Security &amp; Emergency Management">
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">Add Required Recipient</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- RIGHT COLUMN: SERVER SMTP CONFIGURATION -->
    <div style="min-width: 0;">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">SMTP Relay &amp; Email Server Configuration</h2>
            </div>
            <div class="card-body">
                <p class="form-hint" style="margin-bottom: 1.25rem;">
                    Configure the outbound SMTP server used for all automated flight plan notifications. For server migrations or internal networks, specify your internal relay IP and port.
                </p>

                <form method="POST" action="settings.php">
                    <input type="hidden" name="action" value="save_smtp">

                    <div class="grid grid-cols-2">
                        <div class="form-group">
                            <label for="smtp_host">SMTP Host or Server IP</label>
                            <input type="text" name="smtp_host" id="smtp_host" class="form-control" value="<?= htmlspecialchars($smtpHost) ?>" placeholder="e.g. 10.0.0.25 or mail.domain.com" required>
                            <div class="form-hint">Internal relay IP or hostname.</div>
                        </div>
                        <div class="form-group">
                            <label for="smtp_port">SMTP Port</label>
                            <input type="number" name="smtp_port" id="smtp_port" class="form-control" value="<?= htmlspecialchars($smtpPort) ?>" required>
                            <div class="form-hint">Standard: 25 (Relay), 587 (Submission), 465 (SSL).</div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="smtp_secure">Connection Security</label>
                        <select name="smtp_secure" id="smtp_secure" class="form-control">
                            <option value="none" <?= $smtpSecure === 'none' ? 'selected' : '' ?>>None (Standard / Internal Network Relay)</option>
                            <option value="tls" <?= $smtpSecure === 'tls' ? 'selected' : '' ?>>STARTTLS (Port 587)</option>
                            <option value="ssl" <?= $smtpSecure === 'ssl' ? 'selected' : '' ?>>SSL / TLS (Port 465)</option>
                        </select>
                    </div>

                    <div class="form-group" style="background: #f8fafc; padding: 0.85rem; border-radius: 6px; border: 1px solid var(--border-light);">
                        <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer;">
                            <input type="checkbox" name="smtp_auth" value="1" <?= $smtpAuth ? 'checked' : '' ?> onchange="document.getElementById('smtp-auth-fields').style.display = this.checked ? 'grid' : 'none';">
                            <strong>Enable SMTP Authentication</strong>
                        </label>
                        <div class="form-hint" style="margin-left: 1.5rem;">Check if the relay requires a username and password.</div>

                        <div id="smtp-auth-fields" class="grid grid-cols-2" style="margin-top: 0.75rem; display: <?= $smtpAuth ? 'grid' : 'none' ?>;">
                            <div class="form-group">
                                <label for="smtp_user">SMTP Username</label>
                                <input type="text" name="smtp_user" id="smtp_user" class="form-control" value="<?= htmlspecialchars($smtpUser) ?>">
                            </div>
                            <div class="form-group">
                                <label for="smtp_pass">SMTP Password</label>
                                <input type="password" name="smtp_pass" id="smtp_pass" class="form-control" value="<?= htmlspecialchars($smtpPass) ?>">
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-2" style="margin-top: 1rem;">
                        <div class="form-group">
                            <label for="mail_from">Default "From" Email Address</label>
                            <input type="email" name="mail_from" id="mail_from" class="form-control" value="<?= htmlspecialchars($mailFrom) ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="mail_from_name">Default "From" Sender Name</label>
                            <input type="text" name="mail_from_name" id="mail_from_name" class="form-control" value="<?= htmlspecialchars($mailFromName) ?>" required>
                        </div>
                    </div>

                    <div style="margin-top: 1.25rem;">
                        <button type="submit" class="btn btn-primary">Save SMTP Settings</button>
                    </div>
                </form>

                <div class="card" style="margin-top: 1.5rem; border: 1px dashed var(--border); padding: 1.25rem;">
                    <h3 style="font-size: 1rem; margin-bottom: 0.5rem;">Test SMTP Relay Connection</h3>
                    <form method="POST" action="settings.php" style="display: flex; gap: 0.5rem;">
                        <input type="hidden" name="action" value="test_smtp">
                        <input type="email" name="test_email" class="form-control" placeholder="your-email@site.com" required>
                        <button type="submit" class="btn btn-outline btn-sm" style="white-space: nowrap;">Send Test Email</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>