<?php
// ============================================================
// Authentication Verification Guard
// ============================================================

require_once __DIR__ . '/../config/config.php';

function require_auth(): void {
    if (empty($_SESSION['user_id']) || empty($_SESSION['role_name'])) {
        set_flash('warning', 'Session expired or authorization required. Please log in.');
        header('Location: ' . BASE_URL . '/auth/login.php');
        exit();
    }
}

// Automatically enforce check when this file is directly included
require_auth();
