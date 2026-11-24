<?php
// ============================================================
// Authentication - Citizen Registration
// ============================================================

require_once __DIR__ . '/../config/config.php';

// Redirect if already logged in
if (!empty($_SESSION['user_id'])) {
    if ($_SESSION['role_name'] === 'Admin') {
        header('Location: ' . BASE_URL . '/admin/dashboard.php');
    } elseif ($_SESSION['role_name'] === 'Staff') {
        header('Location: ' . BASE_URL . '/staff/dashboard.php');
    } else {
        header('Location: ' . BASE_URL . '/user/dashboard.php');
    }
    exit();
}

$error = '';
$success = '';
$name = '';
$username = '';
$email = '';
$icNumber = '';
$phone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $icNumber = trim($_POST['ic_number'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($name) || empty($username) || empty($email) || empty($password) || empty($icNumber) || empty($phone)) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $pdo = Database::getConnection();

        // Check if username, email, or IC exists
        $checkStmt = $pdo->prepare("SELECT id FROM `users` WHERE `username` = :u OR `email` = :e OR `ic_number` = :ic LIMIT 1");
        $checkStmt->execute([':u' => $username, ':e' => $email, ':ic' => $icNumber]);
        if ($checkStmt->fetch()) {
            $error = 'An account with that username, email, or IC number already exists.';
        } else {
            // Find role_id for 'User'
            $roleStmt = $pdo->query("SELECT id FROM `roles` WHERE `role_name` = 'User' LIMIT 1");
            $roleId = (int)$roleStmt->fetchColumn() ?: 3;

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $insStmt = $pdo->prepare("INSERT INTO `users` (`name`, `username`, `email`, `password`, `role_id`, `ic_number`, `phone`, `status`) 
                                      VALUES (:n, :u, :e, :p, :r, :ic, :ph, 'active')");
            $insStmt->execute([
                ':n' => $name,
                ':u' => $username,
                ':e' => $email,
                ':p' => $hash,
                ':r' => $roleId,
                ':ic' => $icNumber,
                ':ph' => $phone
            ]);

            log_activity(null, 'CITIZEN_REGISTER', "New citizen registered: {$username} ({$email})");
            set_flash('success', 'Your citizen account has been created successfully! You can now log in.');
            header('Location: ' . BASE_URL . '/auth/login.php');
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Citizen Registration - <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center min-vh-100 py-4">
    <div class="container" style="max-width: 520px;">
        <div class="text-center mb-4">
            <div class="d-inline-flex p-3 bg-primary bg-opacity-10 text-primary rounded-circle mb-2">
                <i class="bi bi-person-plus-fill fs-1"></i>
            </div>
            <h3 class="fw-bold text-dark"><?= e(APP_NAME) ?></h3>
            <p class="text-muted">Public Citizen Registration</p>
        </div>

        <div class="card shadow-sm border-0 rounded-3">
            <div class="card-body p-4">
                <?= display_flash() ?>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger d-flex align-items-center" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <div><?= e($error) ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="<?= BASE_URL ?>/auth/register.php">
                    <?= csrf_field() ?>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label for="name" class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name" value="<?= e($name) ?>" required placeholder="e.g. John Doe">
                        </div>
                        <div class="col-md-6">
                            <label for="ic_number" class="form-label fw-semibold">IC / Passport <span class="text-danger">*</span></label>
                            <input type="text" class="form-control text-uppercase" id="ic_number" name="ic_number" value="<?= e($icNumber) ?>" required placeholder="e.g. 950101-14-1234">
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label for="email" class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="email" name="email" value="<?= e($email) ?>" required placeholder="name@example.com">
                        </div>
                        <div class="col-md-6">
                            <label for="phone" class="form-label fw-semibold">Phone Number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="phone" name="phone" value="<?= e($phone) ?>" required placeholder="+60 12-345 6789">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="username" class="form-label fw-semibold">Username <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-person"></i></span>
                            <input type="text" class="form-control" id="username" name="username" value="<?= e($username) ?>" required placeholder="Choose a username">
                        </div>
                    </div>

                    <div class="row g-2 mb-4">
                        <div class="col-md-6">
                            <label for="password" class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="password" name="password" required placeholder="Min 6 characters">
                        </div>
                        <div class="col-md-6">
                            <label for="confirm_password" class="form-label fw-semibold">Confirm Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required placeholder="Re-type password">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
                        <i class="bi bi-person-check me-1"></i> Complete Registration
                    </button>
                </form>
            </div>
            <div class="card-footer bg-light border-0 py-3 text-center">
                <small class="text-muted">
                    Already registered? 
                    <a href="<?= BASE_URL ?>/auth/login.php" class="text-decoration-none fw-bold">
                        Sign In here <i class="bi bi-arrow-right"></i>
                    </a>
                </small>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
