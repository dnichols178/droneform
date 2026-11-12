<?php
$message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $host = $_POST['host'] ?? '';
    $port = $_POST['port'] ?? '3306';
    $user = $_POST['user'] ?? '';
    $pass = $_POST['pass'] ?? '';

    try {
        // Enable strict error reporting for mysqli
        mysqli_report(MYSQLI_REPORT_STRICT | MYSQLI_REPORT_ERROR);
        
        // Attempt the connection (No database selected, just testing the server)
        $conn = new mysqli($host, $user, $pass, "", (int)$port);
        
        $message = "<p style='color: green; font-weight: bold;'>✅ Connection successful!</p>";
        $conn->close();
        
    } catch (mysqli_sql_exception $e) {
        $message = "<p style='color: red; font-weight: bold;'>❌ Connection failed:</p>";
        $message .= "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>MySQL Remote Connection Tester</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 400px; margin: 40px auto; padding: 20px; }
        label { display: block; margin-top: 10px; font-weight: bold; }
        input { width: 100%; padding: 8px; margin-top: 5px; box-sizing: border-box; }
        button { margin-top: 20px; padding: 10px; width: 100%; background: #0056b3; color: white; border: none; cursor: pointer; }
        button:hover { background: #004494; }
        .result { margin-top: 20px; padding: 15px; border: 1px solid #ddd; background: #f9f9f9; }
    </style>
</head>
<body>

    <h2>Test MySQL Connection</h2>
    
    <form method="POST">
        <label for="host">Host / IP URL:</label>
        <input type="text" id="host" name="host" required placeholder="e.g., 192.168.1.50 or db.example.com" value="<?= htmlspecialchars($_POST['host'] ?? '') ?>">

        <label for="port">Port:</label>
        <input type="number" id="port" name="port" required value="<?= htmlspecialchars($_POST['port'] ?? '3306') ?>">

        <label for="user">Username:</label>
        <input type="text" id="user" name="user" required value="<?= htmlspecialchars($_POST['user'] ?? '') ?>">

        <label for="pass">Password:</label>
        <input type="password" id="pass" name="pass" value="<?= htmlspecialchars($_POST['pass'] ?? '') ?>">

        <button type="submit">Test Connection</button>
    </form>

    <?php if ($message): ?>
        <div class="result">
            <?= $message ?>
        </div>
    <?php endif; ?>

</body>
</html>
