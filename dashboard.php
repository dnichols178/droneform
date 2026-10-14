<?php
$pageTitle = 'Operations Dashboard';
$currentPage = 'dashboard';
require_once __DIR__ . '/includes/header.php';
$pdo = getDB();

// Operational metrics
$totalFlights = $pdo->query("SELECT COUNT(*) FROM flights")->fetchColumn();
$activeLAANC = $pdo->query("SELECT COUNT(*) FROM flights WHERE laanc_status IN ('AUTO_APPROVED', 'MANUAL_APPROVED')")->fetchColumn();
$loggedHours = $pdo->query("SELECT COALESCE(SUM(total_flight_minutes), 0) FROM flight_logs")->fetchColumn();
$loggedHoursFormat = round($loggedHours / 60, 1);

// Recent Flights Query: Ordered by flight plan number, highest first
$recentFlights = $pdo->query("
    SELECT f.*, u.full_name AS pilot_name
    FROM flights f
    JOIN users u ON f.pilot_id = u.id
    ORDER BY f.flight_number DESC
    LIMIT 6
")->fetchAll();
?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'flight_cancelled'): ?>
    <div class="alert alert-info">Flight plan and all associated records have been cancelled and permanently deleted.</div>
<?php endif; ?>

<div class="grid grid-cols-3" style="margin-bottom: 2rem;">
    <div class="stat-card">
        <div class="stat-label">Total Missions</div>
        <div class="stat-val"><?= $totalFlights ?></div>
    </div>
    <div class="stat-card" style="border-left-color: #16a34a;">
        <div class="stat-label">Active LAANC Approvals</div>
        <div class="stat-val"><?= $activeLAANC ?></div>
    </div>
    <div class="stat-card" style="border-left-color: #d97706;">
        <div class="stat-label">Total Flight Time</div>
        <div class="stat-val"><?= $loggedHoursFormat ?> <span style="font-size: 1rem; color: var(--text-muted);">hrs</span></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">Recent &amp; Upcoming Flight Missions</h2>
        <a href="flight_create.php" class="btn btn-primary btn-sm">+ Plan Flight</a>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Flight Plan #</th>
                        <th>Work Package</th>
                        <th>Target Facility</th>
                        <th>Airspace</th>
                        <th>Altitude</th>
                        <th>LAANC Status</th>
                        <th>Pilot (RPIC)</th>
                        <th>Date &amp; Time</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentFlights)): ?>
                        <tr><td colspan="10" style="text-align: center; color: var(--text-muted);">No recorded flights. Plan your first mission.</td></tr>
                    <?php else: foreach ($recentFlights as $f): ?>
                        <tr>
                            <td><strong><a href="flight_details.php?id=<?= $f['id'] ?>"><?= htmlspecialchars($f['flight_number']) ?></a></strong></td>
                            <td><?= htmlspecialchars($f['work_package']) ?></td>
                            <td><?= htmlspecialchars($f['facility_name']) ?></td>
                            <td><span class="badge badge-neutral">Class <?= htmlspecialchars($f['airspace_class']) ?></span></td>
                            <td><?= $f['max_altitude'] ?> ft</td>
                            <td>
                                <?php if ($f['laanc_status'] === 'AUTO_APPROVED' || $f['laanc_status'] === 'MANUAL_APPROVED'): ?>
                                    <span class="badge badge-success"><?= $f['laanc_status'] ?></span>
                                <?php elseif ($f['laanc_status'] === 'PENDING'): ?>
                                    <span class="badge badge-warning">PENDING</span>
                                <?php else: ?>
                                    <span class="badge badge-neutral"><?= $f['laanc_status'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($f['pilot_name']) ?></td>
                            <td><?= $f['flight_date'] ?> <?= $f['start_time'] ?></td>
                            <td><span class="badge badge-info"><?= $f['status'] ?></span></td>
                            <td>
                                <a href="flight_details.php?id=<?= $f['id'] ?>" class="btn btn-outline btn-sm">Review</a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>