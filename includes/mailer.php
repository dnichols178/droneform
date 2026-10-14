<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function ensureSettingsTables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS distribution_recipients (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        organization TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");
}

function getSystemSetting(string $key, string $default = ''): string {
    $pdo = getDB();
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return ($val !== false && $val !== null) ? $val : $default;
    } catch (PDOException $e) {
        ensureSettingsTables($pdo);
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $val = $stmt->fetchColumn();
            return ($val !== false && $val !== null) ? $val : $default;
        } catch (Exception $e2) {
            return $default;
        }
    }
}

/**
 * Web Mercator Projection Helpers for Tile Math
 */
function mercatorLon2X(float $lon, int $zoom): float {
    return (($lon + 180.0) / 360.0) * (256 * (1 << $zoom));
}

function mercatorLat2Y(float $lat, int $zoom): float {
    $latRad = deg2rad($lat);
    return (1.0 - (log(tan($latRad) + (1.0 / cos($latRad))) / M_PI)) / 2.0 * (256 * (1 << $zoom));
}

/**
 * Generates an embedded base64 image (PNG) showing ESRI Satellite Imagery
 * stitched with the mission boundary polygon, centroid, and telemetry HUD.
 */
function generateFlightAreaImageBase64(array $flight): string {
    $width = 584;
    $height = 320;

    $lat = (float)($flight['latitude'] ?? 37.115191);
    $lng = (float)($flight['longitude'] ?? -88.811224);

    // Parse boundary coordinates
    $rawCoords = [];
    $allLngs = [$lng];
    $allLats = [$lat];

    if (!empty($flight['polygon_geojson'])) {
        $geo = @json_decode($flight['polygon_geojson'], true);
        if (!empty($geo['coordinates'][0]) && is_array($geo['coordinates'][0])) {
            $rawCoords = $geo['coordinates'][0];
            $allLngs = array_column($rawCoords, 0);
            $allLats = array_column($rawCoords, 1);
        }
    }

    $minLng = min($allLngs);
    $maxLng = max($allLngs);
    $minLat = min($allLats);
    $maxLat = max($allLats);

    $centerLat = ($minLat + $maxLat) / 2.0;
    $centerLng = ($minLng + $maxLng) / 2.0;

    // Calculate optimal zoom level (between 12 and 17) to fit the polygon with margins
    $optimalZoom = 15;
    for ($z = 17; $z >= 12; $z--) {
        $wPx = abs(mercatorLon2X($maxLng, $z) - mercatorLon2X($minLng, $z));
        $hPx = abs(mercatorLat2Y($maxLat, $z) - mercatorLat2Y($minLat, $z));
        if ($wPx <= ($width * 0.70) && $hPx <= ($height * 0.70)) {
            $optimalZoom = $z;
            break;
        }
    }

    if (function_exists('imagecreatetruecolor')) {
        $img = imagecreatetruecolor($width, $height);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        // Color definitions
        $bgDark = imagecolorallocate($img, 15, 23, 42);
        $gridLine = imagecolorallocate($img, 30, 41, 59);
        $cyan = imagecolorallocate($img, 0, 210, 255);
        $cyanFill = imagecolorallocatealpha($img, 0, 210, 255, 80);
        $white = imagecolorallocate($img, 248, 250, 252);
        $muted = imagecolorallocate($img, 148, 163, 184);
        $green = imagecolorallocate($img, 34, 197, 94);
        $yellow = imagecolorallocate($img, 245, 158, 11);
        $hudBg = imagecolorallocatealpha($img, 15, 23, 42, 30);
        $cardBorder = imagecolorallocate($img, 51, 65, 85);
        $shadow = imagecolorallocatealpha($img, 0, 0, 0, 60);

        // Compute pixel bounds relative to center
        $centerX = mercatorLon2X($centerLng, $optimalZoom);
        $centerY = mercatorLat2Y($centerLat, $optimalZoom);

        $imgMinX = $centerX - ($width / 2.0);
        $imgMinY = $centerY - ($height / 2.0);

        $tileMinX = (int)floor($imgMinX / 256.0);
        $tileMaxX = (int)floor(($imgMinX + $width) / 256.0);
        $tileMinY = (int)floor($imgMinY / 256.0);
        $tileMaxY = (int)floor(($imgMinY + $height) / 256.0);

        // Prepare local disk tile cache directory
        $cacheDir = DATA_PATH . '/tile_cache';
        if (!file_exists($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }

        // Identify needed tiles
        $tilesToFetch = [];
        $tilesData = [];

        for ($tx = $tileMinX; $tx <= $tileMaxX; $tx++) {
            for ($ty = $tileMinY; $ty <= $tileMaxY; $ty++) {
                $key = "{$optimalZoom}_{$ty}_{$tx}";
                $cachePath = "{$cacheDir}/{$key}.jpg";

                if (file_exists($cachePath) && filesize($cachePath) > 500) {
                    $tilesData[$key] = file_get_contents($cachePath);
                } else {
                    $tilesToFetch[$key] = [
                        'url' => "https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{$optimalZoom}/{$ty}/{$tx}",
                        'cache_path' => $cachePath
                    ];
                }
            }
        }

        // Parallel fetch of missing tiles using curl_multi
        if (!empty($tilesToFetch) && function_exists('curl_multi_init')) {
            $mh = curl_multi_init();
            $curlHandles = [];

            foreach ($tilesToFetch as $key => $info) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $info['url']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Part107-Ops-Satellite/1.0');
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_multi_add_handle($mh, $ch);
                $curlHandles[$key] = $ch;
            }

            $running = null;
            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh, 0.1);
            } while ($running > 0);

            foreach ($curlHandles as $key => $ch) {
                $rawTile = curl_multi_getcontent($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($httpCode === 200 && !empty($rawTile) && strlen($rawTile) > 500) {
                    @file_put_contents($tilesToFetch[$key]['cache_path'], $rawTile);
                    $tilesData[$key] = $rawTile;
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
        }

        // Stitch satellite imagery tiles onto the canvas
        $loadedSatCount = 0;
        for ($tx = $tileMinX; $tx <= $tileMaxX; $tx++) {
            for ($ty = $tileMinY; $ty <= $tileMaxY; $ty++) {
                $key = "{$optimalZoom}_{$ty}_{$tx}";
                $destX = (int)round(($tx * 256.0) - $imgMinX);
                $destY = (int)round(($ty * 256.0) - $imgMinY);

                if (!empty($tilesData[$key])) {
                    $tileImg = @imagecreatefromstring($tilesData[$key]);
                    if ($tileImg) {
                        imagecopy($img, $tileImg, $destX, $destY, 0, 0, 256, 256);
                        imagedestroy($tileImg);
                        $loadedSatCount++;
                    }
                }
            }
        }

        // Fallback grid if offline / no tiles returned
        if ($loadedSatCount === 0) {
            imagefilledrectangle($img, 0, 0, $width, $height, $bgDark);
            for ($x = 0; $x < $width; $x += 35) {
                imageline($img, $x, 0, $x, $height, $gridLine);
            }
            for ($y = 0; $y < $height; $y += 35) {
                imageline($img, 0, $y, $width, $y, $gridLine);
            }
        }

        // Coordinate to pixel projection helper
        $projectX = function($lon) use ($optimalZoom, $imgMinX) {
            return (int)round(mercatorLon2X($lon, $optimalZoom) - $imgMinX);
        };
        $projectY = function($lat) use ($optimalZoom, $imgMinY) {
            return (int)round(mercatorLat2Y($lat, $optimalZoom) - $imgMinY);
        };

        // Draw Polygon flight boundary
        if (!empty($rawCoords) && count($rawCoords) >= 3) {
            $polyPoints = [];
            foreach ($rawCoords as $c) {
                $polyPoints[] = $projectX($c[0]);
                $polyPoints[] = $projectY($c[1]);
            }

            // Translucent cyan polygon fill
            imagefilledpolygon($img, $polyPoints, $cyanFill);

            // Bold polygon boundary line
            imagesetthickness($img, 3);
            for ($i = 0; $i < count($rawCoords) - 1; $i++) {
                $x1 = $projectX($rawCoords[$i][0]);
                $y1 = $projectY($rawCoords[$i][1]);
                $x2 = $projectX($rawCoords[$i+1][0]);
                $y2 = $projectY($rawCoords[$i+1][1]);
                imageline($img, $x1, $y1, $x2, $y2, $cyan);
            }
            imagesetthickness($img, 1);
        }

        // Draw Centroid Crosshairs & Target Marker
        $centroidX = $projectX($lng);
        $centroidY = $projectY($lat);

        // Shadow backing for target visibility over satellite imagery
        imagesetthickness($img, 3);
        imageline($img, $centroidX - 13, $centroidY, $centroidX + 13, $centroidY, $shadow);
        imageline($img, $centroidX, $centroidY - 13, $centroidX, $centroidY + 13, $shadow);
        imageellipse($img, $centroidX, $centroidY, 19, 19, $shadow);

        // Crisp cyan targeting reticle
        imagesetthickness($img, 2);
        imageline($img, $centroidX - 12, $centroidY, $centroidX + 12, $centroidY, $cyan);
        imageline($img, $centroidX, $centroidY - 12, $centroidX, $centroidY + 12, $cyan);
        imageellipse($img, $centroidX, $centroidY, 18, 18, $cyan);
        imagefilledellipse($img, $centroidX, $centroidY, 5, 5, $white);
        imagesetthickness($img, 1);

        // Top Left HUD Banner
        imagefilledrectangle($img, 10, 10, 310, 48, $hudBg);
        imagerectangle($img, 10, 10, 310, 48, $cardBorder);
        imagestring($img, 3, 18, 15, "FLIGHT AREA BOUNDARY MAP", $cyan);
        $acres = $flight['operational_acres'] ?? 0;
        $alt = $flight['max_altitude'] ?? 0;
        imagestring($img, 2, 18, 30, "Footprint: {$acres} Ac | Ceiling: {$alt} ft AGL", $white);

        // Top Right North Compass Indicator
        imagefilledrectangle($img, $width - 44, 10, $width - 10, 48, $hudBg);
        imagerectangle($img, $width - 44, 10, $width - 10, 48, $cardBorder);
        imagestring($img, 3, $width - 32, 14, "N", $white);
        imageline($img, $width - 27, 28, $width - 27, 39, $cyan);

        // Bottom Telemetry Bar
        imagefilledrectangle($img, 10, $height - 34, $width - 10, $height - 8, $hudBg);
        imagerectangle($img, 10, $height - 34, $width - 10, $height - 8, $cardBorder);
        $coordStr = "Centroid: " . round($lat, 6) . ", " . round($lng, 6);
        $airspaceStr = "Airspace: Class " . ($flight['airspace_class'] ?? 'G');
        imagestring($img, 2, 18, $height - 25, $coordStr, $white);
        imagestring($img, 2, $width - 150, $height - 25, $airspaceStr, (($flight['airspace_class'] ?? 'G') === 'G' ? $green : $yellow));

        ob_start();
        imagepng($img);
        $binary = ob_get_clean();
        imagedestroy($img);
        return 'data:image/png;base64,' . base64_encode($binary);
    }

    // Vector SVG Fallback
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="584" height="320" viewBox="0 0 584 320">';
    $svg .= '<rect width="584" height="320" fill="#0f172a"/>';
    $svg .= '<circle cx="292" cy="160" r="80" fill="none" stroke="#1e293b" stroke-width="2"/>';
    $svg .= '<circle cx="292" cy="160" r="5" fill="#00d2ff"/>';
    $svg .= '<text x="20" y="30" fill="#00d2ff" font-family="Arial" font-size="14" font-weight="bold">FLIGHT OPERATIONAL BOUNDARY MAP</text>';
    $svg .= '<text x="20" y="52" fill="#f8fafc" font-family="Arial" font-size="12">Footprint: ' . htmlspecialchars($flight['operational_acres']) . ' Acres | Ceiling: ' . htmlspecialchars($flight['max_altitude']) . ' ft AGL</text>';
    $svg .= '<text x="20" y="305" fill="#94a3b8" font-family="monospace" font-size="11">Centroid: ' . htmlspecialchars($flight['latitude']) . ', ' . htmlspecialchars($flight['longitude']) . ' | Class ' . htmlspecialchars($flight['airspace_class']) . '</text>';
    $svg .= '</svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

function buildFlightNotificationHtml(array $flight, array $recipients): string {
    $laancBadgeColor = (!empty($flight['laanc_auth_number']) || $flight['laanc_status'] === 'AUTO_APPROVED') ? '#16a34a' : '#d97706';
    $laancBadgeBg = (!empty($flight['laanc_auth_number']) || $flight['laanc_status'] === 'AUTO_APPROVED') ? '#dcfce7' : '#fef3c7';
    $laancText = !empty($flight['laanc_auth_number']) ? htmlspecialchars($flight['laanc_auth_number']) : ($flight['airspace_class'] === 'G' ? 'NOT REQUIRED (CLASS G)' : 'PENDING');
    $localTzName = date('T');
    $flightAreaImageBase64 = generateFlightAreaImageBase64($flight);

    $html = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">' . "\r\n";
    $html .= '<html xmlns="http://www.w3.org/1999/xhtml">' . "\r\n";
    $html .= '<head>' . "\r\n";
    $html .= '  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />' . "\r\n";
    $html .= '  <meta name="viewport" content="width=device-width, initial-scale=1.0" />' . "\r\n";
    $html .= '  <title>' . htmlspecialchars($flight['flight_number']) . '</title>' . "\r\n";
    $html .= '</head>' . "\r\n";
    $html .= '<body style="margin: 0; padding: 20px; background-color: #f0f4f8; font-family: Arial, Helvetica, sans-serif;">' . "\r\n";
    $html .= '  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f0f4f8;">' . "\r\n";
    $html .= '    <tr><td align="center">' . "\r\n";
    $html .= '        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: 640px; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden;">' . "\r\n";
    
    // Header Banner
    $html .= '          <tr><td bgcolor="#0f172a" style="background-color: #0f172a; padding: 22px 28px; border-bottom: 3px solid #0284c7;">' . "\r\n";
    $html .= '              <table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>' . "\r\n";
    $html .= '                  <td><div style="color: #38bdf8; font-size: 11px; font-weight: 700; text-transform: uppercase;">FAA Part 107 Commercial Flight Plan</div>' . "\r\n";
    $html .= '                      <div style="color: #ffffff; font-size: 22px; font-weight: 700; margin-top: 4px;">' . htmlspecialchars($flight['flight_number']) . '</div></td>' . "\r\n";
    $html .= '                  <td align="right"><span style="background-color: rgba(255,255,255,0.15); color: #ffffff; font-size: 12px; font-weight: 700; padding: 6px 12px; border-radius: 4px;">Class ' . htmlspecialchars($flight['airspace_class']) . ' Airspace</span></td>' . "\r\n";
    $html .= '              </tr></table></td></tr>' . "\r\n";

    // LAANC Callout
    $html .= '          <tr><td style="padding: 20px 28px 10px 28px;">' . "\r\n";
    $html .= '              <table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="' . $laancBadgeBg . '" style="background-color: ' . $laancBadgeBg . '; border: 1px solid ' . $laancBadgeColor . '; border-radius: 6px;">' . "\r\n";
    $html .= '                <tr><td style="padding: 12px 16px;">' . "\r\n";
    $html .= '                    <span style="color: ' . $laancBadgeColor . '; font-weight: 700; font-size: 13px;">FAA LAANC CLEARANCE:</span>' . "\r\n";
    $html .= '                    <span style="font-family: monospace; font-weight: 700; font-size: 15px; color: #0f172a; margin-left: 8px;">' . $laancText . '</span>' . "\r\n";
    if (!empty($flight['laanc_authorized_at'])) {
        $html .= '                    <div style="color: #475569; font-size: 11px; margin-top: 4px;">Cleared Timestamp: ' . htmlspecialchars($flight['laanc_authorized_at']) . ' Local (' . $localTzName . ')</div>' . "\r\n";
    }
    $html .= '                </td></tr></table></td></tr>' . "\r\n";

    // Embedded Base64 Flight Map
    $html .= '          <tr><td style="padding: 10px 28px;">' . "\r\n";
    $html .= '              <table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#0f172a" style="background-color: #0f172a; border-radius: 6px; overflow: hidden; border: 1px solid #334155;">' . "\r\n";
    $html .= '                <tr><td align="center" style="padding: 0; background-color: #090d16;">' . "\r\n";
    $html .= '                    <img src="' . $flightAreaImageBase64 . '" alt="Flight Area Map" width="584" style="width: 100%; max-width: 584px; height: auto; display: block; border: 0;" />' . "\r\n";
    $html .= '                </td></tr></table></td></tr>' . "\r\n";

    // Operations Data Table
    $html .= '          <tr><td style="padding: 10px 28px 20px 28px;">' . "\r\n";
    $html .= '              <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse;">' . "\r\n";
    $rows = [
        'Target Facility'         => htmlspecialchars($flight['facility_name']),
        'Work Package'            => htmlspecialchars($flight['work_package']),
        'Operational Window'      => htmlspecialchars($flight['flight_date']) . ' (' . htmlspecialchars($flight['start_time']) . ' - ' . htmlspecialchars($flight['end_time']) . ' Local ' . $localTzName . ')',
        'Max Operating Ceiling'   => '<strong>' . htmlspecialchars($flight['max_altitude']) . ' ft AGL</strong>',
        'Centroid Coordinates'    => htmlspecialchars($flight['latitude']) . ', ' . htmlspecialchars($flight['longitude']),
        'Operational Area'        => htmlspecialchars($flight['operational_acres']) . ' Acres',
        'UAS Aircraft Model'      => htmlspecialchars($flight['aircraft_model']),
        'Remote Pilot in Command' => htmlspecialchars($flight['pilot_name']) . ' (Part 107 #: ' . htmlspecialchars($flight['part107_cert_num'] ?: 'ON FILE') . ')',
        'Emergency Phone Contact' => htmlspecialchars($flight['pilot_phone'] ?: 'N/A'),
        'RPIC Email Contact'      => htmlspecialchars($flight['pilot_email'] ?: 'N/A')
    ];
    $i = 0;
    foreach ($rows as $label => $val) {
        $bg = ($i % 2 === 0) ? '#ffffff' : '#f8fafc';
        $html .= '                <tr bgcolor="' . $bg . '" style="background-color: ' . $bg . ';">' . "\r\n";
        $html .= '                  <td width="38%" style="padding: 8px 12px; border-bottom: 1px solid #e2e8f0; font-size: 12px; font-weight: 700; color: #64748b;">' . $label . '</td>' . "\r\n";
        $html .= '                  <td width="62%" style="padding: 8px 12px; border-bottom: 1px solid #e2e8f0; font-size: 13px; color: #0f172a;">' . $val . '</td>' . "\r\n";
        $html .= '                </tr>' . "\r\n";
        $i++;
    }
    $html .= '              </table></td></tr>' . "\r\n";

    $html .= '          <tr><td bgcolor="#f8fafc" style="background-color: #f8fafc; padding: 14px 28px; border-top: 1px solid #e2e8f0; font-size: 11px; color: #64748b;">FAA 14 CFR Part 107 Automated Bulletin.</td></tr>' . "\r\n";
    $html .= '        </table></td></tr></table></body></html>' . "\r\n";

    return $html;
}

function buildFlightNotificationText(array $flight): string {
    $localTzName = date('T');
    $body = "====================================================\r\n";
    $body .= "FAA PART 107 OFFICIAL UAS FLIGHT NOTIFICATION\r\n";
    $body .= "====================================================\r\n\r\n";
    $body .= "Flight Plan Number      : " . $flight['flight_number'] . "\r\n";
    $body .= "Target Facility         : " . $flight['facility_name'] . "\r\n";
    $body .= "Scheduled Date          : " . $flight['flight_date'] . "\r\n";
    $body .= "Operational Window      : " . $flight['start_time'] . " to " . $flight['end_time'] . " Local (" . $localTzName . ")\r\n";
    $body .= "Max Authorized Altitude : " . $flight['max_altitude'] . " ft AGL\r\n";
    $body .= "Remote Pilot in Command : " . $flight['pilot_name'] . "\r\n";
    return $body;
}

function buildPostFlightReminderHtml(array $flight, array $creator): string {
    $flightAreaImageBase64 = generateFlightAreaImageBase64($flight);
    $localTzName = date('T');
    $appUrl = (isset($_SERVER['HTTP_HOST']) ? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST']) : '') . dirname($_SERVER['PHP_SELF'] ?? '');
    $logUrl = rtrim($appUrl, '/') . '/flight_log.php?flight_id=' . $flight['id'];

    $html = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">' . "\r\n";
    $html .= '<html xmlns="http://www.w3.org/1999/xhtml"><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8" /></head>' . "\r\n";
    $html .= '<body style="margin: 0; padding: 20px; background-color: #f0f4f8; font-family: Arial, sans-serif;">' . "\r\n";
    $html .= '  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f0f4f8;"><tr><td align="center">' . "\r\n";
    $html .= '      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: 640px; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden;">' . "\r\n";
    $html .= '        <tr><td bgcolor="#b45309" style="padding: 20px 28px; color:#fff;"><h2>Reminder: Complete Post-Flight Debrief Log</h2></td></tr>' . "\r\n";
    $html .= '        <tr><td style="padding: 20px 28px;">' . "\r\n";
    $html .= '          <p>Hello <strong>' . htmlspecialchars($creator['full_name']) . '</strong>,</p>' . "\r\n";
    $html .= '          <p>The post-flight telemetry and Part 107 log for flight plan <strong>' . htmlspecialchars($flight['flight_number']) . '</strong> scheduled on <strong>' . htmlspecialchars($flight['flight_date']) . '</strong> has not yet been recorded (> 24 hours elapsed).</p>' . "\r\n";
    $html .= '          <div style="text-align: center; margin: 20px 0;"><a href="' . htmlspecialchars($logUrl) . '" style="background:#0284c7; color:#fff; padding:10px 20px; text-decoration:none; border-radius:5px; font-weight:bold;">Submit Post-Flight Log Now</a></div>' . "\r\n";
    $html .= '        </td></tr>' . "\r\n";
    $html .= '        <tr><td align="center" style="padding: 0 28px 20px 28px;"><img src="' . $flightAreaImageBase64 . '" alt="Flight Area" width="584" style="width:100%; max-width:584px; display:block; border-radius:4px;" /></td></tr>' . "\r\n";
    $html .= '      </table></td></tr></table></body></html>' . "\r\n";
    return $html;
}

function buildPostFlightReminderText(array $flight, array $creator): string {
    $localTzName = date('T');
    $body = "REMINDER: POST-FLIGHT LOG REQUIRED\r\n\r\n";
    $body .= "Hello " . $creator['full_name'] . ",\r\n";
    $body .= "Please complete the post-flight log for flight " . $flight['flight_number'] . " scheduled on " . $flight['flight_date'] . " (" . $localTzName . ").\r\n";
    return $body;
}

function sendSmtpMail(array $to, string $subject, string $htmlBody, string $textBody = '', ?string &$errorOutput = null): bool {
    $host = getSystemSetting('smtp_host', '127.0.0.1');
    $port = (int)getSystemSetting('smtp_port', '25');
    $auth = getSystemSetting('smtp_auth', '0') === '1';
    $user = getSystemSetting('smtp_user', '');
    $pass = getSystemSetting('smtp_pass', '');
    $secure = getSystemSetting('smtp_secure', 'none');
    $from = getSystemSetting('mail_from', 'flight-ops@uas-part107.com');
    $fromName = getSystemSetting('mail_from_name', 'FAA Part 107 Flight Operations');

    if (empty($host) || empty($to)) {
        $errorOutput = "SMTP configuration incomplete or no recipients specified.";
        return false;
    }

    $socketHost = ($secure === 'ssl') ? "ssl://{$host}" : $host;
    $timeout = 2;
    $errno = 0;
    $errstr = '';

    $socket = @fsockopen($socketHost, $port, $errno, $errstr, $timeout);
    if (!$socket) {
        $errorOutput = "Failed to connect to SMTP host {$host}:{$port} ({$errstr})";
        return false;
    }

    stream_set_timeout($socket, 2);

    $getResponse = function() use ($socket) {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') break;
            $info = stream_get_meta_data($socket);
            if ($info['timed_out']) break;
        }
        return $response;
    };

    $sendCommand = function($cmd) use ($socket, $getResponse) {
        fputs($socket, $cmd . "\r\n");
        return $getResponse();
    };

    $greeting = $getResponse();
    if (substr($greeting, 0, 3) !== '220') {
        $errorOutput = "Server greeting failed: " . trim($greeting);
        fclose($socket);
        return false;
    }

    $helloResp = $sendCommand("EHLO " . gethostname());
    if (substr($helloResp, 0, 3) !== '250') {
        $sendCommand("HELO " . gethostname());
    }

    if ($secure === 'tls') {
        $tlsResp = $sendCommand("STARTTLS");
        if (substr($tlsResp, 0, 3) === '220') {
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $sendCommand("EHLO " . gethostname());
        }
    }

    if ($auth && !empty($user)) {
        $sendCommand("AUTH LOGIN");
        $sendCommand(base64_encode($user));
        $passResp = $sendCommand(base64_encode($pass));
        if (substr($passResp, 0, 3) !== '235') {
            $errorOutput = "SMTP Authentication failed: " . trim($passResp);
            fclose($socket);
            return false;
        }
    }

    $mailFromResp = $sendCommand("MAIL FROM:<{$from}>");
    if (substr($mailFromResp, 0, 3) !== '250') {
        $errorOutput = "MAIL FROM rejected: " . trim($mailFromResp);
        fclose($socket);
        return false;
    }

    foreach ($to as $recipient) {
        $rcptResp = $sendCommand("RCPT TO:<{$recipient}>");
        if (substr($rcptResp, 0, 3) !== '250' && substr($rcptResp, 0, 3) !== '251') {
            $errorOutput = "Recipient <{$recipient}> rejected: " . trim($rcptResp);
            fclose($socket);
            return false;
        }
    }

    $dataResp = $sendCommand("DATA");
    if (substr($dataResp, 0, 3) !== '354') {
        $errorOutput = "DATA command rejected: " . trim($dataResp);
        fclose($socket);
        return false;
    }

    $boundary = "==Part107_MIME_" . md5(uniqid());
    $headers = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>\r\n" .
               "To: " . implode(', ', $to) . "\r\n" .
               "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n" .
               "Date: " . date('r') . "\r\n" .
               "MIME-Version: 1.0\r\n" .
               "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n" .
               "X-Mailer: Part107-UAS-Platform\r\n\r\n";

    $mimeContent = "--{$boundary}\r\n" .
                   "Content-Type: text/plain; charset=UTF-8\r\n" .
                   "Content-Transfer-Encoding: 8bit\r\n\r\n" .
                   $textBody . "\r\n\r\n" .
                   "--{$boundary}\r\n" .
                   "Content-Type: text/html; charset=UTF-8\r\n" .
                   "Content-Transfer-Encoding: 8bit\r\n\r\n" .
                   $htmlBody . "\r\n\r\n" .
                   "--{$boundary}--\r\n";

    fputs($socket, $headers . $mimeContent . "\r\n.\r\n");
    $resultResp = $getResponse();
    $sendCommand("QUIT");
    fclose($socket);

    return (substr($resultResp, 0, 3) === '250');
}

function sendFlightNotification(int $flightId, array $adHocEmails = []): bool {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT f.*, u.full_name AS pilot_name, u.part107_cert_num, u.phone AS pilot_phone, u.email AS pilot_email
        FROM flights f
        JOIN users u ON f.pilot_id = u.id
        WHERE f.id = ?
    ");
    $stmt->execute([$flightId]);
    $flight = $stmt->fetch();
    if (!$flight) return false;

    $mandatoryList = $pdo->query("SELECT email FROM distribution_recipients ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
    $flightStoredExtras = !empty($flight['additional_emails']) ? explode(',', $flight['additional_emails']) : [];
    $allRecipients = array_merge($mandatoryList, $flightStoredExtras, $adHocEmails);

    $recipients = [];
    foreach ($allRecipients as $em) {
        $emClean = trim($em);
        if (filter_var($emClean, FILTER_VALIDATE_EMAIL)) {
            $recipients[] = strtolower($emClean);
        }
    }
    $recipients = array_values(array_unique($recipients));

    if (empty($recipients)) return false;

    $subject = "FAA PART 107 FLIGHT AUTHORIZATION NOTIFICATION: " . $flight['flight_number'] . " [" . $flight['facility_name'] . "]";
    $htmlBody = buildFlightNotificationHtml($flight, $recipients);
    $textBody = buildFlightNotificationText($flight);
    $errorOutput = null;

    $sent = sendSmtpMail($recipients, $subject, $htmlBody, $textBody, $errorOutput);

    $toHeader = implode(', ', $recipients);
    $localNow = date('Y-m-d H:i:s');
    $logStmt = $pdo->prepare("INSERT INTO notification_logs (flight_id, recipients, sent_subject, sent_body, sent_at) VALUES (?, ?, ?, ?, ?)");
    $logStmt->execute([$flightId, $toHeader, $subject, $textBody, $localNow]);

    return $sent;
}

function checkAndSendPostFlightReminders(): int {
    $pdo = getDB();
    $sentCount = 0;

    $sql = "
        SELECT f.*, 
               creator.full_name AS creator_name, 
               creator.email AS creator_email,
               pilot.full_name AS pilot_name
        FROM flights f
        JOIN users creator ON COALESCE(f.created_by, f.pilot_id) = creator.id
        JOIN users pilot ON f.pilot_id = pilot.id
        LEFT JOIN flight_logs fl ON fl.flight_id = f.id
        WHERE fl.id IS NULL
          AND (f.reminder_sent_at IS NULL OR f.reminder_sent_at = '')
    ";

    $flights = $pdo->query($sql)->fetchAll();
    $todayTs = strtotime(date('Y-m-d'));

    foreach ($flights as $f) {
        $flightDateTs = strtotime($f['flight_date']);
        $oneDayAfterTs = strtotime('+1 day', $flightDateTs);

        if ($todayTs > $oneDayAfterTs) {
            $recipientEmail = trim($f['creator_email']);
            if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $creator = [
                'full_name' => $f['creator_name'],
                'email'     => $recipientEmail
            ];

            $subject = "ACTION REQUIRED: Complete Post-Flight Log for " . $f['flight_number'] . " [" . $f['facility_name'] . "]";
            $htmlBody = buildPostFlightReminderHtml($f, $creator);
            $textBody = buildPostFlightReminderText($f, $creator);
            $err = null;

            if (sendSmtpMail([$recipientEmail], $subject, $htmlBody, $textBody, $err)) {
                $localNow = date('Y-m-d H:i:s');
                $pdo->prepare("UPDATE flights SET reminder_sent_at = ? WHERE id = ?")->execute([$localNow, $f['id']]);

                $logStmt = $pdo->prepare("INSERT INTO notification_logs (flight_id, recipients, sent_subject, sent_body, sent_at) VALUES (?, ?, ?, ?, ?)");
                $logStmt->execute([$f['id'], $recipientEmail, $subject, $textBody, $localNow]);
                $sentCount++;
            }
        }
    }

    return $sentCount;
}
