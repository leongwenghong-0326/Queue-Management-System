<?php
// ============================================================
// Authentication - Logout Module
// ============================================================

require_once __DIR__ . '/../config/config.php';

if (!empty($_SESSION['user_id'])) {
    $uid = $_SESSION['user_id'];
    $uname = $_SESSION['username'] ?? 'User';

    // Log Activity
    log_activity($uid, 'LOGOUT', "User {$uname} logged out");

    // Close any active counter session if staff
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("UPDATE `counter_sessions` SET `ended_at` = NOW(), `status` = 'closed' WHERE `staff_id` = :uid AND `status` = 'active'");
        $stmt->execute([':uid' => $uid]);
    } catch (Exception $e) {
        error_log("Failed to close counter session on logout: " . $e->getMessage());
    }

    // Unset and destroy session
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

session_start();
set_flash('info', 'You have been safely signed out.');
header('Location: ' . BASE_URL . '/auth/login.php');
exit();
