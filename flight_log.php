<?php
$pageTitle = 'Post Flight Log';
$currentPage = 'flights';
require_once __DIR__ . '/includes/header.php';

requireLogin();
$pdo = getDB();

$flightId = (int)($_GET['flight_id'] ?? 0);
$stmt = $pdo->prepare("SELECT f.*, u.full_name AS pilot_name FROM flights f JOIN users u ON f.pilot_id = u.id WHERE f.id = ?");
$stmt->execute([$flightId]);
$flight = $stmt->fetch();

if (!$flight) {
    echo "<div class='alert alert-danger'>Flight not found.</div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Handle Flight Cancellation
    if (isset($_POST['action_cancel'])) {
        try {
            $updateStmt = $pdo->prepare("UPDATE flights SET status = 'CANCELED' WHERE id = ?");
            $updateStmt->execute([$flightId]);

            header("Location: flights.php");
            exit;
        } catch (PDOException $e) {
            $err = "Error canceling flight: " . $e->getMessage();
        }
    } else {
        // 2. Handle Standard Post-Flight Log Submission
        $actualStart = $_POST['actual_start_time'] ?? '';
        $actualEnd = $_POST['actual_end_time'] ?? '';
        $totalMinutes = (int)($_POST['total_flight_minutes'] ?? 0);
        $maxAltitudeReached = (int)($_POST['max_altitude_reached'] ?? 0);
        $battStart = (int)($_POST['battery_start_pct'] ?? 100);
        $battEnd = (int)($_POST['battery_end_pct'] ?? 20);
        $pilotNotes = trim($_POST['pilot_notes'] ?? '');
        $incidentOccurred = isset($_POST['incident_occurred']) ? 1 : 0;
        $incidentDesc = trim($_POST['incident_description'] ?? '');

        if (empty($pilotNotes)) {
            $err = "Pilot notes are required to complete the post-flight log.";
        } else {
            try {
                $logInsert = $pdo->prepare("
                    INSERT INTO flight_logs (
                        flight_id, actual_start_time, actual_end_time, total_flight_minutes,
                        max_altitude_reached, battery_start_pct, battery_end_pct, pilot_notes,
                        incident_occurred, incident_description, logged_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $logInsert->execute([
                    $flightId, $actualStart, $actualEnd, $totalMinutes,
                    $maxAltitudeReached, $battStart, $battEnd, $pilotNotes,
                    $incidentOccurred, $incidentDesc, $_SESSION['user_id']
                ]);

                // Update flight status
                $pdo->prepare("UPDATE flights SET status = 'COMPLETED' WHERE id = ?")->execute([$flightId]);

                header("Location: flight_details.php?id=$flightId");
                exit;
            } catch (PDOException $e) {
                $err = "Error recording log: " . $e->getMessage();
            }
        }
    }
}
?>

<?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Post-Flight Debrief &amp; Telemetry Log: <?= htmlspecialchars($flight['flight_number']) ?></h2>
        <form method="POST" style="display:inline; margin: 0;">
            <button type="submit" 
                    name="action_cancel" 
                    value="1" 
                    formnovalidate 
                    class="btn btn-outline" 
                    style="color: var(--danger); border-color: var(--danger);" 
                    onclick="return confirm('Are you sure you want to mark this flight as Canceled? The flight plan will remain saved, but no post-flight telemetry will be recorded.');">
                Flight Canceled
            </button>
        </form>
    </div>
    <div class="card-body">
        <form method="POST">
            <div class="grid grid-cols-3">
                <div class="form-group">
                    <label for="actual_start_time">Actual Wheels-Up Time</label>
                    <input type="time" name="actual_start_time" id="actual_start_time" class="form-control" value="<?= htmlspecialchars($flight['start_time']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="actual_end_time">Actual Wheels-Down Time</label>
                    <input type="time" name="actual_end_time" id="actual_end_time" class="form-control" value="<?= htmlspecialchars($flight['end_time']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="total_flight_minutes">Total Airtime (Minutes)</label>
                    <input type="number" name="total_flight_minutes" id="total_flight_minutes" class="form-control" min="1" max="600" value="35" required>
                </div>
            </div>

            <div class="grid grid-cols-3">
                <div class="form-group">
                    <label for="max_altitude_reached">Max Altitude Reached (ft AGL)</label>
                    <input type="number" name="max_altitude_reached" id="max_altitude_reached" class="form-control" value="<?= htmlspecialchars($flight['max_altitude']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="battery_start_pct">Initial Battery Charge (%)</label>
                    <input type="number" name="battery_start_pct" id="battery_start_pct" class="form-control" min="1" max="100" value="100" required>
                </div>
                <div class="form-group">
                    <label for="battery_end_pct">Landing Battery Charge (%)</label>
                    <input type="number" name="battery_end_pct" id="battery_end_pct" class="form-control" min="1" max="100" value="28" required>
                </div>
            </div>

            <div class="form-group">
                <label for="pilot_notes">Pilot Remarks, Airframe Check, Weather/Wind Details <span style="color: var(--danger);">*</span></label>
                <textarea name="pilot_notes" id="pilot_notes" class="form-control" placeholder="Observations, visual observer coordination, KP index, wind gusts..." required><?= htmlspecialchars($_POST['pilot_notes'] ?? '') ?></textarea>
            </div>

            <div class="card" style="border: 1px solid var(--danger); padding: 1.25rem; background: #fff5f5;">
                <h3 style="color: var(--danger); margin-bottom: 0.5rem;">Part 107 Mandatory Incident Tracking (14 CFR § 107.9)</h3>
                <p class="form-hint" style="margin-bottom: 1rem;">
                    Part 107 requires reporting to the FAA within 10 calendar days any operation resulting in serious injury, loss of consciousness, or property damage over $500.
                </p>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="incident_occurred" value="1" <?= isset($_POST['incident_occurred']) ? 'checked' : '' ?>>
                        An incident, flyaway, structural collision, or emergency occurred during this flight
                    </label>
                </div>
                <div class="form-group">
                    <label for="incident_description">Incident Assessment &amp; Corrective Action</label>
                    <textarea name="incident_description" id="incident_description" class="form-control" placeholder="Describe damage, property involvement, or injury if applicable..."><?= htmlspecialchars($_POST['incident_description'] ?? '') ?></textarea>
                </div>
            </div>

            <div style="margin-top: 1.5rem; text-align: right;">
                <div>
                    <a href="flight_details.php?id=<?= $flightId ?>" class="btn btn-outline">Back</a>
                    <button type="submit" class="btn btn-primary" style="margin-left: 0.5rem;">Commit Post Flight Record</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
