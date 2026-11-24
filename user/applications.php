<?php
// ============================================================
// Citizen Portal - Online Document Applications
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/role_check.php';
require_role('User');

$pageTitle = 'Online Applications';
$pdo = Database::getConnection();
$userId = (int)$_SESSION['user_id'];

// Fetch user profile
$userStmt = $pdo->prepare("SELECT * FROM `users` WHERE `id` = :id LIMIT 1");
$userStmt->execute([':id' => $userId]);
$currentUser = $userStmt->fetch();

// Active services
$services = $pdo->query("SELECT * FROM `services` WHERE `status` = 'active' ORDER BY `service_code` ASC")->fetchAll();

$error = '';

// Handle Application Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit') {
    require_csrf();
    $serviceId = (int)($_POST['service_id'] ?? 0);
    $gender = $_POST['gender'] ?? 'Male';
    $nationality = trim($_POST['nationality'] ?? 'Malaysian');
    $notes = trim($_POST['notes'] ?? '');

    if ($serviceId <= 0) {
        $error = 'Please select a service category.';
    } else {
        $documentPath = null;

        // Handle File Upload if provided
        if (!empty($_FILES['document']['name']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
            $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
            $fileInfo = pathinfo($_FILES['document']['name']);
            $ext = strtolower($fileInfo['extension'] ?? '');

            if (!in_array($ext, $allowedExtensions, true)) {
                $error = 'Only PDF, JPG, and PNG files are allowed.';
            } elseif ($_FILES['document']['size'] > 5 * 1024 * 1024) {
                $error = 'File size exceeds maximum 5MB limit.';
            } else {
                $uploadDir = __DIR__ . '/../uploads/applications';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $filename = 'DOC_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $targetFile = $uploadDir . '/' . $filename;

                if (move_uploaded_file($_FILES['document']['tmp_name'], $targetFile)) {
                    $documentPath = 'uploads/applications/' . $filename;
                } else {
                    $error = 'Failed to save uploaded document.';
                }
            }
        }

        if (empty($error)) {
            $res = create_application([
                'user_id' => $userId,
                'service_id' => $serviceId,
                'applicant_name' => $currentUser['name'],
                'ic_number' => $currentUser['ic_number'] ?? 'N/A',
                'phone' => $currentUser['phone'] ?? 'N/A',
                'email' => $currentUser['email'] ?? null,
                'gender' => $gender,
                'nationality' => $nationality,
                'document_path' => $documentPath,
                'notes' => $notes
            ]);

            if ($res['success']) {
                set_flash('success', "Application submitted successfully! Tracking Reference: {$res['application_reference']}");
                header('Location: ' . BASE_URL . '/user/applications.php');
                exit();
            } else {
                $error = 'Failed to submit application. Please try again.';
            }
        }
    }
}

// Fetch user's applications
$myAppsStmt = $pdo->prepare("SELECT a.*, s.service_name, s.service_code, u.name as reviewer_name 
                             FROM `applications` a 
                             JOIN `services` s ON a.service_id = s.id 
                             LEFT JOIN `users` u ON a.reviewed_by = u.id 
                             WHERE a.user_id = :uid 
                             ORDER BY a.id DESC");
$myAppsStmt->execute([':uid' => $userId]);
$myApplications = $myAppsStmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="app-main d-flex flex-column flex-grow-1">
    <header class="app-header d-flex justify-content-between align-items-center px-4 py-3 bg-white border-bottom">
        <div class="d-flex align-items-center">
            <button type="button" class="btn btn-link text-dark p-0 me-3 d-lg-none" onclick="toggleMobileSidebar()">
                <i class="bi bi-list fs-3"></i>
            </button>
            <h4 class="mb-0 fw-bold">Online Document Applications</h4>
        </div>
    </header>

    <main class="app-content p-4 flex-grow-1 bg-light">
        <?= display_flash() ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger d-flex align-items-center" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                <div><?= e($error) ?></div>
            </div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <!-- Submission Form -->
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm rounded-4 bg-white p-4">
                    <h5 class="fw-bold mb-3 d-flex align-items-center">
                        <i class="bi bi-file-earmark-plus text-primary me-2"></i> Submit New Application
                    </h5>

                    <form method="POST" action="<?= BASE_URL ?>/user/applications.php" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="submit">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Applicant Name</label>
                            <input type="text" class="form-control bg-light" value="<?= e($currentUser['name']) ?>" readonly>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold">IC / Passport</label>
                                <input type="text" class="form-control bg-light" value="<?= e($currentUser['ic_number'] ?? 'N/A') ?>" readonly>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">Phone Number</label>
                                <input type="text" class="form-control bg-light" value="<?= e($currentUser['phone'] ?? 'N/A') ?>" readonly>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="service_id" class="form-label fw-semibold">Application Service <span class="text-danger">*</span></label>
                            <select name="service_id" id="service_id" class="form-select" required>
                                <option value="">-- Choose Target Service --</option>
                                <?php foreach ($services as $svc): ?>
                                    <option value="<?= $svc['id'] ?>">
                                        <?= e($svc['service_code']) ?> - <?= e($svc['service_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label for="gender" class="form-label fw-semibold">Gender</label>
                                <select name="gender" id="gender" class="form-select">
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label for="nationality" class="form-label fw-semibold">Nationality</label>
                                <input type="text" name="nationality" id="nationality" class="form-control" value="Malaysian">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="document" class="form-label fw-semibold">Support Document (PDF / Image)</label>
                            <input type="file" name="document" id="document" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                            <div class="form-text">Max file size: 5MB. Formats: PDF, JPG, PNG.</div>
                        </div>

                        <div class="mb-4">
                            <label for="notes" class="form-label fw-semibold">Application Details / Remarks</label>
                            <textarea name="notes" id="notes" class="form-control" rows="3" placeholder="Provide background details for this application..."></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2 fw-bold rounded-3">
                            <i class="bi bi-send-fill me-1"></i> Submit Application
                        </button>
                    </form>
                </div>
            </div>

            <!-- User's Applications List -->
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm rounded-4 bg-white">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="fw-bold mb-0">My Application History</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light small text-uppercase">
                                    <tr>
                                        <th class="ps-3">Reference</th>
                                        <th>Service</th>
                                        <th>Submitted</th>
                                        <th>Status</th>
                                        <th class="pe-3">Review Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($myApplications)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-4 text-muted">
                                                No online applications submitted yet.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($myApplications as $app): ?>
                                            <tr>
                                                <td class="ps-3 font-monospace fw-bold text-dark">
                                                    <?= e($app['application_reference']) ?>
                                                    <?php if (!empty($app['document_path'])): ?>
                                                        <a href="<?= BASE_URL ?>/<?= e($app['document_path']) ?>" target="_blank" class="d-block small text-primary text-decoration-none">
                                                            <i class="bi bi-paperclip"></i> View File
                                                        </a>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-dark border me-1"><?= e($app['service_code']) ?></span>
                                                    <?= e($app['service_name']) ?>
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
                                                <td class="pe-3 small text-muted">
                                                    <?php if (!empty($app['reviewer_remarks'])): ?>
                                                        <div class="text-dark fw-semibold"><?= e($app['reviewer_remarks']) ?></div>
                                                        <div style="font-size: 0.75rem;">By <?= e($app['reviewer_name'] ?? 'Officer') ?> on <?= date('Y-m-d', strtotime($app['reviewed_at'])) ?></div>
                                                    <?php else: ?>
                                                        <span class="text-muted">Awaiting Review</span>
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
            </div>
        </div>

    </main>

    <?php require_once __DIR__ . '/../includes/footer.php'; ?>
</div>
