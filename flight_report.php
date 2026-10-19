<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/mailer.php';

$pdo = getDB();
$flightId = (int)($_GET['id'] ?? 0);

if (!$flightId) {
    die("Flight ID is missing.");
}

$stmt = $pdo->prepare("
    SELECT f.*, u.full_name AS pilot_name, u.part107_cert_num, u.phone AS pilot_phone, u.email AS pilot_email
    FROM flights f
    JOIN users u ON f.pilot_id = u.id
    WHERE f.id = ?
");
$stmt->execute([$flightId]);
$flight = $stmt->fetch();

if (!$flight) {
    die("Flight plan record not found.");
}

$mapBase64 = generateFlightAreaImageBase64($flight);
$localTz = date('T');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Flight Plan <?= htmlspecialchars($flight['flight_number']) ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            margin: 0;
            padding: 24px;
            color: #0f172a;
            background: #f8fafc;
        }
        .container {
            max-width: 820px;
            margin: 0 auto;
            background: #fff;
            padding: 32px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
        }
        .actions-bar {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .btn {
            display: inline-block;
            padding: 8px 16px;
            font-size: 14px;
            font-weight: 600;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-print { background: #0284c7; color: #fff; border: 1px solid #0284c7; }
        .btn-back { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 16px;
            margin-bottom: 20px;
        }
        .title { font-size: 24px; font-weight: bold; margin: 0; color: #0f172a; }
        .subtitle { font-size: 13px; color: #0284c7; font-weight: bold; text-transform: uppercase; margin-bottom: 4px; }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 13px;
        }
        .data-table th, .data-table td {
            padding: 9px 12px;
            border: 1px solid #cbd5e1;
            text-align: left;
        }
        .data-table th { background: #f8fafc; color: #475569; width: 25%; font-weight: 600; }
        .data-table td { width: 25%; color: #0f172a; }
        .section-title {
            font-size: 13px;
            font-weight: bold;
            text-transform: uppercase;
            color: #0369a1;
            margin-bottom: 6px;
        }
        .scope-box {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-left: 4px solid #0284c7;
            padding: 12px 16px;
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .map-section {
            text-align: center;
            margin-bottom: 20px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            overflow: hidden;
            background: #0f172a;
        }
        .map-section img {
            width: 100%;
            max-width: 584px;
            display: block;
            margin: 0 auto;
        }
        .footer-note {
            font-size: 11px;
            color: #64748b;
            border-top: 1px solid #e2e8f0;
            padding-top: 12px;
            display: flex;
            justify-content: space-between;
        }

        @media print {
            body { background: #fff; padding: 0; }
            .container { border: none; padding: 0; max-width: 100%; }
            .actions-bar { display: none !important; }
            @page { margin: 1.5cm; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="actions-bar">
            <a href="flight_details.php?id=<?= $flight['id'] ?>" class="btn btn-back">&larr; Return to Flight Details</a>
            <button onclick="window.print()" class="btn btn-print">🖨️ Print / Save as PDF</button>
        </div>

        <div class="header">
            <div>
                <div class="subtitle">FRNP UAS Flight Operations</div>
                <h1 class="title"><?= htmlspecialchars($flight['flight_number']) ?></h1>
                <div style="font-size: 13px; color: #64748b; margin-top: 4px;">
                    Target Facility: <strong><?= htmlspecialchars($flight['facility_name']) ?></strong> | Work Package: <strong><?= htmlspecialchars($flight['work_package']) ?></strong>
                </div>
            </div>
            <div style="text-align: right; font-size: 12px; color: #475569;">
                <div><strong>Airspace:</strong> Class <?= htmlspecialchars($flight['airspace_class']) ?></div>
                <div><strong>LAANC:</strong> <?= htmlspecialchars($flight['laanc_auth_number'] ?: 'NOT REQUIRED') ?></div>
                <div><strong>Printed:</strong> <?= date('Y-m-d H:i') ?> <?= $localTz ?></div>
            </div>
        </div>

        <div class="section-title">Operational Scope</div>
        <div class="scope-box">
            <?= nl2br(htmlspecialchars($flight['mission_purpose'])) ?>
        </div>

        <div class="section-title">Mission Parameters</div>
        <table class="data-table">
            <tr>
                <th>Scheduled Date</th>
                <td><?= htmlspecialchars($flight['flight_date']) ?></td>
                <th>Time Window</th>
                <td><?= htmlspecialchars($flight['start_time']) ?> - <?= htmlspecialchars($flight['end_time']) ?> (<?= $localTz ?>)</td>
            </tr>
            <tr>
                <th>Max Altitude</th>
                <td><strong><?= htmlspecialchars($flight['max_altitude']) ?> ft AGL</strong></td>
                <th>Footprint Area</th>
                <td><?= htmlspecialchars($flight['operational_acres']) ?> Acres</td>
            </tr>
            <tr>
                <th>Centroid Coordinates</th>
                <td><?= htmlspecialchars($flight['latitude']) ?>, <?= htmlspecialchars($flight['longitude']) ?></td>
                <th>Aircraft Model</th>
                <td><?= htmlspecialchars($flight['aircraft_model']) ?></td>
            </tr>
            <tr>
                <th>Remote PIC</th>
                <td><?= htmlspecialchars($flight['pilot_name']) ?></td>
                <th>Part 107 Certificate</th>
                <td><?= htmlspecialchars($flight['part107_cert_num'] ?: 'ON FILE') ?></td>
            </tr>
            <tr>
                <th>PIC Phone</th>
                <td><?= htmlspecialchars($flight['pilot_phone'] ?: 'N/A') ?></td>
                <th>PIC Email</th>
                <td><?= htmlspecialchars($flight['pilot_email'] ?: 'N/A') ?></td>
            </tr>
        </table>

        <?php if (!empty($mapBase64)): ?>
            <div class="section-title">Operational Boundary Map</div>
            <div class="map-section">
                <img src="<?= $mapBase64 ?>" alt="Operational Boundary Map">
            </div>
        <?php endif; ?>

        <div class="footer-note">
            <span>FRNP Drone Flight Management System &bull; FAA Part 107 Compliance Document</span>
            <span>Flight ID: <?= $flight['id'] ?></span>
        </div>
    </div>
</body>
</html>
