<?php
// Prevent multiple inclusions & initialize session

// Define custom session parameters before session_start()
$droneCookieParams = [
    'lifetime' => 0,                      // Session expires when browser closes
    'path'     => '/drone/',              // Restricts cookie transmission to /drone/ only
    'domain'   => '',                     // Defaults to current host (comms.pad.local)
    'secure'   => true,                   // Enforce HTTPS
    'httponly' => true,                   // Prevent JavaScript access
    'samesite' => 'Lax'
];

session_name('DRONE_SESSID');             // Unique session cookie name
session_set_cookie_params($droneCookieParams);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// System Paths
define('BASE_PATH', dirname(__DIR__));
define('DATA_PATH', BASE_PATH . '/data');
define('DB_FILE', DATA_PATH . '/drone_ops.sqlite');

// Default Local Operating Timezone (Synchronized automatically by getDB())
if (!date_default_timezone_get() || date_default_timezone_get() === 'UTC') {
    date_default_timezone_set('America/Chicago');
}

// App Info
define('APP_NAME', 'Part 107 UAS Flight Management System');
define('APP_VERSION', '1.2.0');

// Default mail sender (for flight notifications)
define('MAIL_FROM', 'flight-ops@uas-part107.com');
define('MAIL_FROM_NAME', 'FAA Part 107 Flight Operations');

// FAA LAANC Service Supplier (USS) Gateway Configuration
define('LAANC_MODE', 'STANDALONE_ENGINE'); 
define('LAANC_API_ENDPOINT', 'https://api.uas-service-supplier.faa.gov/v1/laanc');
define('LAANC_API_KEY', 'TEST_USS_CLIENT_KEY_107');
