<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/mailer.php';

requireLogin();

$flightId = (int)($_GET['id'] ?? 0);
if (!$flightId) {
    http_response_code(404);
    exit;
}

$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM flights WHERE id = ?");
$stmt->execute([$flightId]);
$flight = $stmt->fetch();

if (!$flight) {
    http_response_code(404);
    exit;
}

$binary = generateFlightAreaImageBinary($flight);

if (!empty($binary)) {
    header('Content-Type: image/png');
    header('Content-Length: ' . strlen($binary));
    header('Cache-Control: private, max-age=3600');
    echo $binary;
    exit;
} else {
    // Deliver SVG vector fallback directly
    header('Content-Type: image/svg+xml');
    echo generateFlightAreaSvgRaw($flight);
    exit;
}
