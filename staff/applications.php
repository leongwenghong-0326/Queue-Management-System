<?php
// ============================================================
// Staff - Applications Desk Review
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/role_check.php';
require_role('Staff');

$pageTitle = 'Applications Review';
$pdo = Database::getConnection();

// Handle Approval / Rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $appId = (int)($_POST['application_id'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');

    if ($appId > 0 && in_array($action, ['approve', 'reject'], true)) {
        $newStatus = $action === 'approve' ? 'Approved' : 'Rejected';
        review_application($appId, $newStatus, (int)$_SESSION['user_id'], $remarks);
        set_flash('success', "Application #{$appId} has been {$newStatus}.");
    }
    header('Location: ' . BASE_URL . '/staff/applications.php');
    exit();
}

$stmt = $pdo->query("SELECT a.*, s.service_code, s.service_name, r.name as reviewer_name 
                     FROM `applications` a 
                     JOIN `services` s ON a.service_id = s.id 
                     LEFT JOIN `users` r ON a.reviewed_by = r.id 
                     ORDER BY a.id DESC");
$applications = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="app-main d-flex flex-column flex-grow-1">
    <header class="app-header d-flex justify-content-between align-items-center px-4 py-3 bg-white border-bottom">
        <div class="d-flex align-items-center">
            <button type="button" class="btn btn-link text-dark p-0 me-3 d-lg-none" onclick="toggleMobileSidebar()">
                <i class="bi bi-list fs-3"></i>
            </button>
            <h4 class="mb-0 fw-bold">Online Applications Desk Review</h4>
        </div>
        <a href="<?= BASE_URL ?>/staff/dashboard.php" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Return to Calling Console
        </a>
    </header>

    <main class="app-content p-4 flex-grow-1 bg-light">
        <?= display_flash() ?>

        <div class="card border-0 shadow-sm rounded-4 bg-white">
            <div class="card-header bg-white border-0 py-3">
                <h5 class="fw-bold mb-0">Citizen Applications (<?= count($applications) ?>)</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light small text-uppercase">
                            <tr>
                                <th class="ps-3">Reference</th>
                                <th>Applicant</th>
                                <th>Service</th>
                                <th>Attachment</th>
                                <th>Submitted</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($applications)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">No applications found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($applications as $app): ?>
                                    <tr>
                                        <td class="ps-3 font-monospace fw-bold text-dark"><?= e($app['application_reference']) ?></td>
                                        <td>
                                            <div class="fw-bold"><?= e($app['applicant_name']) ?></div>
                                            <div class="small text-muted">IC: <?= e($app['ic_number']) ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border me-1"><?= e($app['service_code']) ?></span>
                                            <?= e($app['service_name']) ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($app['document_path'])): ?>
                                                <a href="<?= BASE_URL ?>/<?= e($app['document_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2">
                                                    <i class="bi bi-file-earmark-arrow-down"></i> View
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted small">None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= date('Y-m-d H:i', strtotime($app['created_at'])) ?></td>
                                        <td>
                                            <?php
                                                $appBadge = match($app['status']) {
                                                    'Pending' => 'bg-warning text-dark',
                                                    'Approved' => 'bg-success',
                                                    'Rejected' => 'bg-danger',
                                                    default => 'bg-light text-dark'
                                                };
                                            ?>
                                            <span class="badge <?= $appBadge ?>"><?= e($app['status']) ?></span>
                                        </td>
                                        <td class="text-end pe-3">
                                            <?php if ($app['status'] === 'Pending'): ?>
                                                <form method="POST" action="<?= BASE_URL ?>/staff/applications.php" class="d-inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="approve">
                                                    <input type="hidden" name="application_id" value="<?= $app['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-success me-1">Approve</button>
                                                </form>
                                                <form method="POST" action="<?= BASE_URL ?>/staff/applications.php" class="d-inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="reject">
                                                    <input type="hidden" name="application_id" value="<?= $app['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Reject</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="small text-muted"><?= e($app['status']) ?> by <?= e($app['reviewer_name'] ?? 'Officer') ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </main>

    <?php require_once __DIR__ . '/../includes/footer.php'; ?>
</div>
