<?php
$pageTitle = 'Flight Details & Airspace Clearance';
$currentPage = 'flights';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/laanc.php';

$pdo = getDB();
$flightId = (int)($_GET['id'] ?? 0);
$actionMsg = '';
$actionError = '';
$keepOnStep3 = false;

// Handle background auto-save of client-captured satellite snapshot
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_map_snapshot') {
    $rawSnap = trim($_POST['map_image_base64'] ?? '');
    if (!empty($rawSnap)) {
        $imgBase64 = str_starts_with($rawSnap, 'data:') ? $rawSnap : ('data:image/jpeg;base64,' . $rawSnap);
        // Overwrite unconditionally so corrupted or black snapshots are replaced
        $pdo->prepare("UPDATE flights SET map_image_base64 = ? WHERE id = ?")->execute([$imgBase64, $flightId]);
    }
    echo json_encode(['status' => 'ok']);
    exit;
}

// Fetch Flight Details First to Validate Permissions
$stmt = $pdo->prepare("
    SELECT f.*, u.full_name AS pilot_name, u.phone AS pilot_phone, u.email AS pilot_email, u.username AS pilot_username
    FROM flights f
    JOIN users u ON f.pilot_id = u.id
    WHERE f.id = ?
");
$stmt->execute([$flightId]);
$flight = $stmt->fetch();

if (!$flight) {
    echo "<div class='alert alert-danger'>Flight record not found.</div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Establish pilot / creator display name and authorization check
$pilotDisplayName = !empty($flight['pilot_username']) ? $flight['pilot_username'] : (!empty($flight['pilot_name']) ? $flight['pilot_name'] : 'The initiating pilot');
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$currentUserRole = $_SESSION['role'] ?? '';
$isOwner = ($currentUserId > 0) && ($currentUserId === (int)$flight['pilot_id'] || $currentUserId === (int)($flight['created_by'] ?? 0));
$isAdminUser = ($currentUserRole === 'admin' || (function_exists('isAdmin') && isAdmin()));
$canManage = ($isOwner || $isAdminUser);

// Handle POST actions with ownership / admin verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireLogin();
    $action = $_POST['action'] ?? '';

    if (!$canManage) {
        $actionError = "Access denied: Only {$pilotDisplayName} or an administrator can modify or dispatch this flight plan.";
    } else {
        // CANCEL & PURGE FLIGHT (Step 2 or Step 3)
        if ($action === 'cancel_flight') {
            $pdo->prepare("DELETE FROM notification_logs WHERE flight_id = ?")->execute([$flightId]);
            $pdo->prepare("DELETE FROM flight_logs WHERE flight_id = ?")->execute([$flightId]);
            $pdo->prepare("DELETE FROM flights WHERE id = ?")->execute([$flightId]);
            header("Location: dashboard.php?msg=flight_cancelled");
            exit;
        }

        // STEP 1: Record LAANC code
        if ($action === 'record_laanc') {
            $code = strtoupper(trim($_POST['laanc_auth_number'] ?? ''));
            if (!empty($code)) {
                $checkStmt = $pdo->prepare("SELECT flight_number FROM flights WHERE UPPER(laanc_auth_number) = ? AND id != ?");
                $checkStmt->execute([$code, $flightId]);
                $existing = $checkStmt->fetch();

                if ($existing) {
                    $actionError = "Duplicate LAANC Code: Authorization number '{$code}' has already been registered on flight plan {$existing['flight_number']}. LAANC authorization numbers must be unique.";
                } else {
                    $localNow = date('Y-m-d H:i:s');
                    $stmt = $pdo->prepare("
                        UPDATE flights SET 
                             laanc_auth_number = ?, 
                             laanc_status = 'APPROVED', 
                             laanc_authorized_at = ? 
                           WHERE id = ?
                    ");
                    $stmt->execute([$code, $localNow, $flightId]);
                    header("Location: flight_details.php?id={$flightId}&msg=laanc_saved");
                    exit;
                }
            } else {
                $actionError = 'Please enter a valid FAA LAANC authorization number.';
            }
        }

        // STEP 1 (Class G confirmation)
        if ($action === 'confirm_class_g') {
            $stmt = $pdo->prepare("UPDATE flights SET laanc_status = 'NOT_REQUIRED' WHERE id = ?");
            $stmt->execute([$flightId]);
            header("Location: flight_details.php?id={$flightId}&msg=class_g_confirmed");
            exit;
        }

        // STEP 3: Dispatch final notification email
        if ($action === 'send_final_notification') {
            $smtpError = null;
            if (sendFlightNotification($flightId, [], $smtpError)) {
                header("Location: flight_details.php?id={$flightId}&msg=notification_sent");
                exit;
            } else {
                $actionError = 'Failed to transmit notification via SMTP relay: ' . ($smtpError ?: 'Check server configuration and recipients.');
                $keepOnStep3 = true;
            }
        }

        // Secondary resend
        if ($action === 'resend_notification') {
            $extra = array_filter(array_map('trim', explode(',', $_POST['extra_emails'] ?? '')));
            $smtpError = null;
            if (sendFlightNotification($flightId, $extra, $smtpError)) {
                $actionMsg = 'Flight notification emails successfully re-dispatched.';
            } else {
                $actionError = 'Failed to re-dispatch notification: ' . ($smtpError ?: 'Check server configuration.');
            }
        }
    }
}

// Fetch notification history
$notifStmt = $pdo->prepare("SELECT * FROM notification_logs WHERE flight_id = ? ORDER BY sent_at DESC");
$notifStmt->execute([$flightId]);
$notifications = $notifStmt->fetchAll();
$hasSentNotifications = !empty($notifications);

// Fetch recipient list
$mandatoryRecipients = $pdo->query("SELECT email FROM distribution_recipients ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
$additionalEmails = !empty($flight['additional_emails']) ? array_filter(array_map('trim', explode(',', $flight['additional_emails']))) : [];
$allRecipients = array_values(array_unique(array_merge($mandatoryRecipients, $additionalEmails)));

// Determine workflow step
$currentStep = '1';
$laancIsComplete = (!empty($flight['laanc_auth_number']) || $flight['airspace_class'] === 'G');

if (!$laancIsComplete) {
    $currentStep = '1';
} elseif ($keepOnStep3 || (!$hasSentNotifications && isset($_GET['step']) && $_GET['step'] === 'review')) {
    $currentStep = '3';
} elseif (!$hasSentNotifications) {
    $currentStep = '2';
} else {
    $currentStep = 'complete';
}

$aloftWebUrl = "https://air.aloft.ai";

// Flash messages
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'laanc_saved') $actionMsg = "LAANC authorization number successfully saved! Review the draft notification below.";
    if ($_GET['msg'] === 'class_g_confirmed') $actionMsg = "Class G uncontrolled airspace verified. Review the draft notification below.";
    if ($_GET['msg'] === 'notification_sent') $actionMsg = "Flight plan notification has been sent to recipients!";
}

// Post-flight log status & data
$logStmt = $pdo->prepare("SELECT * FROM flight_logs WHERE flight_id = ?");
$logStmt->execute([$flightId]);
$flightLog = $logStmt->fetch();
?>

<?php if ($actionMsg): ?><div class="alert alert-success"><?= htmlspecialchars($actionMsg) ?></div><?php endif; ?>
<?php if ($actionError): ?><div class="alert alert-danger"><?= htmlspecialchars($actionError) ?></div><?php endif; ?>

<!-- WORKFLOW STEPPER BAR (Removed once post-flight log is completed) -->
<?php if (!$flightLog): ?>
    <div class="workflow-stepper">
        <div class="workflow-step <?= $currentStep === '1' ? 'active' : ($laancIsComplete ? 'completed' : '') ?>">
            <span class="step-num"><?= $laancIsComplete ? '✓' : '1' ?></span>
            <span>1. LAANC Clearance</span>
        </div>
        <div class="workflow-step <?= $currentStep === '2' ? 'active' : ($hasSentNotifications || $currentStep === '3' ? 'completed' : '') ?>">
            <span class="step-num"><?= ($hasSentNotifications || $currentStep === '3') ? '✓' : '2' ?></span>
            <span>2. Draft Summary</span>
        </div>
        <div class="workflow-step <?= $currentStep === '3' ? 'active' : ($hasSentNotifications ? 'completed' : '') ?>">
            <span class="step-num"><?= $hasSentNotifications ? '✓' : '3' ?></span>
            <span>3. Email Review &amp; Send</span>
        </div>
        <div class="workflow-step <?= $currentStep === 'complete' ? 'completed' : '' ?>">
            <span class="step-num"><?= $currentStep === 'complete' ? '✓' : '4' ?></span>
            <span>Flight Plan Active</span>
        </div>
    </div>
<?php endif; ?>

<?php if ($currentStep === '3'): ?>
    <!-- STEP 3: FULL WIDTH OUTLOOK EMAIL REVIEW -->
    <?php
    $previewFrom = "FRNP Drone Flight Operations <" . getSystemSetting('mail_from', 'flight-ops@uas-part107.com') . ">";
    $previewTo = implode(', ', $allRecipients);
    $previewSubject = "FRNP Flight Notification " . $flight['flight_number'];
    // Pass direct base64 image representation untouched
    $previewHtml = buildFlightNotificationHtml($flight, $allRecipients);
    ?>
    <div class="card" style="border-top: 4px solid var(--success); max-width: 960px; margin: 0 auto 2rem auto;">
        <div class="card-header">
            <h2 class="card-title">Step 3: Review Notification Email (Outlook Layout)</h2>
            <a href="flight_details.php?id=<?= $flight['id'] ?>" class="btn btn-outline btn-sm">← Back to Draft</a>
        </div>
        <div class="card-body">
            <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1rem;">
                This preview renders the Microsoft Outlook-compatible HTML email that will be transmitted to all required personnel:
            </p>
            <div class="email-preview-card" style="margin-bottom: 1.5rem;">
                <div class="email-preview-meta">
                    <div class="email-meta-row">
                        <span class="email-meta-label">From:</span>
                        <span class="email-meta-value"><?= htmlspecialchars($previewFrom) ?></span>
                    </div>
                    <div class="email-meta-row">
                        <span class="email-meta-label">To:</span>
                        <span class="email-meta-value"><?= htmlspecialchars($previewTo) ?></span>
                    </div>
                    <div class="email-meta-row">
                        <span class="email-meta-label">Subject:</span>
                        <span class="email-meta-value"><strong><?= htmlspecialchars($previewSubject) ?></strong></span>
                    </div>
                </div>
                <div style="background-color: #f0f4f8; padding: 20px; overflow-x: auto;">
                    <?= $previewHtml ?>
                </div>
            </div>

            <?php if ($canManage): ?>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <form method="POST" action="flight_details.php?id=<?= $flight['id'] ?>" onsubmit="return confirm('Cancel and delete this flight plan? All flight data will be permanently purged.');">
                        <input type="hidden" name="action" value="cancel_flight">
                        <button type="submit" class="btn btn-outline btn-sm" style="color: var(--danger); border-color: var(--danger); padding:15px;">
                            X Cancel &amp; Delete Flight
                        </button>
                    </form>
                    <form method="POST" action="flight_details.php?id=<?= $flight['id'] ?>">
                        <input type="hidden" name="action" value="send_final_notification">
                        <button type="submit" class="btn btn-success" style="font-size: 1.05rem; padding: 0.75rem 1.75rem;">
                            Send Notification
                        </button>
                    </form>
                </div>
            <?php elseif (!isLoggedIn()): ?>
                <div class="alert alert-info" style="margin-bottom: 0;">
                    <a href="login.php">Log in</a> to send notification dispatches or manage this flight plan.
                </div>
            <?php else: ?>
                <div class="alert alert-info" style="margin-bottom: 0;">
                    <strong><?= htmlspecialchars($pilotDisplayName) ?></strong> has not transmitted the flight notification. Only the initiating pilot or an administrator can dispatch this flight plan.
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>
    <!-- 2-COLUMN LAYOUT: FLIGHT INFO ON LEFT, MAP ON RIGHT -->
    <div class="flight-details-split">
        <div class="flight-details-left">
            <!-- STEP 1: LAANC AUTHORIZATION INPUT -->
            <?php if ($currentStep === '1'): ?>
                <div class="card" style="border-top: 4px solid var(--warning);">
                    <div class="card-header">
                        <h2 class="card-title">Step 1: Complete LAANC Airspace Authorization</h2>
                        <span class="badge badge-warning">LAANC Required (Class <?= htmlspecialchars($flight['airspace_class']) ?>)</span>
                    </div>
                    <div class="card-body">
                        <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1rem;">
                            This flight requires an approved FAA LAANC authorization before notification dispatches can be released.
                        </p>
                        <div style="background: #f8fafc; border: 1px solid var(--border-light); border-radius: 6px; padding: 1rem; margin-bottom: 1.25rem;">
                            <div style="font-size: 0.85rem; font-weight: 600; margin-bottom: 0.5rem;">Launch Aloft Air Control:</div>
                            <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.75rem;">
                                <a href="<?= htmlspecialchars($aloftWebUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-aloft btn-sm">
                                    ↗ Open Aloft Web App
                                </a>
                                <button type="button" class="btn btn-outline btn-sm" 
                                        onclick="navigator.clipboard.writeText('<?= $flight['latitude'] ?>, <?= $flight['longitude'] ?>'); alert('Copied coordinates: <?= $flight['latitude'] ?>, <?= $flight['longitude'] ?>');">
                                    Copy Centroid (<?= $flight['latitude'] ?>, <?= $flight['longitude'] ?>)
                                </button>
                            </div>
                            <div class="form-hint">
                                Paste coordinates into Aloft to request your instant FAA authorization.
                            </div>
                        </div>

                        <?php if ($canManage): ?>
                            <form method="POST" action="flight_details.php?id=<?= $flight['id'] ?>">
                                <input type="hidden" name="action" value="record_laanc">
                                <div class="form-group">
                                    <label for="laanc_auth_number">Enter Returned FAA LAANC Authorization #:</label>
                                    <input type="text" 
                                           name="laanc_auth_number" 
                                           id="laanc_auth_number" 
                                           class="form-control" 
                                           placeholder="e.g. FAA-LAANC-XXXXXX" 
                                           style="font-family: monospace; font-size: 1.1rem; text-transform: uppercase;" 
                                           required 
                                           autofocus>
                                    <div class="form-hint">Must be unique across all flight records.</div>
                                </div>
                                <button type="submit" class="btn btn-primary btn-block">Save Authorization &amp; Continue →</button>
                            </form>
                        <?php elseif (!isLoggedIn()): ?>
                            <div class="alert alert-info" style="margin-bottom: 0;">
                                <a href="login.php">Log in</a> to record LAANC authorization for this flight.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info" style="margin-bottom: 0;">
                                <?= htmlspecialchars($pilotDisplayName) ?> has not entered LAANC authorization.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h3 class="card-title">Flight Summary</h3></div>
                    <div class="card-body">
                        <table class="table">
                            <tr><th>Flight Plan #</th><td><strong><?= htmlspecialchars($flight['flight_number']) ?></strong></td></tr>
                            <tr><th>Work Control</th><td><?= htmlspecialchars($flight['work_package']) ?></td></tr>
                            <tr><th>Target Facility</th><td><?= htmlspecialchars($flight['facility_name']) ?></td></tr>
                            <tr><th>Mission Scope</th><td><?= htmlspecialchars($flight['mission_purpose']) ?></td></tr>
                            <?php if (!empty($flight['aircraft_model'])): ?>
                                <tr><th>Aircraft Model</th><td><?= htmlspecialchars($flight['aircraft_model']) ?></td></tr>
                            <?php endif; ?>
                            <?php if (!empty($flight['faa_reg_number'])): ?>
                                <tr>
                                    <th>FAA Drone Reg #</th>
                                    <td><code style="font-size:0.85rem;"><?= htmlspecialchars($flight['faa_reg_number']) ?></code><?= !empty($flight['faa_reg_expiration']) ? ' <small style="color:var(--text-muted);">(Exp: ' . htmlspecialchars($flight['faa_reg_expiration']) . ')</small>' : '' ?></td>
                                </tr>
                            <?php endif; ?>
                            <?php if (!empty($flight['payload'])): ?>
                                <tr><th>Payload</th><td><?= htmlspecialchars($flight['payload']) ?></td></tr>
                            <?php endif; ?>
                            <tr><th>Airspace</th><td>Class <?= htmlspecialchars($flight['airspace_class']) ?></td></tr>
                            <tr><th>Altitude</th><td><?= $flight['max_altitude'] ?> ft AGL</td></tr>
                            <tr><th>Date &amp; Time</th><td><?= $flight['flight_date'] ?> (<?= $flight['start_time'] ?> - <?= $flight['end_time'] ?> Local)</td></tr>
                        </table>
                    </div>
                </div>

            <!-- STEP 2: DRAFT NOTIFICATION INFORMATION SUMMARY -->
            <?php elseif ($currentStep === '2'): ?>
                <div class="card" style="border-top: 4px solid var(--primary);">
                    <div class="card-header">
                        <h2 class="card-title">Step 2: Draft Flight Notification Details</h2>
                        <span class="badge badge-success">LAANC Approved</span>
                    </div>
                    <div class="card-body">
                        <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1.25rem;">
                            Verify all operational details below. If you notice any errors, you may cancel to discard all data and start over.
                        </p>
                        <table class="table" style="margin-bottom: 1.5rem;">
                            <tr>
                                <th style="width: 35%;">Flight Plan #</th>
                                <td><strong><?= htmlspecialchars($flight['flight_number']) ?></strong></td>
                            </tr>
                            <tr>
                                <th>Work Control</th>
                                <td><?= htmlspecialchars($flight['work_package']) ?></td>
                            </tr>
                            <tr>
                                <th>Target Facility</th>
                                <td><?= htmlspecialchars($flight['facility_name']) ?></td>
                            </tr>
                            <tr>
                                <th>Mission Scope</th>
                                <td><?= nl2br(htmlspecialchars($flight['mission_purpose'])) ?></td>
                            </tr>
                            <tr>
                                <th>UAS Make &amp; Model</th>
                                <td><?= htmlspecialchars($flight['aircraft_model']) ?></td>
                            </tr>
                            <?php if (!empty($flight['faa_reg_number'])): ?>
                            <tr>
                                <th>FAA Drone Reg #</th>
                                <td><code style="font-size:0.85rem;"><?= htmlspecialchars($flight['faa_reg_number']) ?></code><?= !empty($flight['faa_reg_expiration']) ? ' <span style="color:var(--text-muted);">(Expires: ' . htmlspecialchars($flight['faa_reg_expiration']) . ')</span>' : '' ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($flight['payload'])): ?>
                            <tr>
                                <th>Payload Equipment</th>
                                <td><?= htmlspecialchars($flight['payload']) ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th>Airspace Classification</th>
                                <td>Class <?= htmlspecialchars($flight['airspace_class']) ?></td>
                            </tr>
                            <tr>
                                <th>FAA LAANC Authorization #</th>
                                <td><strong style="color: var(--success); font-family: monospace;"><?= htmlspecialchars($flight['laanc_auth_number'] ?: 'N/A (CLASS G)') ?></strong></td>
                            </tr>
                            <tr>
                                <th>Centroid Coordinates</th>
                                <td><?= $flight['latitude'] ?>, <?= $flight['longitude'] ?></td>
                            </tr>
                            <tr>
                                <th>Max Altitude</th>
                                <td><strong><?= $flight['max_altitude'] ?> ft AGL</strong></td>
                            </tr>
                            <tr>
                                <th>Scheduled Date &amp; Time</th>
                                <td><?= $flight['flight_date'] ?> (<?= $flight['start_time'] ?> - <?= $flight['end_time'] ?> Local)</td>
                            </tr>
                            <tr>
                                <th>Remote Pilot in Command</th>
                                <td><?= htmlspecialchars($flight['pilot_name']) ?></td>
                            </tr>
                            <tr>
                                <th style="vertical-align: top; padding-top: 0.85rem;">Recipients (<?= count($allRecipients) ?>)</th>
                                <td>
                                    <ul style="list-style: none; margin: 0; padding: 0;">
                                        <?php foreach ($allRecipients as $rEmail): ?>
                                            <li style="padding: 2px 0; font-family: monospace; font-size: 0.85rem; color: var(--text-primary);"><?= htmlspecialchars($rEmail) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </td>
                            </tr>
                        </table>

                        <?php if ($canManage): ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 1rem; border-top: 1px solid var(--border-light);">
                                <form method="POST" action="flight_details.php?id=<?= $flight['id'] ?>" onsubmit="return confirm('Are you sure you want to cancel and delete this flight plan? All entered mission parameters, coordinates, and LAANC records will be permanently deleted.');">
                                    <input type="hidden" name="action" value="cancel_flight">
                                    <button type="submit" class="btn btn-outline btn-sm" style="color: var(--danger); border-color: var(--danger); padding:12px;">
                                        X Cancel &amp; Delete Flight Plan
                                    </button>
                                </form>
                                <a href="flight_details.php?id=<?= $flight['id'] ?>&step=review" id="btn-proceed-review" class="btn btn-primary">
                                    Review and Send Notification →
                                </a>
                            </div>
                        <?php elseif (!isLoggedIn()): ?>
                            <div class="alert alert-info" style="margin-bottom: 0;">
                                <a href="login.php">Log in</a> to send notification dispatches or manage this flight plan.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info" style="margin-bottom: 0;">
                                <?= htmlspecialchars($pilotDisplayName) ?> has not completed notification review.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <!-- STAGE 4 (COMPLETE): NOTIFICATIONS SENT & MISSION ACTIVE -->
            <?php else: ?>
                <div class="card">
                    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                        <h2 class="card-title" style="margin-bottom: 0;">Flight Plan: <?= htmlspecialchars($flight['flight_number']) ?></h2>
                        <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                            <a href="flight_report.php?id=<?= $flight['id'] ?>" target="_blank" class="btn btn-outline btn-sm">
                                🖨️ Print / Generate PDF
                            </a>
                            <?php if (!$flightLog): ?>
                                <?php if ($canManage): ?>
                                    <a href="flight_log.php?flight_id=<?= $flight['id'] ?>" class="btn btn-success btn-sm">+ Complete Post-Flight Log</a>
                                <?php elseif (isLoggedIn()): ?>
                                    <span class="badge badge-warning" style="background-color: #f59e0b; color: #fff;">Awaiting Post-Flight Log (<?= htmlspecialchars($pilotDisplayName) ?>)</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge badge-success">POST-FLIGHT LOGGED</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- POST-FLIGHT LOG DATA (Rendered when completed) -->
                        <?php if ($flightLog): ?>
                            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 1.25rem; margin-bottom: 1.5rem;">
                                <h3 style="font-size: 1.05rem; color: #166534; margin-top: 0; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
                                    <span>✓</span> Post-Flight Operational Log
                                    <?php if (!empty($flightLog['created_at'])): ?>
                                        <small style="font-size: 0.8rem; font-weight: normal; color: #15803d; margin-left: auto;">
                                            Logged: <?= htmlspecialchars($flightLog['created_at']) ?>
                                        </small>
                                    <?php endif; ?>
                                </h3>
                                <table class="table" style="margin-bottom: 0; background: transparent;">
                                    <?php if (!empty($flightLog['actual_start_time']) || !empty($flightLog['actual_end_time'])): ?>
                                        <tr>
                                            <th style="width: 35%;">Actual Flight Time</th>
                                            <td>
                                                <?= htmlspecialchars($flightLog['actual_start_time'] ?? '') ?> 
                                                <?= (!empty($flightLog['actual_start_time']) && !empty($flightLog['actual_end_time'])) ? ' - ' : '' ?>
                                                <?= htmlspecialchars($flightLog['actual_end_time'] ?? '') ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <th style="width: 35%;">Flight Duration</th>
                                        <td><strong><?= htmlspecialchars((string)($flightLog['total_flight_minutes'] ?? '0')) ?> minutes</strong></td>
                                    </tr>
                                    <tr>
                                        <th>Peak Altitude</th>
                                        <td><?= htmlspecialchars((string)($flightLog['max_altitude_reached'] ?? $flight['max_altitude'])) ?> ft AGL</td>
                                    </tr>
                                    <?php if (isset($flightLog['battery_start_pct']) || isset($flightLog['battery_end_pct'])): ?>
                                        <tr>
                                            <th>Battery (Start / End)</th>
                                            <td>
                                                <?= htmlspecialchars((string)($flightLog['battery_start_pct'] ?? '--')) ?>% / 
                                                <?= htmlspecialchars((string)($flightLog['battery_end_pct'] ?? '--')) ?>%
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <th>Incidents / Malfunctions</th>
                                        <td>
                                            <?php if (!empty($flightLog['incident_occurred']) && !empty($flightLog['incident_description'])): ?>
                                                <span style="color: var(--danger); font-weight: 600;">
                                                    <?= nl2br(htmlspecialchars($flightLog['incident_description'])) ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #166534;">None reported</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>RPIC Notes</th>
                                        <td><?= !empty($flightLog['pilot_notes']) ? nl2br(htmlspecialchars($flightLog['pilot_notes'])) : '<span style="color:var(--text-muted);">None entered</span>' ?></td>
                                    </tr>
                                </table>
                            </div>
                        <?php endif; ?>

                        <!-- FLIGHT PLAN SPECIFICATIONS -->
                        <table class="table" style="margin-bottom: 1.5rem;">
                            <tr><th>Work Control</th><td><?= htmlspecialchars($flight['work_package']) ?></td></tr>
                            <tr><th>Facility</th><td><?= htmlspecialchars($flight['facility_name']) ?></td></tr>
                            <tr><th>Mission Scope</th><td><?= nl2br(htmlspecialchars($flight['mission_purpose'])) ?></td></tr>
                            <tr><th>Aircraft Model</th><td><?= htmlspecialchars($flight['aircraft_model']) ?></td></tr>
                            <?php if (!empty($flight['faa_reg_number'])): ?>
                                <tr>
                                    <th>FAA Drone Reg #</th>
                                    <td><code style="font-size:0.85rem;"><?= htmlspecialchars($flight['faa_reg_number']) ?></code><?= !empty($flight['faa_reg_expiration']) ? ' <small style="color:var(--text-muted);">(Exp: ' . htmlspecialchars($flight['faa_reg_expiration']) . ')</small>' : '' ?></td>
                                </tr>
                            <?php endif; ?>
                            <?php if (!empty($flight['payload'])): ?>
                                <tr><th>Payload Equipment</th><td><?= htmlspecialchars($flight['payload']) ?></td></tr>
                            <?php endif; ?>
                            <tr><th>Airspace</th><td>Class <?= htmlspecialchars($flight['airspace_class']) ?></td></tr>
                            <tr><th>LAANC Authorization #</th><td><strong style="color: var(--success); font-family: monospace;"><?= htmlspecialchars($flight['laanc_auth_number'] ?: 'NOT REQUIRED') ?></strong></td></tr>
                            <tr><th>Centroid</th><td><?= $flight['latitude'] ?>, <?= $flight['longitude'] ?></td></tr>
                            <tr><th>Max Altitude</th><td><strong><?= $flight['max_altitude'] ?> ft AGL</strong></td></tr>
                            <tr><th>Schedule Window</th><td><?= $flight['flight_date'] ?> (<?= $flight['start_time'] ?> - <?= $flight['end_time'] ?> Local)</td></tr>
                            <tr><th>Remote PIC</th><td><?= htmlspecialchars($flight['pilot_name']) ?></td></tr>
                        </table>

                        <!-- RE-DISPATCH NOTIFICATION EMAIL (Suppressed once post-flight log is completed; restricted to pilot/admin) -->
                        <?php if ($canManage && !$flightLog): ?>
                            <div style="background: #f8fafc; border: 1px solid var(--border-light); border-radius: 6px; padding: 1rem;">
                                <span style="font-size: 0.9rem; font-weight: 600;">Re-dispatch Notification Email:</span>
                                <form method="POST" action="flight_details.php?id=<?= $flight['id'] ?>" style="margin-top: 0.5rem;">
                                    <input type="hidden" name="action" value="resend_notification">
                                    <div class="form-group">
                                        <input type="text" name="extra_emails" class="form-control" placeholder="Add extra email (optional, comma-separated)">
                                    </div>
                                    <button type="submit" class="btn btn-outline btn-sm">Resend Notification Email</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT COLUMN: INTERACTIVE MAP & TELEMETRY -->
        <div class="flight-details-right">
            <div class="map-cockpit" style="background: #0f172a; border-radius: 8px; overflow: hidden; margin-bottom: 1.5rem;">
                <div class="map-toolbar">
                    <span class="map-mode-indicator">Flight Boundary: <?= $flight['operational_acres'] ?> Acres</span>
                    <span class="badge <?= $flight['airspace_class'] === 'G' ? 'badge-success' : 'badge-warning' ?>">
                        Class <?= htmlspecialchars($flight['airspace_class']) ?>
                    </span>
                </div>
                <div id="flight-polygon-map" 
                     class="planner-map-container"
                     style="height: 520px; min-height: 520px;"
                     data-interactive="false" 
                     data-lat="<?= $flight['latitude'] ?>" 
                     data-lng="<?= $flight['longitude'] ?>">
                </div>
                <input type="hidden" id="polygon_geojson" value="<?= htmlspecialchars($flight['polygon_geojson'] ?? '') ?>">
                <input type="hidden" id="map_image_base64" value="<?= htmlspecialchars($flight['map_image_base64'] ?? '') ?>">
                <input type="hidden" id="operational_acres" value="<?= htmlspecialchars($flight['operational_acres'] ?? '0') ?>">
                <input type="hidden" id="max_altitude" value="<?= htmlspecialchars($flight['max_altitude'] ?? '200') ?>">
                <input type="hidden" id="latitude" value="<?= htmlspecialchars($flight['latitude'] ?? '') ?>">
                <input type="hidden" id="longitude" value="<?= htmlspecialchars($flight['longitude'] ?? '') ?>">
                <input type="hidden" id="airspace_class" value="<?= htmlspecialchars($flight['airspace_class'] ?? 'G') ?>">
                <div class="map-telemetry-bar">
                    <div class="map-telemetry-item">Centroid: <strong><?= $flight['latitude'] ?>, <?= $flight['longitude'] ?></strong></div>
                    <div class="map-telemetry-item">Ceiling: <strong><?= $flight['max_altitude'] ?> ft</strong></div>
                </div>
            </div>

            <?php if (!empty($notifications)): ?>
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Transmission History</h3></div>
                    <div class="card-body">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Sent Time (Local)</th>
                                    <th>Recipients</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($notifications as $n): ?>
                                    <tr>
                                        <td><small><?= htmlspecialchars($n['sent_at']) ?></small></td>
                                        <td><small><?= htmlspecialchars($n['recipients']) ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<script>
// Auto-capture live satellite map and handle navigation sync
document.addEventListener('DOMContentLoaded', function () {
    const hiddenSnap = document.getElementById('map_image_base64');
    const reviewBtn = document.getElementById('btn-proceed-review');

    function syncSnapshot(callback) {
        if (typeof window.generateMapSnapshot === 'function') {
            window.generateMapSnapshot();
        }
        if (hiddenSnap && hiddenSnap.value && hiddenSnap.value.trim() !== '') {
            const fd = new FormData();
            fd.append('action', 'save_map_snapshot');
            fd.append('map_image_base64', hiddenSnap.value);
            fetch('flight_details.php?id=<?= $flightId ?>', { method: 'POST', body: fd })
                .then(() => { if (callback) callback(); })
                .catch(() => { if (callback) callback(); });
        } else {
            if (callback) callback();
        }
    }

    // Auto-capture once tiles load on Step 1 or Step 2
    if (hiddenSnap) {
        setTimeout(function() {
            syncSnapshot();
        }, 1200);
    }

    // Ensure snapshot is synced before navigating into Step 3
    if (reviewBtn) {
        reviewBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const targetUrl = this.href;
            syncSnapshot(function() {
                window.location.href = targetUrl;
            });
        });
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
