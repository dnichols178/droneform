<?php
require_once __DIR__ . '/config.php';

function getDB(): PDO {
    static $pdo = null;
    
    if ($pdo === null) {
        // --- UPDATE THESE REMOTE MYSQL CREDENTIALS ---
        $host = 'your_remote_mysql_host';
        $port = '3306';
        $dbname = 'drone_ops';
        $user = 'your_mysql_username';
        $pass = 'your_mysql_password';
        
        $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
        
        try {
            $pdo = new PDO($dsn, $user, $pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false); // Ensures true prepared statements
            
            // Apply saved system timezone safely
            try {
                $tz = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'app_timezone'")->fetchColumn();
                if (!empty($tz)) {
                    date_default_timezone_set($tz);
                }
            } catch (Exception $e) {
                // Retain default if setting is unreadable
            }
            
        } catch (PDOException $e) {
            die("Database Connection Failed: " . $e->getMessage());
        }
    }
    return $pdo;
}

/**
 * Global Error Logging Utility
 */
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
        // Fallback to PHP native log if database write fails
        error_log("[{$type}] {$message} | Details: " . ($details ?? 'None'));
    }
}
?>
