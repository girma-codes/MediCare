
<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();
requireRole(['admin', 'receptionist']);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$patientId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$patientId) {
    die('Invalid patient ID.');
}

$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.user_id,
        p.patient_number,
        p.date_of_birth,
        p.gender,
        p.phone,
        p.address,
        p.emergency_contact,
        u.full_name,
        u.email,
        u.status
    FROM patients p
    LEFT JOIN users u ON p.user_id = u.id
    WHERE p.id = ?
    LIMIT 1
");
$stmt->execute([$patientId]);
$patient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
    die('Patient not found.');
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = 'Invalid security token. Please refresh the page.';
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $dob = $_POST['date_of_birth'] ?? '';
        $gender = $_POST['gender'] ?? '';
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $emergencyContact = trim($_POST['emergency_contact'] ?? '');
        $status = $_POST['status'] ?? '';

        if ($fullName === '') {
            $errors[] = 'Full name is required.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        if (!in_array($gender, ['male', 'female', 'other'], true)) {
            $errors[] = 'Please select a valid gender.';
        }

        if (!in_array($status, ['active', 'inactive'], true)) {
            $errors[] = 'Please select a valid account status.';
        }

        if ($dob !== '') {
            $date = DateTime::createFromFormat('Y-m-d', $dob);

            if (!$date || $date->format('Y-m-d') !== $dob || $dob > date('Y-m-d')) {
                $errors[] = 'Please enter a valid date of birth.';
            }
        }

        // Check whether another user already uses this email.
        $emailCheck = $pdo->prepare("
            SELECT id FROM users
            WHERE email = ? AND id != ?
            LIMIT 1
        ");
        $emailCheck->execute([$email, $patient['user_id']]);

        if ($emailCheck->fetch()) {
            $errors[] = 'This email address is already registered.';
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                $updateUser = $pdo->prepare("
                    UPDATE users
                    SET full_name = ?, email = ?, status = ?
                    WHERE id = ?
                ");

                $updateUser->execute([
                    $fullName,
                    $email,
                    $status,
                    $patient['user_id']
                ]);

                $updatePatient = $pdo->prepare("
                    UPDATE patients
                    SET date_of_birth = ?,
                        gender = ?,
                        phone = ?,
                        address = ?,
                        emergency_contact = ?
                    WHERE id = ?
                ");

                $updatePatient->execute([
                    $dob !== '' ? $dob : null,
                    $gender,
                    $phone,
                    $address,
                    $emergencyContact,
                    $patientId
                ]);

                $pdo->commit();

                // Refresh patient information.
                $stmt->execute([$patientId]);
                $patient = $stmt->fetch(PDO::FETCH_ASSOC);

                $success = 'Patient information updated successfully.';

            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $errors[] = 'Unable to update patient information. Please try again.';
            }
        }

        // Keep submitted values visible if validation fails.
        if (!empty($errors)) {
            $patient['full_name'] = $fullName;
            $patient['email'] = $email;
            $patient['date_of_birth'] = $dob;
            $patient['gender'] = $gender;
            $patient['phone'] = $phone;
            $patient['address'] = $address;
            $patient['emergency_contact'] = $emergencyContact;
            $patient['status'] = $status;
        }
    }
}

$dashboardUrl = $_SESSION['role'] === 'receptionist'
    ? '../receptionist/dashboard.php'
    : '../admin/dashboard.php';

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Patient | MediCare HMS</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f3f6fb;
            color: #243047;
            padding: 30px 16px;
        }

        .container {
            max-width: 900px;
            margin: auto;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .topbar h1 {
            font-size: 25px;
            color: #172b4d;
        }

        .topbar p {
            color: #718096;
            margin-top: 6px;
            font-size: 14px;
        }

        .back {
            text-decoration: none;
            color: #2563eb;
            font-weight: 600;
        }

        .card {
            background: #fff;
            border-radius: 14px;
            padding: 28px;
            box-shadow: 0 5px 25px rgba(20, 40, 80, .07);
        }

        .patient-info {
            background: #eff6ff;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            color: #1d4ed8;
        }

        .patient-info strong {
            display: block;
            margin-bottom: 5px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        label {
            font-size: 14px;
            font-weight: 600;
        }

        input, select, textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #dce3ed;
            border-radius: 8px;
            outline: none;
            font-size: 14px;
            background: #fff;
        }

        input:focus, select:focus, textarea:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .1);
        }

        textarea {
            min-height: 90px;
            resize: vertical;
        }

        .actions {
            display: flex;
            gap: 12px;
            margin-top: 25px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-primary {
            background: #2563eb;
            color: #fff;
        }

        .btn-primary:hover {
            background: #1d4ed8;
        }

        .btn-secondary {
            background: #e8edf5;
            color: #334155;
        }

        .alert {
            padding: 13px 15px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 14px;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
        }

        @media(max-width: 600px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.full {
                grid-column: auto;
            }

            .card {
                padding: 18px;
            }
        }
    </style>
</head>
<body>

<div class="container">

    <div class="topbar">
        <div>
            <h1><i class="fa-solid fa-user-pen"></i> Edit Patient</h1>
            <p>Update patient personal and contact information.</p>
        </div>

        <a class="back" href="view.php?id=<?= (int)$patientId ?>">
            <i class="fa-solid fa-arrow-left"></i> Back to Patient
        </a>
    </div>

    <div class="card">

        <div class="patient-info">
            <strong><?= e($patient['patient_number']) ?></strong>
            Patient Registration Number
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check"></i>
                <?= e($success) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                    <div><i class="fa-solid fa-circle-exclamation"></i> <?= e($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="edit.php?id=<?= (int)$patientId ?>">

            <input type="hidden" name="csrf_token"
                   value="<?= e($_SESSION['csrf_token']) ?>">

            <div class="form-grid">

                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="full_name"
                           value="<?= e($patient['full_name']) ?>" required>
                </div>

                <div class="form-group">
                    <label>Email Address *</label>
                    <input type="email" name="email"
                           value="<?= e($patient['email']) ?>" required>
                </div>

                <div class="form-group">
                    <label>Date of Birth</label>
                    <input type="date" name="date_of_birth"
                           max="<?= date('Y-m-d') ?>"
                           value="<?= e($patient['date_of_birth']) ?>">
                </div>

                <div class="form-group">
                    <label>Gender *</label>
                    <select name="gender" required>
                        <option value="">Select Gender</option>
                        <option value="male" <?= $patient['gender'] === 'male' ? 'selected' : '' ?>>Male</option>
                        <option value="female" <?= $patient['gender'] === 'female' ? 'selected' : '' ?>>Female</option>
                        <option value="other" <?= $patient['gender'] === 'other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="text" name="phone"
                           value="<?= e($patient['phone']) ?>">
                </div>

                <div class="form-group">
                    <label>Emergency Contact</label>
                    <input type="text" name="emergency_contact"
                           value="<?= e($patient['emergency_contact']) ?>">
                </div>

                <div class="form-group full">
                    <label>Address</label>
                    <textarea name="address"><?= e($patient['address']) ?></textarea>
                </div>

                <div class="form-group">
                    <label>Account Status *</label>
                    <select name="status" required>
                        <option value="active" <?= $patient['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $patient['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>

            </div>

            <div class="actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk"></i> Save Changes
                </button>

                <a href="view.php?id=<?= (int)$patientId ?>"
                   class="btn btn-secondary">
                    Cancel
                </a>
            </div>

        </form>
    </div>
</div>

</body>
</html>
