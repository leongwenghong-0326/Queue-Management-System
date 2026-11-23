<?php
// ============================================================
// Admin - Staff & User Account Management
// ============================================================

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/role_check.php';
require_role('Admin');

$pageTitle = 'Manage Staff Accounts';
$pdo = Database::getConnection();

// Handle Form Submission (Create / Update / Toggle Status)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $roleId = (int)($_POST['role_id'] ?? 2);
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if (empty($name) || empty($username) || empty($email) || empty($password)) {
            set_flash('danger', 'All fields are required to create a new user.');
        } else {
            // Check username or email uniqueness
            $checkStmt = $pdo->prepare("SELECT id FROM `users` WHERE `username` = :u OR `email` = :e LIMIT 1");
            $checkStmt->execute([':u' => $username, ':e' => $email]);
            if ($checkStmt->fetch()) {
                set_flash('danger', 'Username or Email is already registered.');
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO `users` (`name`, `username`, `email`, `password`, `role_id`, `status`, `created_at`) 
                                       VALUES (:name, :u, :e, :p, :rid, :status, NOW())");
                $stmt->execute([
                    ':name' => $name,
                    ':u' => $username,
                    ':e' => $email,
                    ':p' => $hash,
                    ':rid' => $roleId,
                    ':status' => $status
                ]);
                log_activity($_SESSION['user_id'], 'USER_CREATED', "Created user account: {$username} ({$name})");
                set_flash('success', "Staff account '{$username}' created successfully.");
                header('Location: ' . BASE_URL . '/admin/users/index.php');
                exit();
            }
        }
    } elseif ($action === 'update') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $roleId = (int)($_POST['role_id'] ?? 2);
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';
        $newPassword = $_POST['password'] ?? '';

        if (empty($name) || empty($email)) {
            set_flash('danger', 'Name and Email are required.');
        } else {
            if (!empty($newPassword)) {
                $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE `users` SET `name` = :name, `email` = :email, `role_id` = :rid, `status` = :status, `password` = :p WHERE `id` = :id");
                $stmt->execute([':name' => $name, ':email' => $email, ':rid' => $roleId, ':status' => $status, ':p' => $hash, ':id' => $userId]);
            } else {
                $stmt = $pdo->prepare("UPDATE `users` SET `name` = :name, `email` = :email, `role_id` = :rid, `status` = :status WHERE `id` = :id");
                $stmt->execute([':name' => $name, ':email' => $email, ':rid' => $roleId, ':status' => $status, ':id' => $userId]);
            }
            log_activity($_SESSION['user_id'], 'USER_UPDATED', "Updated user account ID: {$userId}");
            set_flash('success', "User account updated successfully.");
            header('Location: ' . BASE_URL . '/admin/users/index.php');
            exit();
        }
    } elseif ($action === 'toggle_status') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId === (int)$_SESSION['user_id']) {
            set_flash('danger', 'You cannot deactivate your own administrative account.');
        } else {
            $stmt = $pdo->prepare("UPDATE `users` SET `status` = IF(`status` = 'active', 'inactive', 'active') WHERE `id` = :id");
            $stmt->execute([':id' => $userId]);
            log_activity($_SESSION['user_id'], 'USER_STATUS_TOGGLED', "Toggled status for user ID: {$userId}");
            set_flash('success', "Account status toggled successfully.");
        }
        header('Location: ' . BASE_URL . '/admin/users/index.php');
        exit();
    }
}

// Fetch all users with their roles
$users = $pdo->query("SELECT u.*, r.role_name 
                      FROM `users` u 
                      JOIN `roles` r ON u.role_id = r.id 
                      ORDER BY u.id ASC")->fetchAll();

// Fetch roles for dropdown
$roles = $pdo->query("SELECT * FROM `roles` ORDER BY id ASC")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1">Staff & Officer Accounts</h4>
        <p class="text-muted small mb-0">Manage system personnel, counter officers, and role authorizations.</p>
    </div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">
        <i class="bi bi-person-plus-fill me-1"></i> Add New Account
    </button>
</div>

<!-- Users Table -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">ID</th>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td class="ps-3 fw-bold text-muted">#<?= $u['id'] ?></td>
                            <td class="fw-semibold text-dark"><?= e($u['name']) ?></td>
                            <td><code><?= e($u['username']) ?></code></td>
                            <td class="text-muted small"><?= e($u['email']) ?></td>
                            <td>
                                <span class="badge <?= $u['role_name'] === 'Admin' ? 'bg-dark' : 'bg-primary' ?>">
                                    <?= e($u['role_name']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $u['status'] === 'active' ? 'bg-success' : 'bg-danger' ?>">
                                    <?= ucfirst($u['status']) ?>
                                </span>
                            </td>
                            <td class="small text-muted"><?= $u['last_login'] ? e($u['last_login']) : 'Never' ?></td>
                            <td class="text-end pe-3">
                                <button type="button" class="btn btn-sm btn-outline-secondary edit-user-btn" 
                                    data-id="<?= $u['id'] ?>"
                                    data-name="<?= e($u['name']) ?>"
                                    data-email="<?= e($u['email']) ?>"
                                    data-role="<?= $u['role_id'] ?>"
                                    data-status="<?= $u['status'] ?>"
                                    data-bs-toggle="modal" data-bs-target="#editUserModal">
                                    <i class="bi bi-pencil"></i> Edit
                                </button>
                                <?php if ($u['id'] !== (int)$_SESSION['user_id']): ?>
                                    <form method="POST" action="" class="d-inline ms-1" onsubmit="return confirm('Toggle status for this user?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-sm <?= $u['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                            <i class="bi <?= $u['status'] === 'active' ? 'bi-person-slash' : 'bi-person-check' ?>"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Create User -->
<div class="modal fade" id="createUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Create Staff Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Full Name</label>
                        <input type="text" name="name" class="form-control" required placeholder="e.g. John Doe">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Username</label>
                            <input type="text" name="username" class="form-control" required placeholder="e.g. jdoe">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Role</label>
                            <select name="role_id" class="form-select" required>
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?= $r['id'] ?>" <?= $r['role_name'] === 'Staff' ? 'selected' : '' ?>><?= e($r['role_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email Address</label>
                        <input type="email" name="email" class="form-control" required placeholder="jdoe@example.com">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Password</label>
                        <input type="password" name="password" class="form-control" required placeholder="Minimum 6 characters">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Initial Status</label>
                        <select name="status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit User -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Edit User Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Full Name</label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email Address</label>
                        <input type="email" name="email" id="edit_email" class="form-control" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Role</label>
                            <select name="role_id" id="edit_role_id" class="form-select" required>
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?= $r['id'] ?>"><?= e($r['role_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="status" id="edit_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Change Password (leave blank to keep current)</label>
                        <input type="password" name="password" class="form-control" placeholder="Enter new password if resetting">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.edit-user-btn').forEach(button => {
    button.addEventListener('click', function() {
        document.getElementById('edit_user_id').value = this.dataset.id;
        document.getElementById('edit_name').value = this.dataset.name;
        document.getElementById('edit_email').value = this.dataset.email;
        document.getElementById('edit_role_id').value = this.dataset.role;
        document.getElementById('edit_status').value = this.dataset.status;
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
