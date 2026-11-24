<?php
// Database Installation / Migration CLI Script
echo "Initializing Database Installation...\n";

$ports = [3307, 3306];
$pdo = null;
$connectedPort = null;

foreach ($ports as $port) {
    try {
        $testPdo = new PDO("mysql:host=127.0.0.1;port={$port}", 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 2
        ]);
        $pdo = $testPdo;
        $connectedPort = $port;
        echo "Successfully connected to MySQL on port {$port}.\n";
        break;
    } catch (Exception $e) {
        // Continue trying next port
    }
}

if (!$pdo) {
    echo "ERROR: Unable to connect to MySQL on ports 3307 or 3306.\n";
    exit(1);
}

// Read SQL file
$sqlFile = __DIR__ . '/queue_management_system.sql';
if (!file_exists($sqlFile)) {
    echo "ERROR: SQL file not found at: {$sqlFile}\n";
    exit(1);
}

$sqlContent = file_get_contents($sqlFile);

try {
    // Split and execute SQL queries
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `queue_management_system` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    $pdo->exec("USE `queue_management_system`;");
    
    // Execute full script
    $pdo->exec($sqlContent);
    echo "Database schema and seed data loaded successfully.\n";

    // Set known fresh bcrypt passwords for testing accounts
    $defaultPasswordHash = password_hash('password123', PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("UPDATE `users` SET `password` = :pwd WHERE `username` IN ('admin', 'staff1', 'staff2', 'citizen1')");
    $stmt->execute([':pwd' => $defaultPasswordHash]);
    echo "Default user passwords updated successfully (admin: password123, staff1: password123, staff2: password123, citizen1: password123).\n";

    // Verify created tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Total tables created: " . count($tables) . "\n";
    foreach ($tables as $t) {
        echo "  - {$t}\n";
    }

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
