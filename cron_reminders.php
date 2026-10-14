<?php
/**
 * Post-Flight Log Reminder Automated Dispatcher
 * Can be run via CLI Cron or server scheduler:
 * e.g. 0 8 * * * /usr/bin/php /path/to/droneform-main/cron_reminders.php > /dev/null 2>&1
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/mailer.php';

$sent = checkAndSendPostFlightReminders();
$timestamp = date('Y-m-d H:i:s T');

if (php_sapi_name() === 'cli') {
    echo "[{$timestamp}] Post-flight reminder check completed. Sent {$sent} reminder(s).\n";
} else {
    header('Content-Type: text/plain');
    echo "[{$timestamp}] Post-flight reminder check completed. Sent {$sent} reminder(s).";
}
