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
 * Retrieves the client-captured screenshot base64 string directly from the flight record.
 * Zero server-side mapping, tile stitching, or SVG synthesis.
 */
function generateFlightAreaImageBase64(array $flight): string {
    $raw = trim($flight['map_image_base64'] ?? '');
    if (empty($raw)) {
        return '';
    }
    if (str_starts_with($raw, 'data:image/')) {
        return $raw;
    }
    return 'data:image/jpeg;base64,' . $raw;
}

/**
 * Decodes the raw binary from the stored client snapshot for SMTP CID attachments.
 */
function generateFlightAreaImageBinary(array $flight): string {
    $raw = trim($flight['map_image_base64'] ?? '');
    if (empty($raw)) {
        return '';
    }
    $clean = preg_replace('#^data:image/[^;]+;base64,#i', '', $raw);
    $binary = base64_decode($clean);
    return ($binary !== false) ? $binary : '';
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

    // Embedded 1:1 Map Screenshot
    if (!empty($imageSrc)) {
        $html .= '          <tr><td style="padding: 10px 28px;">' . "\r\n";
        $html .= '              <table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#0f172a" style="background-color: #0f172a; border-radius: 6px; overflow: hidden; border: 1px solid #334155;">' . "\r\n";
        $html .= '                <tr><td align="center" style="padding: 0; background-color: #090d16;">' . "\r\n";
        $html .= '                    <img src="' . $imageSrc . '" alt="Flight Area Map" width="584" height="520" style="width: 584px; max-width: 100%; height: auto; display: block; border: 0;" />' . "\r\n";
        $html .= '                </td></tr></table></td></tr>' . "\r\n";
    }

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
        'Work Control'            => htmlspecialchars($flight['work_package']),
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
    $body .= "Work Control            : " . $flight['work_package'] . "\r\n";
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
    if (!empty($imageSrc)) {
        $html .= '        <tr><td align="center" style="padding: 0 28px 20px 28px;"><img src="' . $imageSrc . '" alt="Flight Area" width="584" height="520" style="width: 100%; max-width: 584px; display: block; border-radius: 4px;" /></td></tr>' . "\r\n";
    }
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
            'type'     => 'image/jpeg',
            'filename' => 'flight_boundary.jpg'
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
                    'type'     => 'image/jpeg',
                    'filename' => 'flight_boundary.jpg'
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
