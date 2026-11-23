<?php
// ============================================================
// Role-Based Authorization Guard
// ============================================================

require_once __DIR__ . '/auth_check.php';

function require_role(string|array $allowedRoles): void {
    $currentRole = $_SESSION['role_name'] ?? '';
    $allowed = is_array($allowedRoles) ? $allowedRoles : [$allowedRoles];

    if (!in_array($currentRole, $allowed, true)) {
        log_activity($_SESSION['user_id'] ?? null, 'UNAUTHORIZED_ACCESS_ATTEMPT', "Role {$currentRole} attempted access to restricted resource");
        http_response_code(403);
        die('<!DOCTYPE html>
        <html lang="en">
        <head><meta charset="UTF-8"><title>403 Forbidden</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
        <body class="bg-light d-flex align-items-center justify-content-center vh-100">
            <div class="card shadow-sm p-4 text-center" style="max-width: 450px;">
                <h3 class="text-danger mb-3">Access Denied (403)</h3>
                <p class="text-muted">You do not possess the required administrative permissions to access this module.</p>
                <a href="' . BASE_URL . '/index.php" class="btn btn-primary mt-2">Return to Home</a>
            </div>
        </body></html>');
    }
}
