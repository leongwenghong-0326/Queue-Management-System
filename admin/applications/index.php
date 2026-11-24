<?php
// ============================================================
// Admin - Online Document Applications Review & Processing
// ============================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/role_check.php';
require_role('Admin');

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
    header('Location: ' . BASE_URL . '/admin/applications/index.php');
    exit();
}

// Filters
$filterService = (int)($_GET['service_id'] ?? 0);
$filterStatus = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

$services = $pdo->query("SELECT * FROM `services` ORDER BY `service_code` ASC")->fetchAll();

$sql = "SELECT a.*, s.service_code, s.service_name, u.username as user_account, r.name as reviewer_name 
        FROM `applications` a 
        JOIN `services` s ON a.service_id = s.id 
        LEFT JOIN `users` u ON a.user_id = u.id 
        LEFT JOIN `users` r ON a.reviewed_by = r.id 
        WHERE 1=1";
$params = [];

if ($filterService > 0) {
    $sql .= " AND a.service_id = :sid";
    $params[':sid'] = $filterService;
}
if (!empty($filterStatus)) {
    $sql .= " AND a.status = :st";
    $params[':st'] = $filterStatus;
}
if (!empty($search)) {
    $sql .= " AND (a.application_reference LIKE :s OR a.applicant_name LIKE :s OR a.ic_number LIKE :s)";
    $params[':s'] = "%{$search}%";
}

$sql .= " ORDER BY a.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$applications = $stmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main d-flex flex-column flex-grow-1">
    <header class="app-header d-flex justify-content-between align-items-center px-4 py-3 bg-white border-bottom">
        <div class="d-flex align-items-center">
            <button type="button" class="btn btn-link text-dark p-0 me-3 d-lg-none" onclick="toggleMobileSidebar()">
                <i class="bi bi-list fs-3"></i>
            </button>
            <h4 class="mb-0 fw-bold">Online Applications Processing</h4>
        </div>
    </header>

    <main class="app-content p-4 flex-grow-1 bg-light">
        <?= display_flash() ?>

        <!-- Filter Card -->
        <div class="card border-0 shadow-sm rounded-4 bg-white p-3 mb-4">
            <form method="GET" action="<?= BASE_URL ?>/admin/applications/index.php" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Search (Ref / Name / IC)</label>
                    <input type="text" name="search" class="form-control form-control-sm" value="<?= e($search) ?>" placeholder="Search keyword...">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Service</label>
                    <select name="service_id" class="form-select form-select-sm">
                        <option value="0">All Services</option>
                        <?php foreach ($services as $svc): ?>
                            <option value="<?= $svc['id'] ?>" <?= $filterService == $svc['id'] ? 'selected' : '' ?>>
                                <?= e($svc['service_code']) ?> - <?= e($svc['service_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        <option value="Pending" <?= $filterStatus === 'Pending' ? 'selected' : '' ?>>Pending Review</option>
                        <option value="Approved" <?= $filterStatus === 'Approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="Rejected" <?= $filterStatus === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-1">
                    <button type="submit" class="btn btn-primary btn-sm flex-fill">
                        <i class="bi bi-filter"></i> Filter
                    </button>
                    <a href="<?= BASE_URL ?>/admin/applications/index.php" class="btn btn-light btn-sm border">Reset</a>
                </div>
            </form>
        </div>

        <!-- Table Card -->
        <div class="card border-0 shadow-sm rounded-4 bg-white">
            <div class="card-header bg-white border-0 py-3">
                <h5 class="fw-bold mb-0">Submitted Applications (<?= count($applications) ?>)</h5>
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
                                <th class="text-end pe-3">Review Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($applications)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">No applications found matching current criteria.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($applications as $app): ?>
                                    <tr>
                                        <td class="ps-3 font-monospace fw-bold text-dark"><?= e($app['application_reference']) ?></td>
                                        <td>
                                            <div class="fw-bold"><?= e($app['applicant_name']) ?></div>
                                            <div class="small text-muted">IC: <?= e($app['ic_number']) ?> | Tel: <?= e($app['phone']) ?></div>
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
                                            <?php if (!empty($app['reviewer_remarks'])): ?>
                                                <div class="small text-muted mt-1" style="max-width: 160px; font-size: 0.75rem;">
                                                    "<?= e($app['reviewer_remarks']) ?>"
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-3">
                                            <?php if ($app['status'] === 'Pending'): ?>
                                                <button type="button" class="btn btn-sm btn-success me-1" 
                                                        onclick="openReviewModal(<?= $app['id'] ?>, '<?= e($app['application_reference']) ?>', 'approve')">
                                                    <i class="bi bi-check-lg"></i> Approve
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-danger" 
                                                        onclick="openReviewModal(<?= $app['id'] ?>, '<?= e($app['application_reference']) ?>', 'reject')">
                                                    <i class="bi bi-x-lg"></i> Reject
                                                </button>
                                            <?php else: ?>
                                                <span class="small text-muted">Reviewed by <?= e($app['reviewer_name'] ?? 'Admin') ?></span>
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

    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</div>

<!-- Review Modal -->
<div class="modal fade" id="reviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="<?= BASE_URL ?>/admin/applications/index.php" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="modalAction" value="">
            <input type="hidden" name="application_id" id="modalAppId" value="">

            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="modalTitle">Review Application</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Confirm review decision for application <span id="modalRef" class="fw-bold font-monospace"></span>.</p>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Review Remarks / Reason</label>
                    <textarea name="remarks" class="form-control" rows="3" placeholder="Enter remarks for applicant..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" id="modalSubmitBtn" class="btn btn-primary fw-bold">Confirm</button>
            </div>
        </form>
    </div>
</div>

<script>
function openReviewModal(appId, ref, action) {
    document.getElementById('modalAppId').value = appId;
    document.getElementById('modalAction').value = action;
    document.getElementById('modalRef').innerText = ref;
    const title = document.getElementById('modalTitle');
    const submitBtn = document.getElementById('modalSubmitBtn');

    if (action === 'approve') {
        title.innerText = 'Approve Application';
        submitBtn.className = 'btn btn-success fw-bold';
        submitBtn.innerText = 'Approve Application';
    } else {
        title.innerText = 'Reject Application';
        submitBtn.className = 'btn btn-danger fw-bold';
        submitBtn.innerText = 'Reject Application';
    }

    new bootstrap.Modal(document.getElementById('reviewModal')).show();
}
</script>
