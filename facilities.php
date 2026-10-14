<?php
$pageTitle = 'Facility & Airspace Directory';
$currentPage = 'facilities';
require_once __DIR__ . '/includes/header.php';
$pdo = getDB();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_facility') {
    $name = trim($_POST['facility_name'] ?? '');
    $code = strtoupper(trim($_POST['facility_code'] ?? ''));
    $airspace = $_POST['airspace_class'] ?? 'G';
    $ceiling = (int)($_POST['grid_ceiling_alt'] ?? 400);
    $lat = (float)($_POST['latitude'] ?? 37.0878);
    $lng = (float)($_POST['longitude'] ?? -88.6000);
    $address = trim($_POST['address'] ?? '');
    $emails = trim($_POST['default_notification_emails'] ?? '');

    if ($name && $code) {
        $stmt = $pdo->prepare("INSERT INTO facilities (facility_name, facility_code, airspace_class, grid_ceiling_alt, latitude, longitude, address, default_notification_emails) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        try {
            $stmt->execute([$name, $code, $airspace, $ceiling, $lat, $lng, $address, $emails]);
            $msg = 'Facility registered successfully with UASFM coordinates.';
        } catch (PDOException $e) {
            $msg = 'Error creating facility: ' . $e->getMessage();
        }
    }
}

$facilities = $pdo->query("SELECT * FROM facilities ORDER BY facility_name ASC")->fetchAll();
?>

<?php if ($msg): ?><div class="alert alert-info"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="grid grid-cols-2">
    <div class="card">
        <div class="card-header"><h3 class="card-title">Add Target Facility</h3></div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="create_facility">
                <div class="form-group">
                    <label for="facility_name">Facility Name</label>
                    <input type="text" name="facility_name" id="facility_name" class="form-control" required placeholder="e.g. West Coast Substation 4">
                </div>
                <div class="grid grid-cols-3">
                    <div class="form-group">
                        <label for="facility_code">Facility Code</label>
                        <input type="text" name="facility_code" id="facility_code" class="form-control" required placeholder="FAC-WCS4">
                    </div>
                    <div class="form-group">
                        <label for="airspace_class">Airspace Class</label>
                        <select name="airspace_class" id="airspace_class" class="form-control">
                            <option value="G">Class G (Uncontrolled)</option>
                            <option value="B">Class B</option>
                            <option value="C">Class C</option>
                            <option value="D">Class D</option>
                            <option value="E">Class E (Surface)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="grid_ceiling_alt">LAANC Ceiling (ft)</label>
                        <input type="number" name="grid_ceiling_alt" id="grid_ceiling_alt" class="form-control" value="400" min="0" max="400">
                    </div>
                </div>
                <div class="grid grid-cols-2">
                    <div class="form-group">
                        <label for="latitude">Facility Latitude</label>
                        <input type="number" step="any" name="latitude" id="latitude" class="form-control" value="37.0878" required>
                    </div>
                    <div class="form-group">
                        <label for="longitude">Facility Longitude</label>
                        <input type="number" step="any" name="longitude" id="longitude" class="form-control" value="-88.6000" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="address">Address / Location Description</label>
                    <input type="text" name="address" id="address" class="form-control" placeholder="Coordinates or physical street address">
                </div>
                <div class="form-group">
                    <label for="default_notification_emails">Predefined Personnel Notification Emails (Comma-separated)</label>
                    <textarea name="default_notification_emails" id="default_notification_emails" class="form-control" placeholder="security@facility.com, ops@facility.com"></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Save Facility</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title">Configured Facilities</h3></div>
        <div class="card-body">
            <table class="table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Airspace</th>
                        <th>LAANC Ceiling</th>
                        <th>Coordinates</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($facilities as $fac): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($fac['facility_code']) ?></strong></td>
                            <td><?= htmlspecialchars($fac['facility_name']) ?></td>
                            <td><span class="badge badge-neutral">Class <?= $fac['airspace_class'] ?></span></td>
                            <td><?= $fac['grid_ceiling_alt'] ?> ft</td>
                            <td><small><?= $fac['latitude'] ?>, <?= $fac['longitude'] ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>