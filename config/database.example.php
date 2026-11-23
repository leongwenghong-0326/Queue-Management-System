<?php
// ============================================================
// Smart Queue Management System (QMS) - Database Config Template
// Copy this file to database.php and configure your database settings.
// ============================================================

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $dbHost = '127.0.0.1';
            $dbName = 'queue_management_system';
            $dbUser = 'root';
            $dbPass = '';
            $ports = [3307, 3306];
            $connected = false;
            $lastException = null;

            foreach ($ports as $port) {
                try {
                    $dsn = "mysql:host={$dbHost};port={$port};dbname={$dbName};charset=utf8mb4";
                    self::$instance = new PDO($dsn, $dbUser, $dbPass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => true,
                        PDO::ATTR_TIMEOUT => 2
                    ]);
                    $connected = true;
                    break;
                } catch (PDOException $e) {
                    $lastException = $e;
                }
            }

            if (!$connected) {
                error_log("Database connection failed: " . ($lastException ? $lastException->getMessage() : "Unknown error"));
                die("Critical Error: Unable to connect to database. Please check your credentials in config/database.php.");
            }
        }

        return self::$instance;
    }
}
