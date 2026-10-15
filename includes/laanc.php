<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

class LaancDeepLinkService {

    public static function buildAloftWebUrl(array $flight): string {
        $lat = round((float)($flight['latitude'] ?? 37.115191), 6);
        $lng = round((float)($flight['longitude'] ?? -88.811224), 6);
        $alt = (int)($flight['max_altitude'] ?? 200);
        $title = urlencode($flight['flight_number'] . ' - ' . ($flight['facility_name'] ?? 'Part 107 Mission'));

        $base = "https://air.aloft.ai";
        $params = [
            'lat' => $lat,
            'lng' => $lng,
            'alt' => $alt,
            'name' => $title,
            'date' => $flight['flight_date'] ?? date('Y-m-d'),
            'start' => $flight['start_time'] ?? '09:00',
            'end' => $flight['end_time'] ?? '11:00'
        ];
        return $base . '?' . http_build_query($params);
    }

    public static function buildAloftAppUrl(array $flight): string {
        $lat = round((float)($flight['latitude'] ?? 37.115191), 6);
        $lng = round((float)($flight['longitude'] ?? -88.811224), 6);
        $alt = (int)($flight['max_altitude'] ?? 200);

        return "aloft://aircontrol/plan?lat={$lat}&lng={$lng}&altitude={$alt}&flight=" . urlencode($flight['flight_number']);
    }

    /**
     * Records the FAA LAANC authorization in local time, preventing duplicates across all flights
     */
    public static function recordAloftAuthorization(int $flightId, string $authNumber, ?string &$errorMsg = null): bool {
        $authNumber = strtoupper(trim($authNumber));
        if (empty($authNumber)) {
            $errorMsg = "Authorization number cannot be empty.";
            return false;
        }

        $pdo = getDB();

        // Check for duplicate authorization code across other flight plans
        $checkStmt = $pdo->prepare("SELECT flight_number FROM flights WHERE UPPER(laanc_auth_number) = ? AND id != ?");
        $checkStmt->execute([$authNumber, $flightId]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            $errorMsg = "Duplicate LAANC Authorization: Number '{$authNumber}' is already registered to flight plan {$existing['flight_number']}. LAANC authorization numbers must be unique.";
            return false;
        }

        $localTimestamp = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("
            UPDATE flights SET
                laanc_auth_number = ?,
                laanc_status = 'APPROVED',
                laanc_authorized_at = ?
            WHERE id = ?
        ");
        return $stmt->execute([$authNumber, $localTimestamp, $flightId]);
    }
}
