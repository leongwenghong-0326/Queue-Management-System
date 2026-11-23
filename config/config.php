<?php
// ============================================================
// Smart Queue Management System (QMS) - Global Configuration
// ============================================================

// Error reporting for local development
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Set business timezone
date_default_timezone_set('Asia/Kuala_Lumpur');

// Application Constants
if (!defined('APP_NAME')) {
    define('APP_NAME', 'Smart Queue Management System');
}

// ============================================================
// Base URL Detection (Auto-detects localhost web root & subfolder)
// ============================================================
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

$docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
$appDir = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$basePath = '';
if (!empty($docRoot) && !empty($appDir) && strpos($appDir, $docRoot) === 0) {
    $basePath = substr($appDir, strlen($docRoot));
} else {
    if (isset($_SERVER['REQUEST_URI'])) {
        if (stripos($_SERVER['REQUEST_URI'], '/queue_management_system') !== false) {
            $basePath = '/queue_management_system';
        } elseif (stripos($_SERVER['REQUEST_URI'], '/Queue-Management-System') !== false) {
            $basePath = '/Queue-Management-System';
        }
    }
}

// Clean up slashes
$basePath = '/' . ltrim(rtrim($basePath, '/'), '/');
if ($basePath === '/') {
    $basePath = '';
}

define('BASE_URL', $protocol . $host . $basePath);

// Session Security Configuration
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

// Autoload Core Dependencies
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
