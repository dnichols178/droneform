<?php
$pageTitle = 'Flight Operations Catalog';
$currentPage = 'flights';
require_once __DIR__ . '/includes/header.php';
$pdo = getDB();

$search = trim($_GET['search'] ?? '');
$sql = "
    SELECT f.*, u.full_name AS pilot_name
    FROM flights f
    JOIN users u ON f.pilot_id = u.id
";

if (!empty($search)) {
    $sql .= " WHERE f.flight_number LIKE :s OR f.facility_name LIKE :s OR f.work_package LIKE :s ";
}
// Highest flight plan number first
$sql .= " ORDER BY f.flight_number DESC";

$stmt = $pdo->prepare($sql);
if (!empty($search)) {
    $stmt->execute([':s' => "%$search%"]);
} else {
    $stmt->execute();
}
$flights = $stmt->fetchAll();
?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">All Flight Operations</h2>
        <form method="GET" style="display: flex; gap: 0.5rem;">
            <input type="text" name="search" class="form-control" placeholder="Search flight #, facility, package..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        </form>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Flight Plan #</th>
                        <th>Work Package</th>
                        <th>Facility</th>
                        <th>Airspace</th>
                        <th>Altitude</th>
                        <th>LAANC Status</th>
                        <th>Pilot</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($flights)): ?>
                        <tr><td colspan="10" style="text-align:center;">No matching flight operations cataloged.</td></tr>
                    <?php else: foreach ($flights as $f): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($f['flight_number']) ?></strong></td>
                            <td><?= htmlspecialchars($f['work_package']) ?></td>
                            <td><?= htmlspecialchars($f['facility_name']) ?></td>
                            <td>Class <?= htmlspecialchars($f['airspace_class']) ?></td>
                            <td><?= $f['max_altitude'] ?> ft</td>
                            <td><span class="badge badge-info"><?= $f['laanc_status'] ?></span></td>
                            <td><?= htmlspecialchars($f['pilot_name']) ?></td>
                            <td><?= $f['flight_date'] ?></td>
                            <td><span class="badge badge-neutral"><?= $f['status'] ?></span></td>
                            <td>
                                <a href="flight_details.php?id=<?= $f['id'] ?>" class="btn btn-outline btn-sm">Details</a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>