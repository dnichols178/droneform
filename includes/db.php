<?php
require_once __DIR__ . '/config.php';

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (!file_exists(DATA_PATH)) {
            mkdir(DATA_PATH, 0755, true);
        }

        $isNew = !file_exists(DB_FILE);
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        if ($isNew) {
            initSchema($pdo);
        } else {
            migrateSchema($pdo);
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
        part107_cert_num TEXT,
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

    // 3. Flights Table (Includes additional_emails column)
    $pdo->exec("CREATE TABLE IF NOT EXISTS flights (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        flight_number TEXT UNIQUE NOT NULL,
        pilot_id INTEGER NOT NULL,
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
        airspace_class TEXT NOT NULL DEFAULT 'G',
        laanc_required INTEGER DEFAULT 0,
        laanc_status TEXT NOT NULL DEFAULT 'PENDING',
        laanc_auth_number TEXT,
        laanc_authorized_at DATETIME,
        additional_emails TEXT,
        status TEXT NOT NULL DEFAULT 'SCHEDULED',
        mission_purpose TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (pilot_id) REFERENCES users(id)
    );");

    // 4. Post Flight Logs
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

    // 5. Notification Transmission Logs
    $pdo->exec("CREATE TABLE IF NOT EXISTS notification_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        flight_id INTEGER NOT NULL,
        recipients TEXT NOT NULL,
        sent_subject TEXT NOT NULL,
        sent_body TEXT NOT NULL,
        sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (flight_id) REFERENCES flights(id)
    );");

    // 6. Mandatory Procedural Distribution List
    $pdo->exec("CREATE TABLE IF NOT EXISTS distribution_recipients (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        organization TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );");

    // 7. System & SMTP Settings
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT
    );");

    // Seed default admin account
    $defaultAdminPass = password_hash('Admin@107', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (username, password_hash, full_name, email, phone, part107_cert_num, role)
                   VALUES ('admin', ?, 'Chief Remote Pilot', 'admin@uas-ops.local', '555-0107', 'FAA-4019283', 'admin')")
        ->execute([$defaultAdminPass]);

    // Seed default procedural recipients
    $pdo->exec("INSERT INTO distribution_recipients (full_name, email, organization) VALUES
                ('Security Operations Center', 'soc@site-security.local', 'Facility Protective Force'),
                ('Aviation Safety Officer', 'safety@uas-ops.local', 'Airspace & Safety Management');");

    // Seed default SMTP & sender settings
    $defaultSettings = [
        'smtp_host' => '127.0.0.1',
        'smtp_port' => '25',
        'smtp_auth' => '0',
        'smtp_user' => '',
        'smtp_pass' => '',
        'smtp_secure' => 'none', // none, tls, ssl
        'mail_from' => 'flight-ops@uas-part107.com',
        'mail_from_name' => 'FAA Part 107 Flight Operations'
    ];
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($defaultSettings as $k => $v) {
        $stmt->execute([$k, $v]);
    }
}

function migrateSchema(PDO $pdo): void {
    // Ensure new tables exist
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

    // Seed default settings if empty
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

    // Ensure additional_emails exists in flights table
    $flightCols = array_column($pdo->query("PRAGMA table_info(flights)")->fetchAll(), 'name');
    if (!in_array('additional_emails', $flightCols, true)) {
        $pdo->exec("ALTER TABLE flights ADD COLUMN additional_emails TEXT");
    }
}