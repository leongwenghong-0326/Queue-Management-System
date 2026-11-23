<?php
// ============================================================
// Layout - Sidebar Component
// ============================================================
$currentUri = $_SERVER['REQUEST_URI'] ?? '';
$role = $_SESSION['role_name'] ?? '';
?>
<!-- Mobile Backdrop Overlay -->
<div class="sidebar-overlay d-lg-none" id="sidebarOverlay" onclick="toggleMobileSidebar()"></div>

<aside class="app-sidebar d-flex flex-column" id="appSidebar">
    <div class="sidebar-brand d-flex justify-content-between align-items-center">
        <a href="<?= BASE_URL ?>/index.php" class="text-white text-decoration-none d-flex align-items-center">
            <i class="bi bi-diagram-3-fill text-primary me-2 fs-4"></i>
            <span>Smart QMS</span>
        </a>
        <button type="button" class="btn btn-link text-white-50 p-0 d-lg-none" onclick="toggleMobileSidebar()" aria-label="Close Sidebar">
            <i class="bi bi-x-lg fs-5"></i>
        </button>
    </div>

    <div class="flex-grow-1 overflow-auto py-2">
        <?php if ($role === 'Admin'): ?>
            <div class="nav-header">Administration</div>
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentUri, 'dashboard.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/dashboard.php">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentUri, '/users/') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/users/index.php">
                        <i class="bi bi-people-fill"></i> Staff Accounts
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentUri, '/services/') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/services/index.php">
                        <i class="bi bi-tags-fill"></i> Services
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentUri, '/counters/') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/counters/index.php">
                        <i class="bi bi-window-desktop"></i> Service Counters
                    </a>
                </li>
            </ul>

            <div class="nav-header">Queue Control</div>
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentUri, '/queues/') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/queues/index.php">
                        <i class="bi bi-list-ol"></i> Live Queue Monitor
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentUri, '/reports/') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/reports/index.php">
                        <i class="bi bi-file-earmark-bar-graph-fill"></i> Reports & Analytics
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentUri, '/settings/') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/settings/index.php">
                        <i class="bi bi-sliders"></i> System Settings
                    </a>
                </li>
            </ul>

        <?php elseif ($role === 'Staff'): ?>
            <div class="nav-header">Counter Terminal</div>
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link <?= strpos($currentUri, 'dashboard.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>/staff/dashboard.php">
                        <i class="bi bi-headset"></i> Counter Workspace
                    </a>
                </li>
            </ul>
        <?php endif; ?>

        <div class="nav-header">Public Portals</div>
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link" href="<?= BASE_URL ?>/customer/index.php" target="_blank">
                    <i class="bi bi-phone"></i> Kiosk / Customer Reg
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="<?= BASE_URL ?>/display/index.php" target="_blank">
                    <i class="bi bi-tv"></i> Public Display Screen
                </a>
            </li>
        </ul>
    </div>

    <!-- User Profile Footer -->
    <div class="p-3 border-top border-secondary border-opacity-25 bg-dark bg-opacity-25">
        <div class="d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center overflow-hidden">
                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 36px; height: 36px; min-width: 36px;">
                    <i class="bi bi-person-fill"></i>
                </div>
                <div class="text-truncate">
                    <div class="fw-semibold text-white text-truncate small"><?= e($_SESSION['user_name'] ?? 'User') ?></div>
                    <div class="text-muted text-truncate" style="font-size: 0.75rem;"><?= e($_SESSION['role_name'] ?? 'Guest') ?></div>
                </div>
            </div>
            <a href="<?= BASE_URL ?>/auth/logout.php" class="btn btn-sm btn-outline-danger" title="Logout">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>
</aside>
<div class="app-main">
    <nav class="app-navbar d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center overflow-hidden me-2">
            <!-- Mobile Toggle Button -->
            <button type="button" class="btn btn-light btn-sm border d-lg-none me-2 p-1 px-2" onclick="toggleMobileSidebar()" aria-label="Toggle Sidebar">
                <i class="bi bi-list fs-4"></i>
            </button>
            <h5 class="mb-0 fw-semibold text-dark text-truncate"><?= e($pageTitle) ?></h5>
        </div>
        <div class="d-flex align-items-center gap-2 flex-shrink-0">
            <span class="badge bg-light text-dark border px-2 py-1 d-none d-sm-inline-flex align-items-center">
                <i class="bi bi-clock me-1 text-primary"></i> <span id="systemClock"><?= date('H:i:s') ?></span>
            </span>
            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-1">
                <?= e($_SESSION['role_name'] ?? 'Guest') ?>
            </span>
        </div>
    </nav>
    <main class="app-content">
        <?= display_flash() ?>
