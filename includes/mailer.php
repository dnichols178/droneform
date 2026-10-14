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

    $count = $pdo->query("SELECT COUNT(*) FROM system_settings")->fetchColumn();
    if ($count == 0) {
        $defaultSettings = [
            'smtp_host' => '127.0.0.1',
            'smtp_port' => '25',
            'smtp_auth' => '0',
            'smtp_user' => '',
            'smtp_pass' => '',
            'smtp_secure' => 'none',
            'mail_from' => 'flight-ops@uas-part107.com',
            'mail_from_name' => 'FAA Part 107 Flight Operations'
        ];
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
        foreach ($defaultSettings as $k => $v) {
            $stmt->execute([$k, $v]);
        }
    }
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
 * Builds an Outlook-compatible HTML email template
 */
function buildFlightNotificationHtml(array $flight, array $recipients): string {
    $laancBadgeColor = (!empty($flight['laanc_auth_number']) || $flight['laanc_status'] === 'AUTO_APPROVED') ? '#16a34a' : '#d97706';
    $laancBadgeBg = (!empty($flight['laanc_auth_number']) || $flight['laanc_status'] === 'AUTO_APPROVED') ? '#dcfce7' : '#fef3c7';
    $laancText = !empty($flight['laanc_auth_number']) ? htmlspecialchars($flight['laanc_auth_number']) : ($flight['airspace_class'] === 'G' ? 'NOT REQUIRED (CLASS G)' : 'PENDING');

    $html = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">' . "\r\n";
    $html .= '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">' . "\r\n";
    $html .= '<head>' . "\r\n";
    $html .= '  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />' . "\r\n";
    $html .= '  <meta name="viewport" content="width=device-width, initial-scale=1.0" />' . "\r\n";
    $html .= '  <!--[if gte mso 9]><xml><o:OfficeDocumentSettings><o:AllowPNG/><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]-->' . "\r\n";
    $html .= '  <title>' . htmlspecialchars($flight['flight_number']) . '</title>' . "\r\n";
    $html .= '</head>' . "\r\n";
    $html .= '<body style="margin: 0; padding: 20px; background-color: #f0f4f8; font-family: Arial, Helvetica, sans-serif; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%;">' . "\r\n";

    // Main 640px Wrapper for Outlook
    $html .= '  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f0f4f8;">' . "\r\n";
    $html .= '    <tr>' . "\r\n";
    $html .= '      <td align="center">' . "\r\n";
    $html .= '        <!--[if (gte mso 9)|(IE)]><table width="640" align="center" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->' . "\r\n";
    $html .= '        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: 640px; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">' . "\r\n";

    // Header Banner
    $html .= '          <tr>' . "\r\n";
    $html .= '            <td bgcolor="#0f172a" style="background-color: #0f172a; padding: 22px 28px; border-bottom: 3px solid #0284c7;">' . "\r\n";
    $html .= '              <table width="100%" cellpadding="0" cellspacing="0" border="0">' . "\r\n";
    $html .= '                <tr>' . "\r\n";
    $html .= '                  <td>' . "\r\n";
    $html .= '                    <div style="color: #38bdf8; font-size: 11px; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase;">FAA Part 107 Commercial Flight Plan</div>' . "\r\n";
    $html .= '                    <div style="color: #ffffff; font-size: 22px; font-weight: 700; margin-top: 4px;">' . htmlspecialchars($flight['flight_number']) . '</div>' . "\r\n";
    $html .= '                  </td>' . "\r\n";
    $html .= '                  <td align="right" valign="middle">' . "\r\n";
    $html .= '                    <span style="display: inline-block; background-color: rgba(255,255,255,0.15); color: #ffffff; font-size: 12px; font-weight: 700; padding: 6px 12px; border-radius: 4px;">Class ' . htmlspecialchars($flight['airspace_class']) . ' Airspace</span>' . "\r\n";
    $html .= '                  </td>' . "\r\n";
    $html .= '                </tr>' . "\r\n";
    $html .= '              </table>' . "\r\n";
    $html .= '            </td>' . "\r\n";
    $html .= '          </tr>' . "\r\n";

    // LAANC Status Callout Box
    $html .= '          <tr>' . "\r\n";
    $html .= '            <td style="padding: 20px 28px 10px 28px;">' . "\r\n";
    $html .= '              <table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="' . $laancBadgeBg . '" style="background-color: ' . $laancBadgeBg . '; border: 1px solid ' . $laancBadgeColor . '; border-radius: 6px;">' . "\r\n";
    $html .= '                <tr>' . "\r\n";
    $html .= '                  <td style="padding: 12px 16px;">' . "\r\n";
    $html .= '                    <span style="color: ' . $laancBadgeColor . '; font-weight: 700; font-size: 13px;">FAA LAANC CLEARANCE:</span>' . "\r\n";
    $html .= '                    <span style="font-family: Consolas, monospace, Arial; font-weight: 700; font-size: 15px; color: #0f172a; margin-left: 8px;">' . $laancText . '</span>' . "\r\n";
    if (!empty($flight['laanc_authorized_at'])) {
        $html .= '                    <div style="color: #475569; font-size: 11px; margin-top: 4px;">Cleared Timestamp: ' . htmlspecialchars($flight['laanc_authorized_at']) . ' UTC</div>' . "\r\n";
    }
    $html .= '                  </td>' . "\r\n";
    $html .= '                </tr>' . "\r\n";
    $html .= '              </table>' . "\r\n";
    $html .= '            </td>' . "\r\n";
    $html .= '          </tr>' . "\r\n";

    // Mission Scope Box
    $html .= '          <tr>' . "\r\n";
    $html .= '            <td style="padding: 10px 28px;">' . "\r\n";
    $html .= '              <table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f8fafc" style="background-color: #f8fafc; border-left: 4px solid #0284c7; border-radius: 4px; border-top: 1px solid #e2e8f0; border-right: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0;">' . "\r\n";
    $html .= '                <tr>' . "\r\n";
    $html .= '                  <td style="padding: 14px 18px;">' . "\r\n";
    $html .= '                    <div style="font-size: 11px; font-weight: 700; color: #0369a1; text-transform: uppercase; margin-bottom: 4px;">Required Mission Scope</div>' . "\r\n";
    $html .= '                    <div style="font-size: 13px; line-height: 18px; color: #1e293b; font-weight: 600;">' . nl2br(htmlspecialchars($flight['mission_purpose'])) . '</div>' . "\r\n";
    $html .= '                  </td>' . "\r\n";
    $html .= '                </tr>' . "\r\n";
    $html .= '              </table>' . "\r\n";
    $html .= '            </td>' . "\r\n";
    $html .= '          </tr>' . "\r\n";

    // Operations Data Table
    $html .= '          <tr>' . "\r\n";
    $html .= '            <td style="padding: 10px 28px 20px 28px;">' . "\r\n";
    $html .= '              <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse;">' . "\r\n";

    $rows = [
        'Target Facility'         => htmlspecialchars($flight['facility_name']),
        'Work Package'            => htmlspecialchars($flight['work_package']),
        'Operational Window'      => htmlspecialchars($flight['flight_date']) . ' from ' . htmlspecialchars($flight['start_time']) . ' to ' . htmlspecialchars($flight['end_time']) . ' Local',
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
        $html .= '                  <td width="38%" style="padding: 8px 12px; border-bottom: 1px solid #e2e8f0; font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;">' . $label . '</td>' . "\r\n";
        $html .= '                  <td width="62%" style="padding: 8px 12px; border-bottom: 1px solid #e2e8f0; font-size: 13px; color: #0f172a;">' . $val . '</td>' . "\r\n";
        $html .= '                </tr>' . "\r\n";
        $i++;
    }

    $html .= '              </table>' . "\r\n";
    $html .= '            </td>' . "\r\n";
    $html .= '          </tr>' . "\r\n";

    // Footer
    $html .= '          <tr>' . "\r\n";
    $html .= '            <td bgcolor="#f8fafc" style="background-color: #f8fafc; padding: 16px 28px; border-top: 1px solid #e2e8f0; font-size: 11px; color: #64748b; line-height: 16px;">' . "\r\n";
    $html .= '              <strong>FAA 14 CFR Part 107 Notification:</strong> This automated bulletin notifies facility personnel, security forces, and flight safety officers of authorized UAS operations. Operating within assigned airspace and altitude constraints is mandatory.' . "\r\n";
    $html .= '            </td>' . "\r\n";
    $html .= '          </tr>' . "\r\n";

    $html .= '        </table>' . "\r\n";
    $html .= '        <!--[if (gte mso 9)|(IE)]></td></tr></table><![endif]-->' . "\r\n";
    $html .= '      </td>' . "\r\n";
    $html .= '    </tr>' . "\r\n";
    $html .= '  </table>' . "\r\n";
    $html .= '</body>' . "\r\n";
    $html .= '</html>' . "\r\n";

    return $html;
}

/**
 * Builds Plain-Text Alternative
 */
function buildFlightNotificationText(array $flight): string {
    $body = "====================================================\r\n";
    $body .= "FAA PART 107 OFFICIAL UAS FLIGHT NOTIFICATION\r\n";
    $body .= "====================================================\r\n\r\n";
    $body .= "Flight Plan Number      : " . $flight['flight_number'] . "\r\n";
    $body .= "Work Package            : " . $flight['work_package'] . "\r\n";
    $body .= "Target Facility         : " . $flight['facility_name'] . "\r\n";
    $body .= "Airspace Classification : Class " . $flight['airspace_class'] . "\r\n";
    $body .= "Centroid Coordinates    : " . $flight['latitude'] . ", " . $flight['longitude'] . "\r\n";
    $body .= "Operating Footprint     : " . $flight['operational_acres'] . " Acres\r\n";
    $body .= "Scheduled Date          : " . $flight['flight_date'] . "\r\n";
    $body .= "Operational Window      : " . $flight['start_time'] . " to " . $flight['end_time'] . " Local\r\n";
    $body .= "Max Authorized Altitude : " . $flight['max_altitude'] . " ft AGL\r\n\r\n";

    $body .= "--- FAA LAANC AIRSPACE AUTHORIZATION ---\r\n";
    $body .= "LAANC Status            : " . $flight['laanc_status'] . "\r\n";
    $body .= "LAANC Authorization ID  : " . ($flight['laanc_auth_number'] ?: 'NOT REQUIRED (CLASS G)') . "\r\n";
    if (!empty($flight['laanc_authorized_at'])) {
        $body .= "Authorization Timestamp : " . $flight['laanc_authorized_at'] . " UTC\r\n";
    }
    $body .= "\r\n";

    $body .= "--- REQUIRED MISSION SCOPE ---\r\n";
    $body .= $flight['mission_purpose'] . "\r\n\r\n";

    $body .= "--- REMOTE PILOT IN COMMAND (RPIC) ---\r\n";
    $body .= "Remote Pilot in Command : " . $flight['pilot_name'] . "\r\n";
    $body .= "Part 107 Certificate #  : " . ($flight['part107_cert_num'] ?: 'ON FILE') . "\r\n";
    $body .= "Emergency Phone Contact : " . $flight['pilot_phone'] . "\r\n";
    $body .= "Pilot Email             : " . $flight['pilot_email'] . "\r\n\r\n";

    $body .= "--- AIRCRAFT SPECIFICATION ---\r\n";
    $body .= "UAS Make & Model        : " . $flight['aircraft_model'] . "\r\n\r\n";
    $body .= "Notice: This automated operational notification has been dispatched to mandatory site personnel and safety observers.\r\n";

    return $body;
}

/**
 * Pure PHP SMTP client supporting MIME multipart (Plain text + HTML)
 */
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
    $timeout = 10;
    $errno = 0;
    $errstr = '';

    $socket = @fsockopen($socketHost, $port, $errno, $errstr, $timeout);
    if (!$socket) {
        $errorOutput = "Failed to connect to SMTP host {$host}:{$port} ({$errstr})";
        $headers = "From: {$fromName} <{$from}>\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        return @mail(implode(', ', $to), $subject, $htmlBody, $headers);
    }

    $getResponse = function() use ($socket) {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') break;
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

    // MIME Multipart Alternative (Plain Text + HTML for Outlook)
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

    if (substr($resultResp, 0, 3) === '250') {
        return true;
    } else {
        $errorOutput = "Transmission rejected: " . trim($resultResp);
        return false;
    }
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
    $logStmt = $pdo->prepare("INSERT INTO notification_logs (flight_id, recipients, sent_subject, sent_body) VALUES (?, ?, ?, ?)");
    $logStmt->execute([$flightId, $toHeader, $subject, $textBody]);

    return $sent;
}