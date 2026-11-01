
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireLogin();
requireRole(['admin']);

$error = '';
$success = '';

$departments = $pdo->query(
    "SELECT id, name FROM departments ORDER BY name ASC"
)->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $departmentId = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
    $specialization = trim($_POST['specialization'] ?? '');
    $licenseNumber = trim($_POST['license_number'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if (
        $fullName === '' || $email === '' || $password === '' ||
        !$departmentId || $specialization === '' ||
        $licenseNumber === '' || $phone === ''
    ) {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        try {
            $checkDepartment = $pdo->prepare(
                "SELECT id FROM departments WHERE id = ?"
            );
            $checkDepartment->execute([$departmentId]);

            if (!$checkDepartment->fetch()) {
                throw new Exception('Selected department does not exist.');
            }

            $pdo->beginTransaction();

            $checkEmail = $pdo->prepare(
                "SELECT id FROM users WHERE email = ?"
            );
            $checkEmail->execute([$email]);

            if ($checkEmail->fetch()) {
                throw new Exception('This email is already registered.');
            }

            $checkLicense = $pdo->prepare(
                "SELECT id FROM doctors WHERE license_number = ?"
            );
            $checkLicense->execute([$licenseNumber]);

            if ($checkLicense->fetch()) {
                throw new Exception('This license number is already registered.');
            }

            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $userStmt = $pdo->prepare("
                INSERT INTO users
                    (full_name, email, password, role, status)
                VALUES (?, ?, ?, 'doctor', 'active')
            ");

            $userStmt->execute([
                $fullName,
                $email,
                $hashedPassword
            ]);

            $userId = $pdo->lastInsertId();

            $doctorStmt = $pdo->prepare("
                INSERT INTO doctors
                    (user_id, department_id, specialization, license_number, phone)
                VALUES (?, ?, ?, ?, ?)
            ");

            $doctorStmt->execute([
                $userId,
                $departmentId,
                $specialization,
                $licenseNumber,
                $phone
            ]);

            $pdo->commit();

            $success = 'Doctor registered successfully.';
            $_POST = [];

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Doctor | MediCare HMS</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            padding: 30px 15px;
        }

        .container {
            max-width: 750px;
            margin: 0 auto;
        }

        .form-card {
            background: white;
            padding: 30px;
            border-radius: 14px;
            box-shadow: 0 5px 20px rgba(0,0,0,.06);
        }

        h1 {
            margin-bottom: 8px;
            font-size: 27px;
        }

        .subtitle {
            color: #64748b;
            margin-bottom: 25px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .field {
            margin-bottom: 18px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
        }

        input, select {
            width: 100%;
            padding: 13px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 15px;
        }

        input:focus, select:focus {
            outline: 2px solid #99f6e4;
            border-color: #0d9488;
        }

        .btn {
            border: none;
            background: #0d9488;
            color: white;
            padding: 13px 20px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .back {
            background: #334155;
            margin-left: 8px;
        }

        .message {
            padding: 13px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        @media (max-width: 600px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-card {
                padding: 20px;
            }

            .field.full {
                grid-column: auto;
            }
        }
    </style>
</head>

<body>

<div class="container">
    <div class="form-card">

        <h1>Register New Doctor</h1>
        <p class="subtitle">
            Add a new doctor to MediCare Hospital Management System.
        </p>

        <?php if ($error): ?>
            <div class="message error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="message success">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($departments)): ?>
            <div class="message error">
                No departments found. Please add a department first.
            </div>
        <?php else: ?>

        <form method="POST" action="">

            <div class="form-grid">

                <div class="field full">
                    <label for="full_name">Doctor Full Name</label>
                    <input
                        type="text"
                        id="full_name"
                        name="full_name"
                        value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label for="email">Email Address</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label for="password">Login Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        minlength="8"
                        required
                    >
                </div>

                <div class="field">
                    <label for="department_id">Department</label>
                    <select id="department_id" name="department_id" required>
                        <option value="">Select Department</option>

                        <?php foreach ($departments as $department): ?>
                            <option
                                value="<?= (int)$department['id'] ?>"
                                <?= (($_POST['department_id'] ?? '') == $department['id']) ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($department['name']) ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="field">
                    <label for="specialization">Specialization</label>
                    <input
                        type="text"
                        id="specialization"
                        name="specialization"
                        value="<?= htmlspecialchars($_POST['specialization'] ?? '') ?>"
                        placeholder="e.g. Cardiology"
                        required
                    >
                </div>

                <div class="field">
                    <label for="license_number">License Number</label>
                    <input
                        type="text"
                        id="license_number"
                        name="license_number"
                        value="<?= htmlspecialchars($_POST['license_number'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="field">
                    <label for="phone">Phone Number</label>
                    <input
                        type="tel"
                        id="phone"
                        name="phone"
                        value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                        required
                    >
                </div>

            </div>

            <button type="submit" class="btn">
                Register Doctor
            </button>

            <a href="index.php" class="btn back">
                Back to Doctors
            </a>

        </form>

        <?php endif; ?>

    </div>
</div>

</body>
</html>
