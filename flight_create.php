<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

requireLogin();
$pdo = getDB();
$currentUser = getCurrentUser();
$isAdmin = ($currentUser && strtolower(trim($currentUser['role'])) === 'admin');

function getFiscalYearTwoDigit(?string $dateStr = null): string {
    $ts = !empty($dateStr) ? strtotime($dateStr) : time();
    if (!$ts) {
        $ts = time();
    }
    $month = (int)date('n', $ts);
    $year  = (int)date('Y', $ts);
    $fiscalYear = ($month >= 10) ? ($year + 1) : $year;
    return substr((string)$fiscalYear, -2);
}

function generateNextFlightNumber(PDO $pdo, ?string $flightDate = null): string {
    $twoDigitFY = getFiscalYearTwoDigit($flightDate);
    $yearPrefix = "FP-" . $twoDigitFY . "-";

    $countStmt = $pdo->prepare("SELECT flight_number FROM flights WHERE flight_number LIKE ? ORDER BY flight_number DESC LIMIT 1");
    $countStmt->execute([$yearPrefix . '%']);
    $lastFlight = $countStmt->fetchColumn();

    if ($lastFlight) {
        $parts = explode('-', $lastFlight);
        $lastSeqStr = end($parts);
        $lastSeq = (int)$lastSeqStr;
        $nextSeq = $lastSeq + 1;
        $padLen = max(3, strlen($lastSeqStr));
        return sprintf("FP-%s-%0" . $padLen . "d", $twoDigitFY, $nextSeq);
    } else {
        return sprintf("FP-%s-001", $twoDigitFY);
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'get_flight_number') {
    header('Content-Type: application/json');
    $date = $_GET['flight_date'] ?? date('Y-m-d');
    echo json_encode(['flight_number' => generateNextFlightNumber($pdo, $date)]);
    exit;
}

$pageTitle = 'Plan & Schedule Flight (Aloft Polygon Engine)';
$currentPage = 'create_flight';
require_once __DIR__ . '/includes/header.php';

$err = '';
$defaultFlightDate = $_POST['flight_date'] ?? date('Y-m-d');
$suggestedFlightNumber = generateNextFlightNumber($pdo, $defaultFlightDate);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $workPackage = trim($_POST['work_package'] ?? '');
    $facilityName = trim($_POST['facility_name'] ?? '');
    
    if ($isAdmin) {
        $pilotId = (int)($_POST['pilot_id'] ?? $currentUser['id']);
    } else {
        $pilotId = (int)$currentUser['id'];
    }

    $createdBy = (int)$currentUser['id'];
    $flightDate = $_POST['flight_date'] ?? date('Y-m-d');
    $flightNumber = generateNextFlightNumber($pdo, $flightDate);

    $startTime = $_POST['start_time'] ?? '';
    $endTime = $_POST['end_time'] ?? '';
    $maxAltitude = (int)($_POST['max_altitude'] ?? 0);
    $airspaceClass = strtoupper(trim($_POST['airspace_class'] ?? 'G'));
    $latitude = (float)($_POST['latitude'] ?? 37.115191);
    $longitude = (float)($_POST['longitude'] ?? -88.811224);
    $polygonGeoJson = trim($_POST['polygon_geojson'] ?? '');
    $operationalAcres = (float)($_POST['operational_acres'] ?? 0.0);
    $mapImageBase64 = trim($_POST['map_image_base64'] ?? '');

    $droneSelect = trim($_POST['drone_select'] ?? '');
    if ($droneSelect === 'other') {
        $aircraftModel = trim($_POST['custom_drone_model'] ?? '');
        $faaRegNumber = trim($_POST['custom_faa_reg_number'] ?? '');
        $faaRegExp = trim($_POST['custom_faa_reg_expiration'] ?? '');
    } else {
        $aircraftModel = trim($_POST['aircraft_model'] ?? '');
        $faaRegNumber = trim($_POST['faa_reg_number'] ?? '');
        $faaRegExp = trim($_POST['faa_reg_expiration'] ?? '');
    }

    $payload = trim($_POST['payload'] ?? '');
    $missionPurpose = trim($_POST['mission_purpose'] ?? '');
    $additionalEmails = trim($_POST['additional_emails'] ?? '');

    if (empty($workPackage) || empty($facilityName) || empty($missionPurpose) || empty($aircraftModel)) {
        $err = "Work package, target facility, aircraft model, and mission scope are required fields.";
    } elseif (empty($polygonGeoJson)) {
        $err = "Operational flight polygon is required. Please define the mission boundary on the map.";
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
                    operational_acres, aircraft_model, payload, faa_reg_number, faa_reg_expiration,
                    map_image_base64, airspace_class, laanc_required, laanc_status, additional_emails, mission_purpose, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $flightNumber, $pilotId, $workPackage, $facilityName, $flightDate,
                $startTime, $endTime, $maxAltitude, $latitude, $longitude, $polygonGeoJson,
                $operationalAcres, $aircraftModel, $payload, $faaRegNumber, $faaRegExp,
                $mapImageBase64, $airspaceClass, $laancRequired, $laancStatus, $additionalEmails, $missionPurpose, $createdBy
            ]);

            $newFlightId = (int)$pdo->lastInsertId();
            header("Location: flight_details.php?id=$newFlightId&created=1");
            exit;
        } catch (PDOException $e) {
            $err = "Failed to schedule flight: " . $e->getMessage();
        }
    }
}

$pilots = $isAdmin ? $pdo->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name ASC")->fetchAll() : [];
$prepopulatedDrones = $pdo->query("SELECT * FROM drones ORDER BY model_name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    .flight-planner-split {
        display: flex;
        gap: 1.5rem;
        transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        width: 100%;
        align-items: flex-start;
    }
    
    .flight-planner-left {
        flex: 0 0 calc(33.333% - 0.75rem);
        max-width: calc(33.333% - 0.75rem);
        transition: flex 0.35s ease, max-width 0.35s ease;
    }
    
    .flight-planner-right {
        flex: 0 0 calc(66.666% - 0.75rem);
        max-width: calc(66.666% - 0.75rem);
        transition: flex 0.35s ease, max-width 0.35s ease;
    }

    .flight-planner-split.layout-balanced .flight-planner-left {
        flex: 0 0 calc(50% - 0.75rem);
        max-width: calc(50% - 0.75rem);
    }

    .flight-planner-split.layout-balanced .flight-planner-right {
        flex: 0 0 calc(50% - 0.75rem);
        max-width: calc(50% - 0.75rem);
    }

    .step-section {
        display: block;
    }

    .step-section.is-hidden {
        display: none;
    }

    .step-indicator {
        font-size: 0.75rem;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: #64748b;
        margin-bottom: 0.5rem;
        font-weight: 600;
    }

    .grid-cols-2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
    }

    .grid-cols-3 {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 0.75rem;
    }

    .polygon-required-warning {
        display: none;
        margin-bottom: 1rem;
        padding: 0.75rem;
        border-radius: 6px;
        background-color: #fef2f2;
        border: 1px solid #f87171;
        color: #991b1b;
        font-size: 0.85rem;
    }

    .polygon-required-warning.is-active {
        display: block;
    }

    .map-btn-highlight {
        animation: pulse-ring 1.5s infinite;
        outline: 2px solid #ef4444;
    }

    @keyframes pulse-ring {
        0% { transform: scale(1); }
        50% { transform: scale(1.04); }
        100% { transform: scale(1); }
    }

    .btn-disabled-map {
        opacity: 0.45 !important;
        cursor: not-allowed !important;
        pointer-events: none !important;
    }
</style>

<?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<form id="flight-plan-form" method="POST" action="flight_create.php">
    <div id="planner-split-container" class="flight-planner-split">
        <div class="flight-planner-left">
            <div class="card">
                <div class="card-header">
                    <div id="step-badge" class="step-indicator">Step 1 of 2: Overview & Authorization</div>
                    <h2 class="card-title" id="panel-title">Flight Parameters</h2>
                </div>
                <div class="card-body">
                    <!-- ================= STEP 1: INITIAL OVERVIEW ================= -->
                    <div id="step-1-container" class="step-section">
                        <div id="polygon-alert" class="polygon-required-warning">
                            <strong>Polygon Required:</strong> You must draw the operational flight area on the map before advancing to Flight Details.
                        </div>

                        <div class="form-group">
                            <label for="flight_number">Flight Plan #</label>
                            <input type="text" id="flight_number" name="flight_number" class="form-control" value="<?= htmlspecialchars($suggestedFlightNumber) ?>" readonly required style="font-family: monospace; font-weight: bold; background-color: #f8fafc;">
                        </div>

                        <div class="form-group">
                            <label for="work_package">Work Control <span style="color:var(--danger); font-weight:bold;">*</span></label>
                            <input type="text" id="work_package" name="work_package" class="form-control" placeholder="e.g. EX-021" value="<?= htmlspecialchars($_POST['work_package'] ?? '') ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="facility_name">Facility <span style="color:var(--danger); font-weight:bold;">*</span></label>
                            <input type="text" id="facility_name" name="facility_name" class="form-control" placeholder="e.g. C-333 Process Building" value="<?= htmlspecialchars($_POST['facility_name'] ?? '') ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="pilot_id">Remote Pilot in Command (RPIC)</label>
                            <?php if ($isAdmin): ?>
                                <select name="pilot_id" id="pilot_id" class="form-control" required>
                                    <?php foreach ($pilots as $p): ?>
                                        <option value="<?= $p['id'] ?>" <?= ($p['id'] == ($_POST['pilot_id'] ?? $currentUser['id'])) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($p['full_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-hint">Administrator privilege: pilot assignment enabled.</div>
                            <?php else: ?>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($currentUser['full_name']) ?>" readonly style="background-color: #f1f5f9; cursor: not-allowed;">
                                <input type="hidden" name="pilot_id" id="pilot_id" value="<?= (int)$currentUser['id'] ?>">
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

                        <div style="margin-top: 1.5rem; display: flex; justify-content: space-between; align-items: center;">
                            <a href="dashboard.php" class="btn btn-outline">Cancel</a>
                            <button type="button" id="btn-goto-step-2" class="btn btn-primary">Flight Details &rarr;</button>
                        </div>
                    </div>

                    <!-- ================= STEP 2: FLIGHT DETAILS ================= -->
                    <div id="step-2-container" class="step-section is-hidden">
                        <div class="form-group">
                            <label for="drone_select">UAS Aircraft Model <span style="color:var(--danger); font-weight:bold;">*</span></label>
                            <select id="drone_select" name="drone_select" class="form-control" required>
                                <option value="">-- Select Prepopulated Aircraft or Other --</option>
                                <?php foreach ($prepopulatedDrones as $d): ?>
                                    <option value="<?= $d['id'] ?>"
                                            data-model="<?= htmlspecialchars($d['model_name']) ?>"
                                            data-reg="<?= htmlspecialchars($d['faa_reg_number']) ?>"
                                            data-exp="<?= htmlspecialchars($d['faa_reg_expiration']) ?>">
                                        <?= htmlspecialchars($d['model_name']) ?> (FAA Reg: <?= htmlspecialchars($d['faa_reg_number']) ?>)
                                    </option>
                                <?php endforeach; ?>
                                <option value="other" <?= (empty($prepopulatedDrones) || ($_POST['drone_select'] ?? '') === 'other') ? 'selected' : '' ?>>+ Other (Manual Entry)</option>
                            </select>
                        </div>

                        <input type="hidden" id="aircraft_model" name="aircraft_model" value="<?= htmlspecialchars($_POST['aircraft_model'] ?? '') ?>">
                        <input type="hidden" id="faa_reg_number" name="faa_reg_number" value="<?= htmlspecialchars($_POST['faa_reg_number'] ?? '') ?>">
                        <input type="hidden" id="faa_reg_expiration" name="faa_reg_expiration" value="<?= htmlspecialchars($_POST['faa_reg_expiration'] ?? '') ?>">

                        <div id="other-drone-fields" style="display: none; background: #f8fafc; border: 1px solid var(--border-light); border-radius: 6px; padding: 1rem; margin-bottom: 1rem;">
                            <div class="form-group">
                                <label for="custom_drone_model">Drone Model Name <span style="color:var(--danger); font-weight:bold;">*</span></label>
                                <input type="text" id="custom_drone_model" name="custom_drone_model" class="form-control" placeholder="e.g. DJI Mavic 3 Enterprise" value="<?= htmlspecialchars($_POST['custom_drone_model'] ?? '') ?>">
                            </div>
                            <div class="grid-cols-2">
                                <div class="form-group">
                                    <label for="custom_faa_reg_number">FAA Registration # <span style="color:var(--danger); font-weight:bold;">*</span></label>
                                    <input type="text" id="custom_faa_reg_number" name="custom_faa_reg_number" class="form-control" placeholder="FA3XXXXXXXXX" value="<?= htmlspecialchars($_POST['custom_faa_reg_number'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label for="custom_faa_reg_expiration">Registration Expiration Date <span style="color:var(--danger); font-weight:bold;">*</span></label>
                                    <input type="date" id="custom_faa_reg_expiration" name="custom_faa_reg_expiration" class="form-control" value="<?= htmlspecialchars($_POST['custom_faa_reg_expiration'] ?? '') ?>">
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="payload">Payload</label>
                            <input type="text" id="payload" name="payload" class="form-control" placeholder="e.g. Thermal XT2 Camera, LiDAR sensor, standard optical gimbal..." value="<?= htmlspecialchars($_POST['payload'] ?? '') ?>">
                            <div class="form-hint">Specify any onboard payload, cameras, sensors, or specialized equipment.</div>
                        </div>

                        <div class="form-group">
                            <label for="max_altitude">Planned Max Altitude (ft AGL) <span style="color:var(--danger); font-weight:bold;">*</span></label>
                            <input type="number" id="max_altitude" name="max_altitude" class="form-control" value="<?= htmlspecialchars($_POST['max_altitude'] ?? '200') ?>" min="10" max="400" required>
                        </div>

                        <div class="grid-cols-3">
                            <div class="form-group">
                                <label for="flight_date">Flight Date <span style="color:var(--danger); font-weight:bold;">*</span></label>
                                <input type="date" id="flight_date" name="flight_date" class="form-control" value="<?= htmlspecialchars($defaultFlightDate) ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="start_time">Start Time <span style="color:var(--danger); font-weight:bold;">*</span></label>
                                <input type="time" id="start_time" name="start_time" class="form-control" value="<?= htmlspecialchars($_POST['start_time'] ?? '09:00') ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="end_time">End Time <span style="color:var(--danger); font-weight:bold;">*</span></label>
                                <input type="time" id="end_time" name="end_time" class="form-control" value="<?= htmlspecialchars($_POST['end_time'] ?? '11:30') ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="mission_purpose">Mission Scope <span style="color:var(--danger); font-weight:bold;">*</span></label>
                            <textarea id="mission_purpose" name="mission_purpose" class="form-control" rows="3" placeholder="Describe operational mission objectives, flight profile, visual observer setup, safety perimeter..." required><?= htmlspecialchars($_POST['mission_purpose'] ?? '') ?></textarea>
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

                        <div style="margin-top: 1.5rem; display: flex; justify-content: space-between; align-items: center;">
                            <button type="button" id="btn-back-to-step-1" class="btn btn-outline">&larr; Back</button>
                            <button type="submit" id="btn-save-flight" class="btn btn-primary">Save Flight Plan</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="flight-planner-right">
            <div class="map-cockpit" style="background: #0f172a; border-radius: 8px; overflow: hidden;">
                <div class="map-toolbar">
                    <div class="map-toolbar-left">
                        <button type="button" id="btn-draw-polygon" class="btn btn-aloft btn-sm">Draw Flight Polygon</button>
                        <button type="button" id="btn-clear-polygon" class="btn btn-outline btn-sm" style="color: #cbd5e1; border-color: #475569;">Reset</button>
                        <span id="polygon-draw-status" class="map-mode-indicator">Define mission airspace</span>
                    </div>
                </div>
                <div id="flight-polygon-map" 
                     class="planner-map-container"
                     data-interactive="true" 
                     data-lat="37.115191" 
                     data-lng="-88.811224"
                     style="height: 580px; width: 100%;">
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
            <!-- Captured High-Fidelity Satellite Map Snapshot for Outlook & Step 3 -->
            <input type="hidden" name="map_image_base64" id="map_image_base64">
        </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const splitContainer = document.getElementById('planner-split-container');
    const step1Container = document.getElementById('step-1-container');
    const step2Container = document.getElementById('step-2-container');
    const btnGotoStep2 = document.getElementById('btn-goto-step-2');
    const btnBackToStep1 = document.getElementById('btn-back-to-step-1');
    const stepBadge = document.getElementById('step-badge');
    const panelTitle = document.getElementById('panel-title');
    const polygonInput = document.getElementById('polygon_geojson');
    const polygonAlert = document.getElementById('polygon-alert');
    const btnDrawPolygon = document.getElementById('btn-draw-polygon');
    const btnClearPolygon = document.getElementById('btn-clear-polygon');
    const polygonDrawStatus = document.getElementById('polygon-draw-status');
    const dateInput = document.getElementById('flight_date');
    const flightNumInput = document.getElementById('flight_number');

    const droneSelect = document.getElementById('drone_select');
    const otherFields = document.getElementById('other-drone-fields');
    const aircraftModelInput = document.getElementById('aircraft_model');
    const faaRegInput = document.getElementById('faa_reg_number');
    const faaExpInput = document.getElementById('faa_reg_expiration');
    const customModelInput = document.getElementById('custom_drone_model');
    const customRegInput = document.getElementById('custom_faa_reg_number');
    const customExpInput = document.getElementById('custom_faa_reg_expiration');

    function syncDroneSelection() {
        if (!droneSelect) return;
        const val = droneSelect.value;
        if (val === 'other') {
            if (otherFields) otherFields.style.display = 'block';
            if (customModelInput) customModelInput.required = true;
            if (customRegInput) customRegInput.required = true;
            if (customExpInput) customExpInput.required = true;
            if (aircraftModelInput) aircraftModelInput.value = customModelInput.value;
            if (faaRegInput) faaRegInput.value = customRegInput.value;
            if (faaExpInput) faaExpInput.value = customExpInput.value;
        } else if (val) {
            if (otherFields) otherFields.style.display = 'none';
            if (customModelInput) customModelInput.required = false;
            if (customRegInput) customRegInput.required = false;
            if (customExpInput) customExpInput.required = false;

            const selectedOpt = droneSelect.options[droneSelect.selectedIndex];
            if (aircraftModelInput) aircraftModelInput.value = selectedOpt.dataset.model || '';
            if (faaRegInput) faaRegInput.value = selectedOpt.dataset.reg || '';
            if (faaExpInput) faaExpInput.value = selectedOpt.dataset.exp || '';
        } else {
            if (otherFields) otherFields.style.display = 'none';
            if (aircraftModelInput) aircraftModelInput.value = '';
            if (faaRegInput) faaRegInput.value = '';
            if (faaExpInput) faaExpInput.value = '';
        }
    }

    if (droneSelect) {
        droneSelect.addEventListener('change', syncDroneSelection);
        syncDroneSelection();
    }

    if (customModelInput) {
        customModelInput.addEventListener('input', () => {
            if (droneSelect.value === 'other') aircraftModelInput.value = customModelInput.value;
        });
    }
    if (customRegInput) {
        customRegInput.addEventListener('input', () => {
            if (droneSelect.value === 'other') faaRegInput.value = customRegInput.value;
        });
    }
    if (customExpInput) {
        customExpInput.addEventListener('input', () => {
            if (droneSelect.value === 'other') faaExpInput.value = customExpInput.value;
        });
    }

    if (dateInput && flightNumInput) {
        dateInput.addEventListener('change', function () {
            const selectedDate = this.value;
            if (!selectedDate) return;

            fetch('flight_create.php?action=get_flight_number&flight_date=' + encodeURIComponent(selectedDate))
                .then(response => response.json())
                .then(data => {
                    if (data && data.flight_number) {
                        flightNumInput.value = data.flight_number;
                    }
                })
                .catch(err => console.error('Error fetching fiscal year flight number:', err));
        });
    }

    function notifyMapResize() {
        const resizeInterval = setInterval(() => {
            window.dispatchEvent(new Event('resize'));
            if (window.flightMap && typeof window.flightMap.invalidateSize === 'function') {
                window.flightMap.invalidateSize();
            }
        }, 50);

        setTimeout(() => {
            clearInterval(resizeInterval);
            window.dispatchEvent(new Event('resize'));
            if (window.flightMap && typeof window.flightMap.invalidateSize === 'function') {
                window.flightMap.invalidateSize();
            }
        }, 400);
    }

    btnGotoStep2.addEventListener('click', function () {
        const step1Inputs = step1Container.querySelectorAll('input[required], select[required]');
        let isValid = true;

        step1Inputs.forEach(input => {
            if (!input.checkValidity()) {
                input.reportValidity();
                isValid = false;
            }
        });

        if (!isValid) return;

        const polygonVal = polygonInput.value ? polygonInput.value.trim() : '';
        const hasValidPolygon = polygonVal.length > 0 && polygonVal !== '{}' && polygonVal !== 'null';

        if (!hasValidPolygon) {
            polygonAlert.classList.add('is-active');
            btnDrawPolygon.classList.add('map-btn-highlight');
            btnDrawPolygon.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return;
        }

        // Capture live map snapshot before advancing to Step 2
        if (typeof window.generateMapSnapshot === 'function') {
            window.generateMapSnapshot();
        }

        polygonAlert.classList.remove('is-active');
        btnDrawPolygon.classList.remove('map-btn-highlight');

        btnDrawPolygon.disabled = true;
        btnDrawPolygon.classList.add('btn-disabled-map');
        btnClearPolygon.disabled = true;
        btnClearPolygon.classList.add('btn-disabled-map');
        if (polygonDrawStatus) {
            polygonDrawStatus.textContent = 'Airspace boundary locked';
            polygonDrawStatus.style.color = '#94a3b8';
        }

        splitContainer.classList.add('layout-balanced');
        step1Container.classList.add('is-hidden');
        step2Container.classList.remove('is-hidden');

        stepBadge.textContent = 'Step 2 of 2: Mission Specs & Notifications';
        panelTitle.textContent = 'Flight Details';

        notifyMapResize();
    });

    btnBackToStep1.addEventListener('click', function () {
        btnDrawPolygon.disabled = false;
        btnDrawPolygon.classList.remove('btn-disabled-map');
        btnClearPolygon.disabled = false;
        btnClearPolygon.classList.remove('btn-disabled-map');
        if (polygonDrawStatus) {
            polygonDrawStatus.textContent = 'Define mission airspace';
            polygonDrawStatus.style.color = '';
        }

        splitContainer.classList.remove('layout-balanced');
        step2Container.classList.add('is-hidden');
        step1Container.classList.remove('is-hidden');

        stepBadge.textContent = 'Step 1 of 2: Overview & Authorization';
        panelTitle.textContent = 'Flight Parameters';

        notifyMapResize();
    });

    // Ensure final snapshot is fresh right before submit
    document.getElementById('flight-plan-form').addEventListener('submit', function() {
        if (typeof window.generateMapSnapshot === 'function') {
            window.generateMapSnapshot();
        }
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
