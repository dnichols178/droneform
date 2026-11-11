<?php
/**
 * mysql_setup.php
 * Run this script ONCE to generate the remote MySQL database, tables, and seed data.
 * Delete this file from your server after successful execution.
 */

// --- UPDATE THESE REMOTE MYSQL CREDENTIALS ---
$host = 'your_remote_mysql_host';
$port = '3306';
$user = 'your_mysql_username';
$pass = 'your_mysql_password';
$dbname = 'drone_ops';
// ---------------------------------------------

try {
    // 1. Connect to MySQL and create the database
    $pdo = new PDO("mysql:host=$host;port=$port", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    $pdo->exec("USE `$dbname`;");

    echo "Database `$dbname` created or already exists.<br>";

    // 2. Build MySQL Schema
    $schema = [
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(150) NOT NULL,
            email VARCHAR(150) NOT NULL,
            phone VARCHAR(50),
            part107_cert_num VARCHAR(100),
            role VARCHAR(50) NOT NULL DEFAULT 'pilot',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        
        "CREATE TABLE IF NOT EXISTS facilities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            facility_name VARCHAR(200) NOT NULL,
            facility_code VARCHAR(100) UNIQUE NOT NULL,
            airspace_class VARCHAR(10) NOT NULL DEFAULT 'G',
            grid_ceiling_alt INT DEFAULT 400,
            latitude DECIMAL(10, 6) DEFAULT 37.115191,
            longitude DECIMAL(10, 6) DEFAULT -88.811224,
            address TEXT,
            default_notification_emails TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",
        
        "CREATE TABLE IF NOT EXISTS flights (
            id INT AUTO_INCREMENT PRIMARY KEY,
            flight_number VARCHAR(50) UNIQUE NOT NULL,
            pilot_id INT NOT NULL,
            created_by INT,
            work_package VARCHAR(150) NOT NULL,
            facility_name VARCHAR(200) NOT NULL,
            flight_date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            max_altitude INT NOT NULL,
            latitude DECIMAL(10, 6) DEFAULT 37.115191,
            longitude DECIMAL(10, 6) DEFAULT -88.811224,
            polygon_geojson TEXT,
            operational_acres DECIMAL(10, 2) DEFAULT 0.0,
            aircraft_model VARCHAR(150) NOT NULL,
            airspace_class VARCHAR(10) NOT NULL DEFAULT 'G',
            laanc_required TINYINT(1) DEFAULT 0,
            laanc_status VARCHAR(50) NOT NULL DEFAULT 'PENDING',
            laanc_auth_number VARCHAR(100),
            laanc_authorized_at DATETIME,
            additional_emails TEXT,
            reminder_sent_at DATETIME,
            status VARCHAR(50) NOT NULL DEFAULT 'SCHEDULED',
            mission_purpose TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (pilot_id) REFERENCES users(id) ON DELETE RESTRICT,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        )",

        "CREATE TABLE IF NOT EXISTS flight_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            flight_id INT UNIQUE NOT NULL,
            actual_start_time TIME,
            actual_end_time TIME,
            total_flight_minutes INT NOT NULL,
            max_altitude_reached INT NOT NULL,
            battery_start_pct INT,
            battery_end_pct INT,
            pilot_notes TEXT,
            incident_occurred TINYINT(1) DEFAULT 0,
            incident_description TEXT,
            logged_by INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE,
            FOREIGN KEY (logged_by) REFERENCES users(id) ON DELETE RESTRICT
        )",

        "CREATE TABLE IF NOT EXISTS notification_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            flight_id INT NOT NULL,
            recipients TEXT NOT NULL,
            sent_subject TEXT NOT NULL,
            sent_body TEXT NOT NULL,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (flight_id) REFERENCES flights(id) ON DELETE CASCADE
        )",

        "CREATE TABLE IF NOT EXISTS distribution_recipients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(150) NOT NULL,
            email VARCHAR(150) NOT NULL UNIQUE,
            organization VARCHAR(150),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )",

        "CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT
        )",

        "CREATE TABLE IF NOT EXISTS error_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            error_type VARCHAR(50) NOT NULL DEFAULT 'GENERAL',
            message TEXT NOT NULL,
            details TEXT,
            user_id INT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        )"
    ];

    foreach ($schema as $query) {
        $pdo->exec($query);
    }
    echo "MySQL schema successfully generated.<br>";

    // 3. Seed Default Data (Using INSERT IGNORE for MySQL)
    $defaultAdminPass = password_hash('Admin@107', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT IGNORE INTO users (username, password_hash, full_name, email, phone, part107_cert_num, role) 
                   VALUES ('admin', ?, 'Chief Remote Pilot', 'admin@uas-ops.local', '555-0107', 'FAA-4019283', 'admin')")
        ->execute([$defaultAdminPass]);

    $pdo->exec("INSERT IGNORE INTO distribution_recipients (full_name, email, organization) VALUES 
                ('Security Operations Center', 'soc@site-security.local', 'Facility Protective Force'),
                ('Aviation Safety Officer', 'safety@uas-ops.local', 'Airspace & Safety Management')");

    $defaultSettings = [
        'smtp_host'    => '127.0.0.1',
        'smtp_port'    => '25',
        'smtp_auth'    => '0',
        'smtp_user'    => '',
        'smtp_pass'    => '',
        'smtp_secure'  => 'none',
        'mail_from'    => 'flight-ops@uas-part107.com',
        'mail_from_name'=> 'FAA Part 107 Flight Operations',
        'app_timezone' => 'America/Chicago'
    ];

    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ($defaultSettings as $k => $v) {
        $stmt->execute([$k, $v]);
    }

    echo "Default seeds (Admin, Recipients, Settings) injected.<br>";
    echo "<strong style='color:green;'>Migration complete. You may now update your includes/db.php file and delete this script.</strong>";

} catch (PDOException $e) {
    die("Database Setup Failed: " . $e->getMessage());
}
?>
