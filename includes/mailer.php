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

function mercatorLon2X(float $lon, int $zoom): float {
    return (($lon + 180.0) / 360.0) * (256 * (1 << $zoom));
}

function mercatorLat2Y(float $lat, int $zoom): float {
    $latRad = deg2rad($lat);
    return (1.0 - (log(tan($latRad) + (1.0 / cos($latRad))) / M_PI)) / 2.0 * (256 * (1 << $zoom));
}

function fetchSingleTile(string $url): string {
    if (function_exists('curl_init')) {
        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => [
                'Accept: image/jpeg,image/png,image/*;q=0.8',
                'Referer: https://clarity.maptiles.arcgis.com/'
            ]
        ];

        $proxy = getenv('HTTP_PROXY') ?: getenv('http_proxy');
        if ($proxy) {
            $opts[CURLOPT_PROXY] = $proxy;
        }

        curl_setopt_array($ch, $opts);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && !empty($data) && strlen($data) > 500) {
            return $data;
        }
    }

    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 4,
                'header'  => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\nReferer: https://clarity.maptiles.arcgis.com/\r\n"
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false
            ]
        ]);
        $data = @file_get_contents($url, false, $ctx);
        if (!empty($data) && strlen($data) > 500) {
            return $data;
        }
    }

    return '';
}

function generateFlightAreaImageBinary(array $flight): string {
    if (!empty($flight['map_image_base64'])) {
        $clean = preg_replace('#^data:image/[^;]+;base64,#i', '', trim($flight['map_image_base64']));
        if (strlen($clean) > 200) {
            $decoded = base64_decode($clean);
            if (!empty($decoded) && strlen($decoded) > 500) {
                return $decoded;
            }
        }
    }

    if (!function_exists('imagecreatetruecolor')) {
        return '';
    }

    $width = 584;
    $height = 320;

    $lat = (float)($flight['latitude'] ?? 37.115191);
    $lng = (float)($flight['longitude'] ?? -88.811224);

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

    $optimalZoom = 15;
    for ($z = 17; $z >= 13; $z--) {
        $wPx = abs(mercatorLon2X($maxLng, $z) - mercatorLon2X($minLng, $z));
        $hPx = abs(mercatorLat2Y($maxLat, $z) - mercatorLat2Y($minLat, $z));
        if ($wPx <= ($width * 0.65) && $hPx <= ($height * 0.65)) {
            $optimalZoom = $z;
            break;
        }
    }
    $optimalZoom = min(17, max(13, $optimalZoom));

    $img = imagecreatetruecolor($width, $height);
    imagealphablending($img, true);
    imagesavealpha($img, true);

    $bgDark = imagecolorallocate($img, 15, 23, 42);
    $gridLine = imagecolorallocate($img, 30, 41, 59);
    $cyan = imagecolorallocate($img, 0, 210, 255);
    $cyanFill = imagecolorallocatealpha($img, 0, 210, 255, 80);
    $white = imagecolorallocate($img, 248, 250, 252);
    $green = imagecolorallocate($img, 34, 197, 94);
    $yellow = imagecolorallocate($img, 245, 158, 11);
    $hudBg = imagecolorallocatealpha($img, 15, 23, 42, 30);
    $cardBorder = imagecolorallocate($img, 51, 65, 85);
    $shadow = imagecolorallocatealpha($img, 0, 0, 0, 60);

    $centerX = mercatorLon2X($centerLng, $optimalZoom);
    $centerY = mercatorLat2Y($centerLat, $optimalZoom);

    $imgMinX = $centerX - ($width / 2.0);
    $imgMinY = $centerY - ($height / 2.0);

    $tileMinX = (int)floor($imgMinX / 256.0);
    $tileMaxX = (int)floor(($imgMinX + $width) / 256.0);
    $tileMinY = (int)floor($imgMinY / 256.0);
    $tileMaxY = (int)floor(($imgMinY + $height) / 256.0);

    $cacheDir = defined('DATA_PATH') ? (DATA_PATH . '/tile_cache') : (__DIR__ . '/../data/tile_cache');
    if (!file_exists($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }

    $loadedSatCount = 0;

    for ($tx = $tileMinX; $tx <= $tileMaxX; $tx++) {
        for ($ty = $tileMinY; $ty <= $tileMaxY; $ty++) {
            $cacheFile = $cacheDir . "/clarity_{$optimalZoom}_{$ty}_{$tx}.jpg";
            $tileRaw = '';

            if (file_exists($cacheFile) && filesize($cacheFile) > 500) {
                $tileRaw = file_get_contents($cacheFile);
            } else {
                // Top-down nadir Clarity Satellite layer
                $tileUrl = "https://clarity.maptiles.arcgis.com/arcgis/rest/services/World_Imagery/MapServer/tile/{$optimalZoom}/{$ty}/{$tx}";
                $tileRaw = fetchSingleTile($tileUrl);

                if (empty($tileRaw)) {
                    $tileUrl = "https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{$optimalZoom}/{$ty}/{$tx}";
                    $tileRaw = fetchSingleTile($tileUrl);
                }

                if (!empty($tileRaw)) {
                    @file_put_contents($cacheFile, $tileRaw);
                }
            }

            if (!empty($tileRaw)) {
                $tileImg = @imagecreatefromstring($tileRaw);
                if ($tileImg) {
                    $destX = (int)round(($tx * 256.0) - $imgMinX);
                    $destY = (int)round(($ty * 256.0) - $imgMinY);

                    $srcX = 0;
                    $srcY = 0;
                    $srcW = 256;
                    $srcH = 256;

                    if ($destX < 0) {
                        $srcX = -$destX;
                        $srcW -= $srcX;
                        $destX = 0;
                    }
                    if ($destY < 0) {
                        $srcY = -$destY;
                        $srcH -= $srcY;
                        $destY = 0;
                    }
                    if ($destX + $srcW > $width) {
                        $srcW = $width - $destX;
                    }
                    if ($destY + $srcH > $height) {
                        $srcH = $height - $destY;
                    }

                    if ($srcW > 0 && $srcH > 0) {
                        imagecopy($img, $tileImg, $destX, $destY, $srcX, $srcY, $srcW, $srcH);
                        $loadedSatCount++;
                    }
                    imagedestroy($tileImg);
                }
            }
        }
    }

    if ($loadedSatCount === 0) {
        imagefilledrectangle($img, 0, 0, $width, $height, $bgDark);
        for ($x = 0; $x < $width; $x += 35) {
            imageline($img, $x, 0, $x, $height, $gridLine);
        }
        for ($y = 0; $y < $height; $y += 35) {
            imageline($img, 0, $y, $width, $y, $gridLine);
        }
    }

    $projectX = function($lon) use ($optimalZoom, $imgMinX) {
        return (int)round(mercatorLon2X($lon, $optimalZoom) - $imgMinX);
    };
    $projectY = function($lat) use ($optimalZoom, $imgMinY) {
        return (int)round(mercatorLat2Y($lat, $optimalZoom) - $imgMinY);
    };

    if (!empty($rawCoords) && count($rawCoords) >= 3) {
        $polyPoints = [];
        foreach ($rawCoords as $c) {
            $polyPoints[] = $projectX($c[0]);
            $polyPoints[] = $projectY($c[1]);
        }
        imagefilledpolygon($img, $polyPoints, $cyanFill);
        imagesetthickness($img, 3);
        for ($i = 0; $i < count($rawCoords) - 1; $i++) {
            imageline($img, $projectX($rawCoords[$i][0]), $projectY($rawCoords[$i][1]), $projectX($rawCoords[$i+1][0]), $projectY($rawCoords[$i+1][1]), $cyan);
        }
        imagesetthickness($img, 1);
    }

    $centroidX = $projectX($lng);
    $centroidY = $projectY($lat);

    imagesetthickness($img, 3);
    imageline($img, $centroidX - 13, $centroidY, $centroidX + 13, $centroidY, $shadow);
    imageline($img, $centroidX, $centroidY - 13, $centroidX, $centroidY + 13, $shadow);
    imageellipse($img, $centroidX, $centroidY, 19, 19, $shadow);

    imagesetthickness($img, 2);
    imageline($img, $centroidX - 12, $centroidY, $centroidX + 12, $centroidY, $cyan);
    imageline($img, $centroidX, $centroidY - 12, $centroidX, $centroidY + 12, $cyan);
    imageellipse($img, $centroidX, $centroidY, 18, 18, $cyan);
    imagefilledellipse($img, $centroidX, $centroidY, 5, 5, $white);
    imagesetthickness($img, 1);

    imagefilledrectangle($img, 10, 10, 310, 48, $hudBg);
    imagerectangle($img, 10, 10, 310, 48, $cardBorder);
    imagestring($img, 3, 18, 15, "FLIGHT AREA BOUNDARY MAP", $cyan);
    $acres = $flight['operational_acres'] ?? 0;
    $alt = $flight['max_altitude'] ?? 0;
    imagestring($img, 2, 18, 30, "Footprint: {$acres} Ac | Ceiling: {$alt} ft AGL", $white);

    imagefilledrectangle($img, $width - 44, 10, $width - 10, 48, $hudBg);
    imagerectangle($img, $width - 44, 10, $width - 10, 48, $cardBorder);
    imagestring($img, 3, $width - 32, 14, "N", $white);
    imageline($img, $width - 27, 28, $width - 27, 39, $cyan);

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
    return $binary;
}

function generateFlightAreaSvgRaw(array $flight): string {
    $width = 584;
    $height = 320;

    $lat = (float)($flight['latitude'] ?? 37.115191);
    $lng = (float)($flight['longitude'] ?? -88.811224);
    $acres = htmlspecialchars((string)($flight['operational_acres'] ?? '0.00'));
    $alt = htmlspecialchars((string)($flight['max_altitude'] ?? '200'));
    $airspaceClass = htmlspecialchars((string)($flight['airspace_class'] ?? 'G'));

    $pointsSvg = '';
    $rawCoords = [];
    if (!empty($flight['polygon_geojson'])) {
        $geo = @json_decode($flight['polygon_geojson'], true);
        if (!empty($geo['coordinates'][0]) && is_array($geo['coordinates'][0])) {
            $rawCoords = $geo['coordinates'][0];
        }
    }

    if (!empty($rawCoords)) {
        $lats = array_column($rawCoords, 1);
        $lngs = array_column($rawCoords, 0);
        $minLat = min($lats);
        $maxLat = max($lats);
        $minLng = min($lngs);
        $maxLng = max($lngs);

        $spanLat = max(0.0001, $maxLat - $minLat);
        $spanLng = max(0.0001, $maxLng - $minLng);

        $svgCoords = [];
        foreach ($rawCoords as $c) {
            $px = 60 + (($c[0] - $minLng) / $spanLng) * ($width - 120);
            $py = ($height - 60) - (($c[1] - $minLat) / $spanLat) * ($height - 120);
            $svgCoords[] = round($px, 1) . ',' . round($py, 1);
        }
        $pointsSvg = implode(' ', $svgCoords);
    }

    $cx = round($width / 2, 1);
    $cy = round($height / 2, 1);

    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $height . '" width="' . $width . '" height="' . $height . '" style="background:#0f172a; font-family:sans-serif;">' .
           '<defs><pattern id="grid" width="35" height="35" patternUnits="userSpaceOnUse"><path d="M 35 0 L 0 0 0 35" fill="none" stroke="#1e293b" stroke-width="1"/></pattern></defs>' .
           '<rect width="' . $width . '" height="' . $height . '" fill="#0f172a"/>' .
           '<rect width="' . $width . '" height="' . $height . '" fill="url(#grid)"/>' .
           (!empty($pointsSvg) ? '<polygon points="' . $pointsSvg . '" fill="rgba(0, 210, 255, 0.25)" stroke="#00d2ff" stroke-width="3" stroke-linejoin="round"/>' : '') .
           '<circle cx="' . $cx . '" cy="' . $cy . '" r="9" stroke="#00d2ff" stroke-width="2" fill="none"/>' .
           '<line x1="' . ($cx - 13) . '" y1="' . $cy . '" x2="' . ($cx + 13) . '" y2="' . $cy . '" stroke="#00d2ff" stroke-width="2"/>' .
           '<line x1="' . $cx . '" y1="' . ($cy - 13) . '" x2="' . $cx . '" y2="' . ($cy + 13) . '" stroke="#00d2ff" stroke-width="2"/>' .
           '<circle cx="' . $cx . '" cy="' . $cy . '" r="2.5" fill="#ffffff"/>' .
           '<rect x="10" y="10" width="310" height="42" rx="4" fill="rgba(15, 23, 42, 0.85)" stroke="#334155" stroke-width="1"/>' .
           '<text x="20" y="27" fill="#00d2ff" font-size="12" font-weight="bold">FLIGHT AREA BOUNDARY MAP</text>' .
           '<text x="20" y="43" fill="#f8fafc" font-size="10">Footprint: ' . $acres . ' Ac | Ceiling: ' . $alt . ' ft AGL</text>' .
           '<rect x="' . ($width - 44) . '" y="10" width="34" height="42" rx="4" fill="rgba(15, 23, 42, 0.85)" stroke="#334155" stroke-width="1"/>' .
           '<text x="' . ($width - 32) . '" y="27" fill="#ffffff" font-size="12" font-weight="bold">N</text>' .
           '<line x1="' . ($width - 27) . '" y1="' . 31 . '" x2="' . ($width - 27) . '" y2="' . 42 . '" stroke="#00d2ff" stroke-width="2"/>' .
           '<rect x="10" y="' . ($height - 34) . '" width="' . ($width - 20) . '" height="26" rx="4" fill="rgba(15, 23, 42, 0.85)" stroke="#334155" stroke-width="1"/>' .
           '<text x="20" y="' . ($height - 17) . '" fill="#f8fafc" font-size="10">Centroid: ' . round($lat, 6) . ', ' . round($lng, 6) . '</text>' .
           '<text x="' . ($width - 140) . '" y="' . ($height - 17) . '" fill="' . ($airspaceClass === 'G' ? '#22c55e' : '#f59e0b') . '" font-size="10" font-weight="bold">Airspace: Class ' . $airspaceClass . '</text>' .
           '</svg>';
}

function generateFlightAreaImageBase64(array $flight): string {
    if (!empty($flight['map_image_base64'])) {
        $clean = preg_replace('#^data:image/[^;]+;base64,#i', '', trim($flight['map_image_base64']));
        if (strlen($clean) > 200) {
            return 'data:image/jpeg;base64,' . $clean;
        }
    }

    $binary = generateFlightAreaImageBinary($flight);
    if (!empty($binary) && strlen($binary) > 200) {
        return 'data:image/png;base64,' . base64_encode($binary);
    }

    return 'data:image/svg+xml;base64,' . base64_encode(generateFlightAreaSvgRaw($flight));
}

function buildFlightNotificationHtml(array $flight, array $recipients, ?string $imageSrc = null): string {
    if (empty($imageSrc)) {
        $imageSrc = generateFlightAreaImageBase64($flight);
    }

    $laancBadgeColor = (!empty($flight['laanc_auth_number']) || $flight['laanc_status'] === 'AUTO_APPROVED') ? '#16a34a' : '#d97706';
    $laancBadgeBg = (!empty($flight['laanc_auth_number']) || $flight['laanc_status'] === 'AUTO_APPROVED') ? '#dcfce7' : '#fef3c7';
    $laancText = !empty($flight['laanc_auth_number']) ? htmlspecialchars($flight['laanc_auth_number']) : ($flight['airspace_class'] === 'G' ? 'NOT REQUIRED (CLASS G)' : 'PENDING');
    $localTzName = date('T');

    $html = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">' . "\r\n";
    $html .= '<html xmlns="http://www.w3.org/1999/xhtml">' . "\r\n";
    $html .= '<head>' . "\r\n";
    $html .= '  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />' . "\r\n";
    $html .= '  <meta name="viewport" content="width=device-width, initial-scale=1.0" />' . "\r\n";
    $html .= '  <title>' . htmlspecialchars($flight['flight_number']) . '</title>' . "\r\n";
    $html .= '</head>' . "\r\n";
    $html .= '<body style="margin: 0; background-color: #f0f4f8; font-family: Arial, Helvetica, sans-serif;">' . "\r\n";
    $html .= '  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f0f4f8;">' . "\r\n";
    $html .= '    <tr><td align="center">' . "\r\n";
    $html .= '        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: 640px; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden;">' . "\r\n";
    
    // Header Banner
    $html .= '          <tr><td bgcolor="#0f172a" style="background-color: #0f172a; padding: 22px 28px; border-bottom: 3px solid #0284c7;">' . "\r\n";
    $html .= '              <table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>' . "\r\n";
    $html .= '                  <td><div style="color: #38bdf8; font-size: 11px; font-weight: 700; text-transform: uppercase;">FRNP Drone Flight Plan</div>' . "\r\n";
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

    // Embedded Top-Down Satellite Map
    $html .= '          <tr><td style="padding: 10px 28px;">' . "\r\n";
    $html .= '              <table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#0f172a" style="background-color: #0f172a; border-radius: 6px; overflow: hidden; border: 1px solid #334155;">' . "\r\n";
    $html .= '                <tr><td align="center" style="padding: 0; background-color: #090d16;">' . "\r\n";
    $html .= '                    <img src="' . $imageSrc . '" alt="Flight Area Map" width="584" style="width: 100%; max-width: 584px; height: auto; display: block; border: 0;" />' . "\r\n";
    $html .= '                </td></tr></table></td></tr>' . "\r\n";

    // Mission Scope
    $html .= '          <tr><td style="padding: 10px 28px;">' . "\r\n";
    $html .= '              <table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f8fafc" style="background-color: #f8fafc; border-left: 4px solid #0284c7; border-radius: 4px; border: 1px solid #e2e8f0;">' . "\r\n";
    $html .= '                <tr><td style="padding: 14px 18px;">' . "\r\n";
    $html .= '                    <div style="font-size: 11px; font-weight: 700; color: #0369a1; text-transform: uppercase; margin-bottom: 4px;">Mission Scope</div>' . "\r\n";
    $html .= '                    <div style="font-size: 13px; line-height: 18px; color: #1e293b; font-weight: 600;">' . nl2br(htmlspecialchars($flight['mission_purpose'])) . '</div>' . "\r\n";
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
        'UAS Aircraft Model'      => htmlspecialchars($flight['aircraft_model'])
    ];

    if (!empty($flight['faa_reg_number'])) {
        $regDetails = htmlspecialchars($flight['faa_reg_number']);
        if (!empty($flight['faa_reg_expiration'])) {
            $regDetails .= ' (Expires: ' . htmlspecialchars($flight['faa_reg_expiration']) . ')';
        }
        $rows['FAA Drone Registration'] = $regDetails;
    }

    if (!empty($flight['payload'])) {
        $rows['Payload Equipment'] = htmlspecialchars($flight['payload']);
    }

    $rows['Remote Pilot in Command'] = htmlspecialchars($flight['pilot_name']);
    $rows['Emergency Phone Contact'] = htmlspecialchars($flight['pilot_phone'] ?: 'N/A');
    $rows['RPIC Email Contact']      = htmlspecialchars($flight['pilot_email'] ?: 'N/A');

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

    $html .= '          <tr><td bgcolor="#f8fafc" style="background-color: #f8fafc; padding: 14px 28px; border-top: 1px solid #e2e8f0; font-size: 11px; color: #64748b;">FRNP Drone Flight Management System</td></tr>' . "\r\n";
    $html .= '        </table></td></tr></table></body></html>' . "\r\n";

    return $html;
}

function buildFlightNotificationText(array $flight): string {
    $localTzName = date('T');
    $body = "====================================================\r\n";
    $body .= "FAA OFFICIAL UAS FLIGHT NOTIFICATION\r\n";
    $body .= "====================================================\r\n\r\n";
    $body .= "Flight Plan Number      : " . $flight['flight_number'] . "\r\n";
    $body .= "Work Package            : " . $flight['work_package'] . "\r\n";
    $body .= "Target Facility         : " . $flight['facility_name'] . "\r\n";
    $body .= "Airspace Classification : Class " . $flight['airspace_class'] . "\r\n";
    $body .= "Centroid Coordinates    : " . $flight['latitude'] . ", " . $flight['longitude'] . "\r\n";
    $body .= "Operating Footprint     : " . $flight['operational_acres'] . " Acres\r\n";
    $body .= "Scheduled Date          : " . $flight['flight_date'] . "\r\n";
    $body .= "Operational Window      : " . $flight['start_time'] . " to " . $flight['end_time'] . " Local (" . $localTzName . ")\r\n";
    $body .= "Max Authorized Altitude : " . $flight['max_altitude'] . " ft AGL\r\n";
    $body .= "UAS Aircraft Model      : " . $flight['aircraft_model'] . "\r\n";

    if (!empty($flight['faa_reg_number'])) {
        $body .= "FAA Drone Registration  : " . $flight['faa_reg_number'] . (!empty($flight['faa_reg_expiration']) ? " (Expires: " . $flight['faa_reg_expiration'] . ")" : "") . "\r\n";
    }

    if (!empty($flight['payload'])) {
        $body .= "Payload Equipment       : " . $flight['payload'] . "\r\n";
    }

    $body .= "Remote Pilot in Command : " . $flight['pilot_name'] . "\r\n";
    $body .= "Emergency Phone Contact : " . ($flight['pilot_phone'] ?: 'N/A') . "\r\n";
    $body .= "Pilot Email             : " . ($flight['pilot_email'] ?: 'N/A') . "\r\n";
    return $body;
}

function buildPostFlightReminderHtml(array $flight, array $creator, ?string $imageSrc = null): string {
    if (empty($imageSrc)) {
        $imageSrc = generateFlightAreaImageBase64($flight);
    }

    $localTzName = date('T');
    $appUrl = (isset($_SERVER['HTTP_HOST']) ? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST']) : '') . dirname($_SERVER['PHP_SELF'] ?? '');
    $logUrl = rtrim($appUrl, '/') . '/flight_log.php?flight_id=' . $flight['id'];

    $html = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">' . "\r\n";
    $html .= '<html xmlns="http://www.w3.org/1999/xhtml"><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8" /></head>' . "\r\n";
    $html .= '<body style="margin: 0; background-color: #f0f4f8; font-family: Arial, sans-serif;">' . "\r\n";
    $html .= '  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f0f4f8;"><tr><td align="center">' . "\r\n";
    $html .= '      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: 640px; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden;">' . "\r\n";
    $html .= '        <tr><td bgcolor="#b45309" style="padding: 20px 28px; color:#fff;"><h2>Reminder: Complete Post-Flight Debrief Log</h2></td></tr>' . "\r\n";
    $html .= '        <tr><td style="padding: 20px 28px;">' . "\r\n";
    $html .= '          <p>Hello <strong>' . htmlspecialchars($creator['full_name']) . '</strong>,</p>' . "\r\n";
    $html .= '          <p>The post-flight telemetry log for flight plan <strong>' . htmlspecialchars($flight['flight_number']) . '</strong> scheduled on <strong>' . htmlspecialchars($flight['flight_date']) . '</strong> has not yet been recorded (> 24 hours elapsed).</p>' . "\r\n";
    $html .= '          <div style="text-align: center; margin: 20px 0;"><a href="' . htmlspecialchars($logUrl) . '" style="background:#0284c7; color:#fff; padding:10px 20px; text-decoration:none; border-radius:5px; font-weight:bold;">Submit Post-Flight Log Now</a></div>' . "\r\n";
    $html .= '        </td></tr>' . "\r\n";
    $html .= '        <tr><td align="center" style="padding: 0 28px 20px 28px;"><img src="' . $imageSrc . '" alt="Flight Area" width="584" style="width:100%; max-width:584px; display:block; border-radius:4px;" /></td></tr>' . "\r\n";
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

function sendSmtpMail(array $to, string $subject, string $htmlBody, string $textBody = '', ?string &$errorOutput = null, array $inlineImages = []): bool {
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
        logSystemError('SMTP', $errorOutput, "Host: {$host}, Recipients: " . implode(', ', $to));
        return false;
    }

    $socketHost = ($secure === 'ssl') ? "ssl://{$host}" : $host;
    $timeout = 10;
    $errno = 0;
    $errstr = '';

    $socket = @fsockopen($socketHost, $port, $errno, $errstr, $timeout);
    if (!$socket) {
        $errorOutput = "Cannot connect to SMTP host {$host}:{$port} ({$errstr})";
        logSystemError('SMTP', $errorOutput, "Socket: {$socketHost}:{$port}, ErrorNo: {$errno}, ErrorStr: {$errstr}");
        return false;
    }

    stream_set_timeout($socket, 15);

    $transcript = [];
    $getResponse = function() use ($socket, &$transcript) {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            $transcript[] = "< " . trim($line);
            if (substr($line, 3, 1) === ' ') break;
            $info = stream_get_meta_data($socket);
            if ($info['timed_out']) break;
        }
        return $response;
    };

    $sendCommand = function($cmd, $maskLog = false) use ($socket, $getResponse, &$transcript) {
        $transcript[] = "> " . ($maskLog ? '********' : $cmd);
        fputs($socket, $cmd . "\r\n");
        return $getResponse();
    };

    $greeting = $getResponse();
    if (substr($greeting, 0, 3) !== '220') {
        $errorOutput = "Server greeting failed: " . trim($greeting);
        fclose($socket);
        logSystemError('SMTP', $errorOutput, implode("\n", $transcript));
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
        $passResp = $sendCommand(base64_encode($pass), true);
        if (substr($passResp, 0, 3) !== '235') {
            $errorOutput = "SMTP Authentication failed: " . trim($passResp);
            fclose($socket);
            logSystemError('SMTP', $errorOutput, implode("\n", $transcript));
            return false;
        }
    }

    $mailFromResp = $sendCommand("MAIL FROM:<{$from}>");
    if (substr($mailFromResp, 0, 3) !== '250') {
        $errorOutput = "MAIL FROM <{$from}> rejected: " . trim($mailFromResp);
        fclose($socket);
        logSystemError('SMTP', $errorOutput, implode("\n", $transcript));
        return false;
    }

    foreach ($to as $recipient) {
        $rcptResp = $sendCommand("RCPT TO:<{$recipient}>");
        if (substr($rcptResp, 0, 3) !== '250' && substr($rcptResp, 0, 3) !== '251') {
            $errorOutput = "Recipient <{$recipient}> rejected: " . trim($rcptResp);
            fclose($socket);
            logSystemError('SMTP', $errorOutput, implode("\n", $transcript));
            return false;
        }
    }

    $dataResp = $sendCommand("DATA");
    if (substr($dataResp, 0, 3) !== '354') {
        $errorOutput = "DATA command rejected: " . trim($dataResp);
        fclose($socket);
        logSystemError('SMTP', $errorOutput, implode("\n", $transcript));
        return false;
    }

    $altBoundary = "==Part107_ALT_" . md5(uniqid('alt', true));
    $relBoundary = "==Part107_REL_" . md5(uniqid('rel', true));

    $headers = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>\r\n" .
               "To: " . implode(', ', $to) . "\r\n" .
               "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n" .
               "Date: " . date('r') . "\r\n" .
               "MIME-Version: 1.0\r\n";

    if (!empty($inlineImages)) {
        $headers .= "Content-Type: multipart/related; boundary=\"{$relBoundary}\"\r\n" .
                    "X-Mailer: Part107-UAS-Platform\r\n\r\n";

        $mimeContent = "--{$relBoundary}\r\n" .
                       "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n" .
                       "--{$altBoundary}\r\n" .
                       "Content-Type: text/plain; charset=UTF-8\r\n" .
                       "Content-Transfer-Encoding: 8bit\r\n\r\n" .
                       $textBody . "\r\n\r\n" .
                       "--{$altBoundary}\r\n" .
                       "Content-Type: text/html; charset=UTF-8\r\n" .
                       "Content-Transfer-Encoding: 8bit\r\n\r\n" .
                       $htmlBody . "\r\n\r\n" .
                       "--{$altBoundary}--\r\n\r\n";

        foreach ($inlineImages as $cid => $img) {
            $base64Data = chunk_split(base64_encode($img['data']));
            $mimeContent .= "--{$relBoundary}\r\n" .
                            "Content-Type: {$img['type']}; name=\"{$img['filename']}\"\r\n" .
                            "Content-Transfer-Encoding: base64\r\n" .
                            "Content-ID: <{$cid}>\r\n" .
                            "Content-Disposition: inline; filename=\"{$img['filename']}\"\r\n\r\n" .
                            $base64Data . "\r\n";
        }
        $mimeContent .= "--{$relBoundary}--\r\n";
    } else {
        $headers .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n" .
                    "X-Mailer: Part107-UAS-Platform\r\n\r\n";

        $mimeContent = "--{$altBoundary}\r\n" .
                       "Content-Type: text/plain; charset=UTF-8\r\n" .
                       "Content-Transfer-Encoding: 8bit\r\n\r\n" .
                       $textBody . "\r\n\r\n" .
                       "--{$altBoundary}\r\n" .
                       "Content-Type: text/html; charset=UTF-8\r\n" .
                       "Content-Transfer-Encoding: 8bit\r\n\r\n" .
                       $htmlBody . "\r\n\r\n" .
                       "--{$altBoundary}--\r\n";
    }

    $fullPayload = $headers . $mimeContent . "\r\n.\r\n";
    $payloadLength = strlen($fullPayload);
    $chunkSize = 8192;
    $offset = 0;
    $writeFailed = false;

    while ($offset < $payloadLength) {
        $chunk = substr($fullPayload, $offset, $chunkSize);
        $written = @fwrite($socket, $chunk);
        if ($written === false || $written === 0) {
            $writeFailed = true;
            break;
        }
        $offset += $written;
    }

    if ($writeFailed) {
        $errorOutput = "SSL socket write error while transmitting message payload at byte {$offset} of {$payloadLength}";
        logSystemError('SMTP', $errorOutput, implode("\n", $transcript));
        fclose($socket);
        return false;
    }

    @fflush($socket);

    $resultResp = $getResponse();
    $sendCommand("QUIT");
    fclose($socket);

    return (substr($resultResp, 0, 3) === '250');
}

function sendFlightNotification(int $flightId, array $adHocEmails = [], ?string &$errorOutput = null): bool {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT f.*, u.full_name AS pilot_name, u.phone AS pilot_phone, u.email AS pilot_email
        FROM flights f
        JOIN users u ON f.pilot_id = u.id
        WHERE f.id = ?
    ");
    $stmt->execute([$flightId]);
    $flight = $stmt->fetch();
    if (!$flight) {
        $errorOutput = "Flight record not found.";
        logSystemError('NOTIFICATION', $errorOutput, "Attempted Flight ID: {$flightId}");
        return false;
    }

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

    if (empty($recipients)) {
        $errorOutput = "No valid recipient email addresses configured.";
        logSystemError('NOTIFICATION', $errorOutput, "Flight: {$flight['flight_number']}");
        return false;
    }

    $base64Image = generateFlightAreaImageBase64($flight);
    $imageBinary = generateFlightAreaImageBinary($flight);

    $htmlBody = buildFlightNotificationHtml($flight, $recipients, $base64Image);
    $textBody = buildFlightNotificationText($flight);

    $inlineImages = [];
    if (!empty($imageBinary)) {
        $inlineImages['flight_area_map'] = [
            'data'     => $imageBinary,
            'type'     => 'image/png',
            'filename' => 'flight_boundary.png'
        ];
    }

    $subject = "FAA FLIGHT AUTHORIZATION NOTIFICATION: " . $flight['flight_number'] . " [" . $flight['facility_name'] . "]";

    $sent = sendSmtpMail($recipients, $subject, $htmlBody, $textBody, $errorOutput, $inlineImages);

    if ($sent) {
        $toHeader = implode(', ', $recipients);
        $localNow = date('Y-m-d H:i:s');
        $logStmt = $pdo->prepare("INSERT INTO notification_logs (flight_id, recipients, sent_subject, sent_body, sent_at) VALUES (?, ?, ?, ?, ?)");
        $logStmt->execute([$flightId, $toHeader, $subject, $textBody, $localNow]);
    } else {
        logSystemError('NOTIFICATION', "Notification failed for flight {$flight['flight_number']}: {$errorOutput}", "Flight: {$flight['flight_number']}, Recipients: " . implode(', ', $recipients));
    }

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

            $base64Image = generateFlightAreaImageBase64($f);
            $imageBinary = generateFlightAreaImageBinary($f);
            $htmlBody = buildPostFlightReminderHtml($f, $creator, $base64Image);
            $textBody = buildPostFlightReminderText($f, $creator);

            $inlineImages = [];
            if (!empty($imageBinary)) {
                $inlineImages['flight_area_map'] = [
                    'data'     => $imageBinary,
                    'type'     => 'image/png',
                    'filename' => 'flight_boundary.png'
                ];
            }

            $subject = "ACTION REQUIRED: Complete Post-Flight Log for " . $f['flight_number'] . " [" . $f['facility_name'] . "]";
            $err = null;

            if (sendSmtpMail([$recipientEmail], $subject, $htmlBody, $textBody, $err, $inlineImages)) {
                $localNow = date('Y-m-d H:i:s');
                $pdo->prepare("UPDATE flights SET reminder_sent_at = ? WHERE id = ?")->execute([$localNow, $f['id']]);

                $logStmt = $pdo->prepare("INSERT INTO notification_logs (flight_id, recipients, sent_subject, sent_body, sent_at) VALUES (?, ?, ?, ?, ?)");
                $logStmt->execute([$f['id'], $recipientEmail, $subject, $textBody, $localNow]);
                $sentCount++;
            } else {
                logSystemError('REMINDER', "Failed sending post-flight reminder for flight {$f['flight_number']}: {$err}", "Recipient: {$recipientEmail}");
            }
        }
    }

    return $sentCount;
}
