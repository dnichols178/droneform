<?php
$pageTitle = 'Plan & Schedule Flight (Aloft Polygon Engine)';
$currentPage = 'create_flight';
require_once __DIR__ . '/includes/header.php';

$pdo = getDB();
$currentUser = getCurrentUser();
$isAdmin = ($currentUser && strtolower(trim($currentUser['role'])) === 'admin');
$err = '';

// Generate next flight plan number: FP-YY-001 with yearly reset
$twoDigitYear = date('y');
$yearPrefix = "FP-" . $twoDigitYear . "-";

$countStmt = $pdo->prepare("SELECT flight_number FROM flights WHERE flight_number LIKE ? ORDER BY flight_number DESC LIMIT 1");
$countStmt->execute([$yearPrefix . '%']);
$lastFlight = $countStmt->fetchColumn();

if ($lastFlight) {
    $parts = explode('-', $lastFlight);
    $lastSeq = (int)end($parts);
    $nextSeq = $lastSeq + 1;
} else {
    $nextSeq = 1;
}
$suggestedFlightNumber = sprintf("FP-%s-%03d", $twoDigitYear, $nextSeq);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flightNumber = trim($_POST['flight_number'] ?? '');
    $workPackage = trim($_POST['work_package'] ?? '');
    $facilityName = trim($_POST['facility_name'] ?? '');
    
    // RPIC Security Enforcement: Only administrators can select a different pilot
    if ($isAdmin) {
        $pilotId = (int)($_POST['pilot_id'] ?? $currentUser['id']);
    } else {
        $pilotId = (int)$currentUser['id'];
    }

    // Capture the user who created/entered the flight plan
    $createdBy = (int)$currentUser['id'];

    $flightDate = $_POST['flight_date'] ?? '';
    $startTime = $_POST['start_time'] ?? '';
    $endTime = $_POST['end_time'] ?? '';
    $maxAltitude = (int)($_POST['max_altitude'] ?? 0);
    $airspaceClass = strtoupper(trim($_POST['airspace_class'] ?? 'G'));
    $latitude = (float)($_POST['latitude'] ?? 37.115191);
    $longitude = (float)($_POST['longitude'] ?? -88.811224);
    $polygonGeoJson = trim($_POST['polygon_geojson'] ?? '');
    $operationalAcres = (float)($_POST['operational_acres'] ?? 0.0);
    $aircraftModel = trim($_POST['aircraft_model'] ?? '');
    $missionPurpose = trim($_POST['mission_purpose'] ?? '');
    $additionalEmails = trim($_POST['additional_emails'] ?? '');

    // Mission Scope is required
    if (empty($workPackage) || empty($facilityName) || empty($missionPurpose)) {
        $err = "Work package, target facility, and mission scope are all required fields.";
    } elseif ($maxAltitude > 400 && $airspaceClass === 'G') {
        $err = "Flight exceeds 400ft AGL Part 107 ceiling for uncontrolled airspace.";
    } else {
        $laancRequired = ($airspaceClass !== 'G') ? 1 : 0;
        $laancStatus = $laancRequired ? 'PENDING' : 'NOT_REQUIRED';

        try {
            $stmt = $pdo->prepare("
                INSERT INTO flights (
                    flight_number, pilot_id, work_package, facility_name, flight_date,
                    start_time, end_time, max_altitude, latitude, longitude, polygon_geojson,
                    operational_acres, aircraft_model, airspace_class, laanc_required,
                    laanc_status, additional_emails, mission_purpose, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $flightNumber, $pilotId, $workPackage, $facilityName, $flightDate,
                $startTime, $endTime, $maxAltitude, $latitude, $longitude, $polygonGeoJson,
                $operationalAcres, $aircraftModel, $airspaceClass, $laancRequired,
                $laancStatus, $additionalEmails, $missionPurpose, $createdBy
            ]);

            $newFlightId = (int)$pdo->lastInsertId();
            header("Location: flight_details.php?id=$newFlightId&created=1");
            exit;
        } catch (PDOException $e) {
            $err = "Failed to schedule flight: " . $e->getMessage();
        }
    }
}

$pilots = $isAdmin ? $pdo->query("SELECT * FROM users WHERE is_active = 1 ORDER BY full_name ASC")->fetchAll() : [];
?>

<?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<form method="POST" action="flight_create.php">
    <div class="flight-planner-split">
        <!-- LEFT COLUMN: FLIGHT PARAMETERS (1/3 of the page) -->
        <div class="flight-planner-left">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Flight Parameters</h2>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="flight_number">Flight Plan #</label>
                        <input type="text" id="flight_number" name="flight_number" class="form-control" value="<?= htmlspecialchars($suggestedFlightNumber) ?>" readonly required>
                    </div>

                    <div class="form-group">
                        <label for="work_package">Work Package</label>
                        <input type="text" id="work_package" name="work_package" class="form-control" placeholder="e.g. WP-2026-Tower-Inspection" value="<?= htmlspecialchars($_POST['work_package'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="facility_name">Facility</label>
                        <input type="text" id="facility_name" name="facility_name" class="form-control" placeholder="e.g. Barkley West Solar Array" value="<?= htmlspecialchars($_POST['facility_name'] ?? '') ?>" required>
                    </div>

                    <!-- RPIC FIELD: DROPDOWN FOR ADMINS, LOCKED READ-ONLY FOR NON-ADMIN PILOTS -->
                    <div class="form-group">
                        <label for="pilot_id">Remote Pilot in Command (RPIC)</label>
                        <?php if ($isAdmin): ?>
                            <select name="pilot_id" id="pilot_id" class="form-control" required>
                                <?php foreach ($pilots as $p): ?>
                                    <option value="<?= $p['id'] ?>" <?= ($p['id'] == ($_POST['pilot_id'] ?? $currentUser['id'])) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($p['full_name']) ?> (Cert: <?= htmlspecialchars($p['part107_cert_num'] ?: 'None') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-hint">Administrator privilege: pilot assignment enabled.</div>
                        <?php else: ?>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($currentUser['full_name']) ?> (Cert: <?= htmlspecialchars($currentUser['part107_cert_num'] ?: 'None') ?>)" readonly style="background-color: #f1f5f9; cursor: not-allowed;">
                            <input type="hidden" name="pilot_id" value="<?= (int)$currentUser['id'] ?>">
                            <div class="form-hint">Automatically assigned to currently authenticated Remote Pilot.</div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label>Airspace Classification</label>
                        <div class="auto-airspace-box">
                            <div class="auto-airspace-status">
                                <span class="radar-dot"></span>
                                <span id="display-airspace-badge" class="badge badge-warning">Class D (Barkley Regional)</span>
                            </div>
                            <div id="display-airspace-desc" class="auto-airspace-meta">
                                Auto-detected from polygon centroid [37.115191, -88.811224]
                            </div>
                        </div>
                        <input type="hidden" name="airspace_class" id="airspace_class" value="D">
                    </div>

                    <div class="form-group">
                        <label for="max_altitude">Planned Max Altitude (ft AGL)</label>
                        <input type="number" id="max_altitude" name="max_altitude" class="form-control" value="<?= htmlspecialchars($_POST['max_altitude'] ?? '200') ?>" min="10" max="400" required>
                    </div>

                    <div class="form-group">
                        <label for="flight_date">Flight Date</label>
                        <input type="date" id="flight_date" name="flight_date" class="form-control" value="<?= htmlspecialchars($_POST['flight_date'] ?? date('Y-m-d')) ?>" required>
                    </div>

                    <div class="grid grid-cols-2">
                        <div class="form-group">
                            <label for="start_time">Start Time</label>
                            <input type="time" id="start_time" name="start_time" class="form-control" value="<?= htmlspecialchars($_POST['start_time'] ?? '09:00') ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="end_time">End Time</label>
                            <input type="time" id="end_time" name="end_time" class="form-control" value="<?= htmlspecialchars($_POST['end_time'] ?? '11:30') ?>" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="aircraft_model">UAS Aircraft Model</label>
                        <input type="text" id="aircraft_model" name="aircraft_model" class="form-control" placeholder="e.g. Skydio X10D" value="<?= htmlspecialchars($_POST['aircraft_model'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="mission_purpose">Mission Scope <span style="color:var(--danger); font-weight:bold;">*</span></label>
                        <textarea id="mission_purpose" name="mission_purpose" class="form-control" placeholder="Describe operational mission objectives, flight profile, visual observer setup, safety perimeter..." required><?= htmlspecialchars($_POST['mission_purpose'] ?? '') ?></textarea>
                        <div class="form-hint">Required for FAA Part 107 operational safety and site compliance.</div>
                    </div>

                    <div class="form-group">
                        <label for="additional_emails">Additional Notification Emails (Optional)</label>
                        <input type="text" id="additional_emails" name="additional_emails" class="form-control" placeholder="manager@site.com, escort@security.org" value="<?= htmlspecialchars($_POST['additional_emails'] ?? '') ?>">
                        <div class="form-hint">Mandatory procedural contacts are automatically included when authorized.</div>
                    </div>

                    <div id="laanc-status-indicator" class="alert alert-warning" style="margin-top: 1rem;">
                        Class D Controlled Airspace: FAA LAANC authorization required via Aloft Air Control.
                    </div>

                    <div style="margin-top: 1.5rem; text-align: right;">
                        <a href="dashboard.php" class="btn btn-outline">Cancel</a>
                        <button type="submit" class="btn btn-primary" style="margin-left: 0.5rem;">Save Flight Plan</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: ALOFT-STYLE POLYGON MAP (2/3 of the page) -->
        <div class="flight-planner-right">
            <div class="map-cockpit" style="background: #0f172a; border-radius: 8px; overflow: hidden;">
                <div class="map-toolbar">
                    <div class="map-toolbar-left">
                        <button type="button" id="btn-draw-polygon" class="btn btn-aloft btn-sm">  Draw Flight Polygon</button>
                        <button type="button" id="btn-clear-polygon" class="btn btn-outline btn-sm" style="color: #cbd5e1; border-color: #475569;">Reset</button>
                        <span id="polygon-draw-status" class="map-mode-indicator">Define mission airspace</span>
                    </div>
                </div>
                <div id="flight-polygon-map" 
                     class="planner-map-container"
                     data-interactive="true" 
                     data-lat="37.115191" 
                     data-lng="-88.811224">
                </div>
                <div class="map-telemetry-bar">
                    <div class="map-telemetry-item">Footprint: <strong id="display-acres">0.00</strong> Acres</div>
                    <div class="map-telemetry-item">Centroid: <strong id="display-center">37.115191, -88.811224</strong></div>
                    <div class="map-telemetry-item">Detected Airspace: <strong id="display-map-airspace" style="color:#f59e0b;">Class D</strong></div>
                </div>
            </div>

            <input type="hidden" name="polygon_geojson" id="polygon_geojson">
            <input type="hidden" name="latitude" id="latitude" value="37.115191">
            <input type="hidden" name="longitude" id="longitude" value="-88.811224">
            <input type="hidden" name="operational_acres" id="operational_acres" value="0.0">
        </div>
    </div>
</form>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
