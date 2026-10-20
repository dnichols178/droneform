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

// Ensure drones table exists for fleet management
$pdo->exec("
    CREATE TABLE IF NOT EXISTS drones (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        model_name TEXT NOT NULL,
        faa_reg_number TEXT NOT NULL,
        faa_reg_expiration DATE NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");

// 1. Handle SMTP & Timezone Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_smtp') {
    $settingsToSave = [
        'smtp_host'     => trim($_POST['smtp_host'] ?? ''),
        'smtp_port'     => trim($_POST['smtp_port'] ?? '25'),
        'smtp_auth'     => isset($_POST['smtp_auth']) ? '1' : '0',
        'smtp_user'     => trim($_POST['smtp_user'] ?? ''),
        'smtp_pass'     => trim($_POST['smtp_pass'] ?? ''),
        'smtp_secure'   => $_POST['smtp_secure'] ?? 'none',
        'mail_from'     => trim($_POST['mail_from'] ?? ''),
        'mail_from_name'=> trim($_POST['mail_from_name'] ?? ''),
        'app_timezone'  => trim($_POST['app_timezone'] ?? 'America/Chicago')
    ];

    $stmt = $pdo->prepare("INSERT OR REPLACE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($settingsToSave as $k => $v) {
        $stmt->execute([$k, $v]);
    }
    date_default_timezone_set($settingsToSave['app_timezone']);
    $msg = "System, timezone, and SMTP email settings saved successfully.";
}

// 2. Handle Live Test Email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_smtp') {
    $testTo = trim($_POST['test_email'] ?? '');
    if (!filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
        $err = "Please enter a valid email address for the test.";
    } else {
        $testSubject = "FRNP Drone Flight Management System SMTP Test Connection";
        $testBody = "This is a test notification verifying the SMTP relay settings on the FRNP Drone Flight Management System.\r\n" .
                    "Local Timestamp: " . date('Y-m-d H:i:s T') . "\r\n" .
                    "Host: " . getSystemSetting('smtp_host') . ":" . getSystemSetting('smtp_port') . "\r\n";
        $testError = null;
        if (sendSmtpMail([$testTo], $testSubject, nl2br($testBody), $testBody, $testError)) {
            $msg = "Test email successfully sent to {$testTo}!";
        } else {
            $err = "SMTP Test Failed: " . htmlspecialchars($testError);
        }
    }
}

// 3. Handle Manual Reminder Dispatch Trigger
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'run_reminders') {
    $dispatched = checkAndSendPostFlightReminders();
    $msg = "Post-flight log reminder scan complete. Dispatched {$dispatched} overdue reminder notification(s).";
}

// 4. Handle Add Mandatory Recipient
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

// 5. Handle Delete Mandatory Recipient
if (isset($_GET['delete_recipient'])) {
    $delId = (int)$_GET['delete_recipient'];
    $stmt = $pdo->prepare("DELETE FROM distribution_recipients WHERE id = ?");
    $stmt->execute([$delId]);
    header('Location: settings.php?msg=recipient_deleted');
    exit;
}

// 6. Handle Add Prepopulated Drone Model (Requirement 5)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_drone') {
    $modelName = trim($_POST['drone_model_name'] ?? '');
    $faaReg = trim($_POST['drone_faa_reg'] ?? '');
    $faaExp = trim($_POST['drone_faa_exp'] ?? '');

    if (!empty($modelName) && !empty($faaReg) && !empty($faaExp)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO drones (model_name, faa_reg_number, faa_reg_expiration) VALUES (?, ?, ?)");
            $stmt->execute([$modelName, $faaReg, $faaExp]);
            $msg = "Aircraft '{$modelName}' added to prepopulated fleet list.";
        } catch (PDOException $e) {
            $err = "Failed to add drone: " . $e->getMessage();
        }
    } else {
        $err = "Please enter Drone Model Name, FAA Registration #, and Expiration Date.";
    }
}

// 7. Handle Delete Prepopulated Drone Model
if (isset($_GET['delete_drone'])) {
    $delDroneId = (int)$_GET['delete_drone'];
    $stmt = $pdo->prepare("DELETE FROM drones WHERE id = ?");
    $stmt->execute([$delDroneId]);
    header('Location: settings.php?msg=drone_deleted');
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
$currentTimezone = getSystemSetting('app_timezone', 'America/Chicago');

$recipients = $pdo->query("SELECT * FROM distribution_recipients ORDER BY id ASC")->fetchAll();
$drones = $pdo->query("SELECT * FROM drones ORDER BY model_name ASC")->fetchAll();

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'recipient_deleted') $msg = "Recipient removed from distribution list.";
    if ($_GET['msg'] === 'drone_deleted') $msg = "Drone removed from prepopulated fleet.";
}
?>

<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="user-admin-split" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; align-items: start; width: 100%;">
    <!-- LEFT COLUMN: PREPOPULATED DRONES & MANDATORY RECIPIENTS -->
    <div style="min-width: 0; display: flex; flex-direction: column; gap: 1.5rem;">
        <!-- PREPOPULATED DRONE FLEET MANAGEMENT (Requirement 5) -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Prepopulated Drone Fleet</h2>
                <span class="badge badge-neutral"><?= count($drones) ?> Aircraft</span>
            </div>
            <div class="card-body">
                <p class="form-hint" style="margin-bottom: 1.25rem;">
                    Manage preconfigured aircraft available in the flight scheduler. Includes model name, FAA registration number, and FAA expiration date.
                </p>

                <div class="table-responsive" style="margin-bottom: 1.5rem;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Model Name</th>
                                <th>FAA Reg #</th>
                                <th>Reg. Expiration</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($drones)): ?>
                                <tr><td colspan="4" style="text-align: center; color: var(--text-muted);">No prepopulated drones registered. Use the form below to add one.</td></tr>
                            <?php else: foreach ($drones as $d): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($d['model_name']) ?></strong></td>
                                    <td><code style="font-size: 0.85rem;"><?= htmlspecialchars($d['faa_reg_number']) ?></code></td>
                                    <td><?= htmlspecialchars($d['faa_reg_expiration']) ?></td>
                                    <td>
                                        <a href="settings.php?delete_drone=<?= $d['id'] ?>" 
                                           class="btn btn-outline btn-sm" 
                                           style="color: var(--danger); border-color: var(--border);"
                                           onclick="return confirm('Remove <?= htmlspecialchars(addslashes($d['model_name'])) ?> from the prepopulated fleet?');">
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
                        <span class="card-title" style="font-size: 1rem;">Add Prepopulated Drone</span>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="settings.php">
                            <input type="hidden" name="action" value="add_drone">
                            <div class="form-group">
                                <label for="drone_model_name">Drone Make &amp; Model</label>
                                <input type="text" name="drone_model_name" id="drone_model_name" class="form-control" placeholder="e.g. Skydio X10D, DJI Matrice 350 RTK" required>
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                                <div class="form-group">
                                    <label for="drone_faa_reg">FAA Registration #</label>
                                    <input type="text" name="drone_faa_reg" id="drone_faa_reg" class="form-control" placeholder="FA3XXXXXXXXX" required>
                                </div>
                                <div class="form-group">
                                    <label for="drone_faa_exp">Registration Expiration Date</label>
                                    <input type="date" name="drone_faa_exp" id="drone_faa_exp" class="form-control" required>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">Add Drone to Fleet</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- MANDATORY DISTRIBUTION LIST -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Mandatory Distribution List</h2>
                <span class="badge badge-neutral"><?= count($recipients) ?> Required Contacts</span>
            </div>
            <div class="card-body">
                <p class="form-hint" style="margin-bottom: 1.25rem;">
                    These contacts automatically receive official flight plan notifications once LAANC authorization is recorded.
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
                                <tr><td colspan="4" style="text-align: center; color: var(--text-muted);">No recipients configured.</td></tr>
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
                        <span class="card-title" style="font-size: 1rem;">Add Required Contact</span>
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

                <div class="card" style="margin-top: 1.5rem; border: 1px solid var(--border-light);">
                    <div class="card-header"><h3 class="card-title" style="font-size: 1rem;">Post-Flight Log Reminder Trigger</h3></div>
                    <div class="card-body">
                        <p class="form-hint" style="margin-bottom: 0.75rem;">
                            Click below to immediately trigger an audit scan for overdue logs (&gt; 1 day post-flight).
                        </p>
                        <form method="POST" action="settings.php">
                            <input type="hidden" name="action" value="run_reminders">
                            <button type="submit" class="btn btn-outline btn-sm">Trigger Reminder Scan Now</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- RIGHT COLUMN: SERVER SMTP CONFIGURATION, TIMEZONE & ERROR LOG -->
    <div style="min-width: 0; display: flex; flex-direction: column; gap: 1.5rem;">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">System Timezone &amp; SMTP Configuration</h2>
            </div>
            <div class="card-body">
                <p class="form-hint" style="margin-bottom: 1.25rem;">
                    Configure local operating timezone and outbound SMTP settings for all automated flight plan and reminder notifications.
                </p>
                <form method="POST" action="settings.php">
                    <input type="hidden" name="action" value="save_smtp">

                    <div class="form-group" style="background: #f8fafc; padding: 0.85rem; border-radius: 6px; border: 1px solid var(--border-light); margin-bottom: 1.25rem;">
                        <label for="app_timezone"><strong>Local Operating Time Zone</strong></label>
                        <select name="app_timezone" id="app_timezone" class="form-control" style="font-weight: 600;">
                            <?php
                            $commonTimezones = [
                                'America/Chicago'     => 'Central Time (US & Canada) - CDT/CST',
                                'America/New_York'    => 'Eastern Time (US & Canada) - EDT/EST',
                                'America/Denver'      => 'Mountain Time (US & Canada) - MDT/MST',
                                'America/Phoenix'     => 'Mountain Time (Arizona - No DST)',
                                'America/Los_Angeles' => 'Pacific Time (US & Canada) - PDT/PST',
                                'America/Anchorage'   => 'Alaska Time - AKDT/AKST',
                                'Pacific/Honolulu'    => 'Hawaii Standard Time - HST'
                            ];
                            foreach ($commonTimezones as $tzKey => $tzLabel):
                            ?>
                                <option value="<?= $tzKey ?>" <?= ($currentTimezone === $tzKey) ? 'selected' : '' ?>>
                                    <?= $tzLabel ?> (Current: <?= (new DateTime('now', new DateTimeZone($tzKey)))->format('g:i A') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-hint">All flight records, clearance stamps, and email dispatches use this timezone.</div>
                    </div>

                    <div class="grid grid-cols-2" style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
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

                        <div id="smtp-auth-fields" style="margin-top: 0.75rem; display: <?= $smtpAuth ? 'grid' : 'none' ?>; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
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

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-top: 1rem;">
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
                        <button type="submit" class="btn btn-primary">Save Settings</button>
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

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">System Error Log</h2>
            </div>
            <div class="card-body">
                <a style="width: 100%" class="btn btn-primary" href="errors.php">View Error Log</a>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
