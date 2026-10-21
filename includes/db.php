<?php
require_once __DIR__ . '/config.php';

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (!file_exists(DATA_PATH)) {
            @mkdir(DATA_PATH, 0777, true);
        }

        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->setAttribute(PDO::ATTR_TIMEOUT, 5);
        $pdo->exec("PRAGMA busy_timeout = 5000;");
        $pdo->exec("PRAGMA journal_mode = WAL;");
        $pdo->exec("PRAGMA foreign_keys = ON;");

        $tablesExist = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();

        if (!$tablesExist) {
            initSchema($pdo);
        } else {
            migrateSchema($pdo);
        }

        try {
            $tz = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'app_timezone'")->fetchColumn();
            if (!empty($tz)) {
                date_default_timezone_set($tz);
            }
        } catch (Exception $e) {
            // Retain default if setting is unreadable
        }
    }
    return $pdo;
}

function initSchema(PDO $pdo): void {
    // 1. Users Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        full_name TEXT NOT NULL,
        email TEXT NOT NULL,
        phone TEXT,
        role TEXT NOT NULL DEFAULT 'pilot',
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // 2. Facilities Directory Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS facilities (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        facility_name TEXT NOT NULL,
        facility_code TEXT UNIQUE NOT NULL,
        airspace_class TEXT NOT NULL DEFAULT 'G',
        grid_ceiling_alt INTEGER DEFAULT 400,
        latitude REAL DEFAULT 37.115191,
        longitude REAL DEFAULT -88.811224,
        address TEXT,
        default_notification_emails TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // 3. Prepopulated Drones Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS drones (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        model_name TEXT NOT NULL,
        faa_reg_number TEXT NOT NULL,
        faa_reg_expiration DATE NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // 4. Flights Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS flights (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        flight_number TEXT UNIQUE NOT NULL,
        pilot_id INTEGER NOT NULL,
        created_by INTEGER,
        work_package TEXT NOT NULL,
        facility_name TEXT NOT NULL,
        flight_date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        max_altitude INTEGER NOT NULL,
        latitude REAL DEFAULT 37.115191,
        longitude REAL DEFAULT -88.811224,
        polygon_geojson TEXT,
        operational_acres REAL DEFAULT 0.0,
        aircraft_model TEXT NOT NULL,
        payload TEXT,
        faa_reg_number TEXT,
        faa_reg_expiration DATE,
        map_image_base64 TEXT,
        airspace_class TEXT NOT NULL DEFAULT 'G',
        laanc_required INTEGER DEFAULT 0,
        laanc_status TEXT NOT NULL DEFAULT 'PENDING',
        laanc_auth_number TEXT,
        laanc_authorized_at DATETIME,
        additional_emails TEXT,
        reminder_sent_at DATETIME,
        status TEXT NOT NULL DEFAULT 'SCHEDULED',
        mission_purpose TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (pilot_id) REFERENCES users(id),
        FOREIGN KEY (created_by) REFERENCES users(id)
    );");

    // 5. Post Flight Logs
    $pdo->exec("CREATE TABLE IF NOT EXISTS flight_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        flight_id INTEGER UNIQUE NOT NULL,
        actual_start_time TIME,
        actual_end_time TIME,
        total_flight_minutes INTEGER NOT NULL,
        max_altitude_reached INTEGER NOT NULL,
        battery_start_pct INTEGER,
        battery_end_pct INTEGER,
        pilot_notes TEXT,
        incident_occurred INTEGER DEFAULT 0,
        incident_description TEXT,
        logged_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (flight_id) REFERENCES flights(id),
        FOREIGN KEY (logged_by) REFERENCES users(id)
    );");

    // 6. Notification Transmission Logs
    $pdo->exec("CREATE TABLE IF NOT EXISTS notification_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        flight_id INTEGER NOT NULL,
        recipients TEXT NOT NULL,
        sent_subject TEXT NOT NULL,
        sent_body TEXT NOT NULL,
        sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (flight_id) REFERENCES flights(id)
    );");

    // 7. Mandatory Procedural Distribution List
    $pdo->exec("CREATE TABLE IF NOT EXISTS distribution_recipients (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        organization TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // 8. System & SMTP Settings
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT
    );");

    // 9. System & Error Logs Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS error_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        error_type TEXT NOT NULL DEFAULT 'GENERAL',
        message TEXT NOT NULL,
        details TEXT,
        user_id INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    );");

    // 10. Password Reset Tokens Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token_hash TEXT NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_password_resets_token ON password_resets(token_hash);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_password_resets_user ON password_resets(user_id);");

    $defaultAdminPass = password_hash('Admin@107', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT OR IGNORE INTO users (username, password_hash, full_name, email, phone, role)
                   VALUES ('admin', ?, 'Chief Remote Pilot', 'admin@uas-ops.local', '555-0107', 'admin')")
        ->execute([$defaultAdminPass]);

    $pdo->exec("INSERT OR IGNORE INTO distribution_recipients (full_name, email, organization) VALUES
                ('Security Operations Center', 'soc@site-security.local', 'Facility Protective Force'),
                ('Aviation Safety Officer', 'safety@uas-ops.local', 'Airspace & Safety Management');");

    $defaultSettings = [
        'smtp_host'      => '127.0.0.1',
        'smtp_port'      => '25',
        'smtp_auth'      => '0',
        'smtp_user'      => '',
        'smtp_pass'      => '',
        'smtp_secure'    => 'none',
        'mail_from'      => 'flight-ops@uas-part107.com',
        'mail_from_name' => 'FAA Part 107 Flight Operations',
        'app_timezone'   => 'America/Chicago'
    ];
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($defaultSettings as $k => $v) {
        $stmt->execute([$k, $v]);
    }
}

function migrateSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS drones (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        model_name TEXT NOT NULL,
        faa_reg_number TEXT NOT NULL,
        faa_reg_expiration DATE NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS distribution_recipients (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        organization TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS error_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        error_type TEXT NOT NULL DEFAULT 'GENERAL',
        message TEXT NOT NULL,
        details TEXT,
        user_id INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    );");

    // Ensure password_resets table and indices exist on existing databases
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token_hash TEXT NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    );");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_password_resets_token ON password_resets(token_hash);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_password_resets_user ON password_resets(user_id);");

    $defaultSettings = [
        'smtp_host'      => '127.0.0.1',
        'smtp_port'      => '25',
        'smtp_auth'      => '0',
        'smtp_user'      => '',
        'smtp_pass'      => '',
        'smtp_secure'    => 'none',
        'mail_from'      => 'flight-ops@uas-part107.com',
        'mail_from_name' => 'FAA Part 107 Flight Operations',
        'app_timezone'   => 'America/Chicago'
    ];
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($defaultSettings as $k => $v) {
        $stmt->execute([$k, $v]);
    }

    $hasFlights = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='flights'")->fetchColumn();
    if ($hasFlights) {
        $flightCols = array_column($pdo->query("PRAGMA table_info(flights)")->fetchAll(), 'name');

        if (!in_array('additional_emails', $flightCols, true)) {
            $pdo->exec("ALTER TABLE flights ADD COLUMN additional_emails TEXT");
        }
        if (!in_array('created_by', $flightCols, true)) {
            $pdo->exec("ALTER TABLE flights ADD COLUMN created_by INTEGER");
            $pdo->exec("UPDATE flights SET created_by = pilot_id WHERE created_by IS NULL");
        }
        if (!in_array('reminder_sent_at', $flightCols, true)) {
            $pdo->exec("ALTER TABLE flights ADD COLUMN reminder_sent_at DATETIME");
        }
        if (!in_array('payload', $flightCols, true)) {
            $pdo->exec("ALTER TABLE flights ADD COLUMN payload TEXT");
        }
        if (!in_array('faa_reg_number', $flightCols, true)) {
            $pdo->exec("ALTER TABLE flights ADD COLUMN faa_reg_number TEXT");
        }
        if (!in_array('faa_reg_expiration', $flightCols, true)) {
            $pdo->exec("ALTER TABLE flights ADD COLUMN faa_reg_expiration DATE");
        }
        if (!in_array('map_image_base64', $flightCols, true)) {
            $pdo->exec("ALTER TABLE flights ADD COLUMN map_image_base64 TEXT");
        }
    }
}

function logSystemError(string $type, string $message, ?string $details = null, ?int $userId = null): void {
    try {
        $pdo = getDB();
        if ($userId === null && !empty($_SESSION['user_id'])) {
            $userId = (int)$_SESSION['user_id'];
        }
        $localNow = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("INSERT INTO error_logs (error_type, message, details, user_id, created_at) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([strtoupper(trim($type)), trim($message), $details ? trim($details) : null, $userId, $localNow]);
    } catch (Exception $e) {
        error_log("[{$type}] {$message} | Details: " . ($details ?? 'None'));
    }
}
