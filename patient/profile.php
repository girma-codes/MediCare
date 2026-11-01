<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['patient']);
require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$userId = $_SESSION['user_id'];
$message = '';
$error = '';

$stmt = $pdo->prepare("
    SELECT
        u.full_name,
        u.email,
        p.patient_number,
        p.date_of_birth,
        p.gender,
        p.phone,
        p.address,
        p.emergency_contact
    FROM patients p
    JOIN users u ON u.id = p.user_id
    WHERE p.user_id = :user_id
    LIMIT 1
");

$stmt->execute(['user_id' => $_SESSION['user_id']]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile) {
    exit('Patient profile not found.');
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh the page.';
    } else {
        $name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $emergency = trim($_POST['emergency_contact'] ?? '');
        $dob = trim($_POST['date_of_birth'] ?? '');
        $gender = trim($_POST['gender'] ?? '');

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid name and email address.';
        } elseif (!in_array($gender, ['', 'male', 'female', 'other'], true)) {
            $error = 'Please select a valid gender.';
        } else {
            $dateValue = null;

            if ($dob !== '') {
                $date = DateTime::createFromFormat('Y-m-d', $dob);
                if (!$date || $date->format('Y-m-d') !== $dob || $dob > date('Y-m-d')) {
                    $error = 'Please enter a valid date of birth.';
                } else {
                    $dateValue = $dob;
                }
            }

            if ($error === '') {
                $check = $pdo->prepare("
                    SELECT id FROM users
                    WHERE email = ? AND id != ?
                ");
                $check->execute([$email, $userId]);

                if ($check->fetch()) {
                    $error = 'This email address is already in use.';
                } else {
                    try {
                        $pdo->beginTransaction();

                        $updateUser = $pdo->prepare("
                            UPDATE users
                            SET full_name = ?, email = ?
                            WHERE id = ?
                        ");
                        $updateUser->execute([$name, $email, $userId]);

                        $updatePatient = $pdo->prepare("
                            UPDATE patients
                            SET date_of_birth = ?,
                                gender = ?,
                                phone = ?,
                                address = ?,
                                emergency_contact = ?
                            WHERE user_id = ?
                        ");
                        $updatePatient->execute([
                            $dateValue,
                            $gender !== '' ? $gender : null,
                            $phone,
                            $address,
                            $emergency,
                            $userId
                        ]);

                        $pdo->commit();

                        $_SESSION['full_name'] = $name;
                        $_SESSION['email'] = $email;

                        $message = 'Your profile has been updated successfully.';

                        $stmt->execute([$userId]);
                        $profile = $stmt->fetch();

                    } catch (PDOException $ex) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log($ex->getMessage());
                        $error = 'Unable to update your profile. Please try again.';
                    }
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile | MediCare HMS</title>

<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,sans-serif;background:#f4f7fb;color:#263449}
.sidebar{width:245px;height:100vh;position:fixed;background:#102b46;color:white;padding:30px 18px}
.logo{font-size:23px;font-weight:bold;color:#40d6bd;margin-bottom:40px}
.sidebar a{display:block;color:#dce7f1;text-decoration:none;padding:14px;border-radius:9px;margin-bottom:7px}
.sidebar a:hover,.sidebar a.active{background:#1b4666;color:white}
.main{margin-left:245px;padding:35px}
.header{margin-bottom:25px}
.header h1{font-size:28px}
.subtitle{color:#8290a2;margin-top:8px}
.card{background:white;padding:30px;border-radius:15px;max-width:950px;box-shadow:0 5px 20px #00000008}
.patient-number{background:#e8f8f5;color:#138a78;padding:12px 16px;border-radius:8px;margin-bottom:25px;display:inline-block;font-weight:bold}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.field label{display:block;font-size:14px;font-weight:bold;margin-bottom:8px}
.field input,.field select,.field textarea{width:100%;padding:13px;border:1px solid #dce3eb;border-radius:8px;font-size:14px;outline:none}
.field input:focus,.field select:focus,.field textarea:focus{border-color:#20b99f}
.field textarea{resize:vertical;min-height:85px}
.full{grid-column:1/-1}
button{margin-top:24px;background:#159f8b;color:white;border:0;padding:14px 25px;border-radius:8px;font-weight:bold;cursor:pointer}
button:hover{background:#107d70}
.alert{padding:14px;border-radius:8px;margin-bottom:20px}
.success{background:#e1f7eb;color:#187b4c}
.danger{background:#ffe5e5;color:#b52d2d}
@media(max-width:800px){.sidebar{width:100%;height:auto;position:relative}.main{margin:0;padding:20px}.form-grid{grid-template-columns:1fr}.full{grid-column:auto}}
</style>
</head>

<body>

<aside class="sidebar">
    <div class="logo">✚ MediCare</div>
    <a href="dashboard.php">Dashboard</a>
    <a href="appointments.php">Book Appointment</a>
    <a href="my-appointments.php">My Appointments</a>
    <a href="medical-history.php">Medical History</a>
    <a href="profile.php" class="active">My Profile</a>
    <a href="notifications.php">Notifications</a>
    <a href="../logout.php">Logout</a>
</aside>

<main class="main">
    <div class="header">
        <h1>My Profile</h1>
        <p class="subtitle">View and update your personal information.</p>
    </div>

    <section class="card">

        <?php if ($message): ?>
            <div class="alert success"><?= e($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert danger"><?= e($error) ?></div>
        <?php endif; ?>

        <div class="patient-number">
            Patient ID: <?= e($profile['patient_number']) ?>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token"
                   value="<?= e($_SESSION['csrf_token']) ?>">

            <div class="form-grid">

                <div class="field">
                    <label>Full Name</label>
                    <input type="text" name="full_name"
                           value="<?= e($profile['full_name']) ?>" required>
                </div>

                <div class="field">
                    <label>Email Address</label>
                    <input type="email" name="email"
                           value="<?= e($profile['email']) ?>" required>
                </div>

                <div class="field">
                    <label>Phone Number</label>
                    <input type="text" name="phone"
                           value="<?= e($profile['phone']) ?>">
                </div>

                <div class="field">
                    <label>Date of Birth</label>
                    <input type="date" name="date_of_birth"
                           max="<?= date('Y-m-d') ?>"
                           value="<?= e($profile['date_of_birth']) ?>">
                </div>

                <div class="field">
                    <label>Gender</label>
                    <select name="gender">
                        <option value="">Select Gender</option>
                        <option value="male" <?= strtolower($profile['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
                        <option value="female" <?= strtolower($profile['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
                        <option value="other" <?= strtolower($profile['gender'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>

                <div class="field">
                    <label>Emergency Contact</label>
                    <input type="text" name="emergency_contact"
                           value="<?= e($profile['emergency_contact']) ?>">
                </div>

                <div class="field full">
                    <label>Address</label>
                    <textarea name="address"><?= e($profile['address']) ?></textarea>
                </div>

            </div>

            <button type="submit">Save Changes</button>
        </form>
    </section>
</main>

</body>
</html>