
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole(['doctor']);

$userId = (int) $_SESSION['user_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = '';
$error = '';

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Load doctor profile
$stmt = $pdo->prepare("
    SELECT
        u.id,
        u.full_name,
        u.email,
        d.phone,
        d.specialization,
        d.department_id,
        dep.name AS department_name
    FROM users u
    INNER JOIN doctors d ON d.user_id = u.id
    LEFT JOIN departments dep ON dep.id = d.department_id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$doctor = $stmt->fetch();

if (!$doctor) {
    exit('Doctor profile not found.');
}

// Update profile
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $error = 'Invalid request. Please refresh the page.';
    } else {

        $action = $_POST['action'] ?? '';

        try {

            if ($action === 'update_profile') {

                $fullName = trim($_POST['full_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');

                if ($fullName === '' || $email === '') {
                    throw new Exception('Full Name and Email are required.');
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Please enter a valid email address.');
                }

                if (strlen($fullName) > 150 || strlen($email) > 150) {
                    throw new Exception('The entered information is too long.');
                }

                if (strlen($phone) > 30) {
                    throw new Exception('Phone number is too long.');
                }

                // Check duplicate email
                $check = $pdo->prepare("
                    SELECT id FROM users
                    WHERE email = ? AND id != ?
                    LIMIT 1
                ");
                $check->execute([$email, $userId]);

                if ($check->fetch()) {
                    throw new Exception('This email address is already in use.');
                }

                $pdo->beginTransaction();

                $updateUser = $pdo->prepare("
                    UPDATE users
                    SET full_name = ?, email = ?
                    WHERE id = ?
                ");
                $updateUser->execute([$fullName, $email, $userId]);

                $updateDoctor = $pdo->prepare("
                    UPDATE doctors
                    SET phone = ?
                    WHERE user_id = ?
                ");
                $updateDoctor->execute([$phone, $userId]);

                $pdo->commit();

                $_SESSION['full_name'] = $fullName;
                $_SESSION['email'] = $email;

                $success = 'Your profile has been updated successfully.';

            } elseif ($action === 'change_password') {

                $currentPassword = $_POST['current_password'] ?? '';
                $newPassword = $_POST['new_password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';

                if (
                    $currentPassword === '' ||
                    $newPassword === '' ||
                    $confirmPassword === ''
                ) {
                    throw new Exception('Please fill in all password fields.');
                }

                if (strlen($newPassword) < 8) {
                    throw new Exception('New password must be at least 8 characters.');
                }

                if ($newPassword !== $confirmPassword) {
                    throw new Exception('New passwords do not match.');
                }

                $passwordQuery = $pdo->prepare("
                    SELECT password FROM users WHERE id = ?
                ");
                $passwordQuery->execute([$userId]);
                $passwordData = $passwordQuery->fetch();

                if (
                    !$passwordData ||
                    !password_verify($currentPassword, $passwordData['password'])
                ) {
                    throw new Exception('Current password is incorrect.');
                }

                $hashedPassword = password_hash(
                    $newPassword,
                    PASSWORD_DEFAULT
                );

                $updatePassword = $pdo->prepare("
                    UPDATE users SET password = ? WHERE id = ?
                ");
                $updatePassword->execute([$hashedPassword, $userId]);

                $success = 'Your password has been changed successfully.';

            } else {
                throw new Exception('Invalid action.');
            }

        } catch (Exception $ex) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $ex->getMessage();
        }

        // Reload updated profile
        $stmt->execute([$userId]);
        $doctor = $stmt->fetch();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Doctor Profile | MediCare HMS</title>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: Arial, sans-serif;
    background: #f4f7fb;
    color: #1e293b;
}

.sidebar {
    width: 250px;
    height: 100vh;
    background: #102a43;
    color: white;
    position: fixed;
    left: 0;
    top: 0;
    padding: 28px 18px;
}

.logo {
    font-size: 23px;
    font-weight: bold;
    margin-bottom: 38px;
    color: #38bdf8;
}

.sidebar a {
    display: block;
    color: #dbeafe;
    text-decoration: none;
    padding: 13px 15px;
    border-radius: 8px;
    margin-bottom: 7px;
    transition: .2s;
}

.sidebar a:hover,
.sidebar a.active {
    background: #1e496b;
    color: white;
}

.main {
    margin-left: 250px;
    padding: 35px;
}

.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 28px;
    gap: 15px;
}

.topbar h1 {
    font-size: 27px;
}

.topbar p {
    color: #64748b;
    margin-top: 7px;
}

.card {
    background: white;
    padding: 28px;
    border-radius: 14px;
    box-shadow: 0 4px 18px rgba(15, 23, 42, .05);
    margin-bottom: 25px;
    max-width: 900px;
}

.card h2 {
    font-size: 20px;
    margin-bottom: 8px;
}

.card .subtitle {
    color: #64748b;
    font-size: 14px;
    margin-bottom: 25px;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.form-group {
    margin-bottom: 18px;
}

label {
    display: block;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 8px;
}

input {
    width: 100%;
    padding: 13px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    outline: none;
    font-size: 14px;
}

input:focus {
    border-color: #0284c7;
    box-shadow: 0 0 0 3px rgba(2,132,199,.1);
}

input[readonly] {
    background: #f1f5f9;
    color: #64748b;
}

.btn {
    border: none;
    padding: 12px 20px;
    border-radius: 8px;
    cursor: pointer;
    font-weight: bold;
    font-size: 14px;
}

.btn-primary {
    background: #0284c7;
    color: white;
}

.btn-primary:hover {
    background: #0369a1;
}

.message {
    max-width: 900px;
    padding: 14px 17px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.success {
    background: #dcfce7;
    color: #166534;
}

.error {
    background: #fee2e2;
    color: #991b1b;
}

@media(max-width: 800px) {
    .sidebar {
        width: 100%;
        height: auto;
        position: relative;
    }

    .main {
        margin-left: 0;
        padding: 20px;
    }

    .form-grid {
        grid-template-columns: 1fr;
        gap: 0;
    }

    .topbar {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>
</head>

<body>

<aside class="sidebar">
    <div class="logo">MediCare HMS</div>

    <a href="dashboard.php">Dashboard</a>
    <a href="appointments.php">Manage Appointments</a>
    <a href="medical-records.php">Medical Records</a>
    <a href="patient-history.php">Patient Medical History</a>
    <a href="profile.php" class="active">My Profile</a>
    <a href="../logout.php">Logout</a>
</aside>

<main class="main">

    <div class="topbar">
        <div>
            <h1>Doctor Profile</h1>
            <p>Manage your personal information and account security.</p>
        </div>
        <strong>Dr. <?= e($doctor['full_name']) ?></strong>
    </div>

    <?php if ($success): ?>
        <div class="message success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="message error"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="card">
        <h2>Personal Information</h2>
        <p class="subtitle">Update your basic account information.</p>

        <form method="POST">
            <input type="hidden" name="csrf_token"
                   value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="update_profile">

            <div class="form-grid">

                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="full_name"
                           maxlength="150" required
                           value="<?= e($doctor['full_name']) ?>">
                </div>

                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email"
                           maxlength="150" required
                           value="<?= e($doctor['email']) ?>">
                </div>

                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="text" name="phone"
                           maxlength="30"
                           value="<?= e($doctor['phone']) ?>">
                </div>

                <div class="form-group">
                    <label>Specialization</label>
                    <input type="text" readonly
                           value="<?= e($doctor['specialization']) ?>">
                </div>

                <div class="form-group">
                    <label>Department</label>
                    <input type="text" readonly
                           value="<?= e($doctor['department_name']) ?>">
                </div>

            </div>

            <button type="submit" class="btn btn-primary">
                Save Changes
            </button>
        </form>
    </section>

    <section class="card">
        <h2>Change Password</h2>
        <p class="subtitle">
            Enter your current password before creating a new one.
        </p>

        <form method="POST">
            <input type="hidden" name="csrf_token"
                   value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="change_password">

            <div class="form-grid">

                <div class="form-group">
                    <label>Current Password</label>
                    <input type="password" name="current_password"
                           autocomplete="current-password" required>
                </div>

                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password"
                           minlength="8" autocomplete="new-password" required>
                </div>

                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password"
                           minlength="8" autocomplete="new-password" required>
                </div>

            </div>

            <button type="submit" class="btn btn-primary">
                Change Password
            </button>
        </form>
    </section>

</main>

</body>
</html>
