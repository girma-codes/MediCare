
<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole(['admin', 'receptionist']);

$errors = [];
$success = '';

// CSRF protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verify CSRF token
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        $errors[] = 'Invalid form request. Please refresh the page.';
    }

    // Collect form data
    $fullName = trim($_POST['full_name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $gender = strtolower(trim($_POST['gender'] ?? ''));
    $dateOfBirth = trim($_POST['date_of_birth'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $emergencyContact = trim($_POST['emergency_contact'] ?? '');

    // Full name validation
    if ($fullName === '' || strlen($fullName) > 150) {
        $errors[] = 'Please enter a valid patient name.';
    }

    // Email validation
    if (
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        strlen($email) > 150
    ) {
        $errors[] = 'Please enter a valid email address.';
    }

    // Password validation
    if (strlen($password) < 8) {
        $errors[] = 'Password must contain at least 8 characters.';
    }

    // Gender validation
    if (!in_array($gender, ['male', 'female', 'other'], true)) {
        $errors[] = 'Please select a valid gender.';
    }

    // Date of birth validation
    if ($dateOfBirth !== '') {

        $date = DateTime::createFromFormat('!Y-m-d', $dateOfBirth);

        if (!$date || $date->format('Y-m-d') !== $dateOfBirth) {
            $errors[] = 'Please enter a valid date of birth.';
        } elseif ($date > new DateTime('today')) {
            $errors[] = 'Date of birth cannot be in the future.';
        }
    }

    // Phone number validation
    if ($phone !== '') {

        $cleanPhone = preg_replace('/[\s\-()]/', '', $phone);

        if (!preg_match('/^\+?[0-9]{7,15}$/', $cleanPhone)) {
            $errors[] = 'Please enter a valid phone number (7-15 digits).';
        }
    }

    // Address validation
    if (strlen($address) > 255) {
        $errors[] = 'Address must not exceed 255 characters.';
    }

    // Emergency contact validation
    if ($emergencyContact !== '') {

        $cleanEmergency = preg_replace(
            '/[\s\-()]/',
            '',
            $emergencyContact
        );

        if (!preg_match('/^\+?[0-9]{7,15}$/', $cleanEmergency)) {
            $errors[] = 'Please enter a valid emergency contact number.';
        }
    }

    // Save patient
    if (empty($errors)) {

        try {

            $pdo->beginTransaction();

            // Check duplicate email
            $checkEmail = $pdo->prepare(
                "SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1"
            );

            $checkEmail->execute([$email]);

            if ($checkEmail->fetch()) {
                throw new RuntimeException(
                    'This email address is already registered.'
                );
            }

            // Generate unique patient number
            do {

                $patientNumber = 'PAT-' .
                    strtoupper(bin2hex(random_bytes(4)));

                $checkNumber = $pdo->prepare(
                    "SELECT id FROM patients WHERE patient_number = ?"
                );

                $checkNumber->execute([$patientNumber]);

            } while ($checkNumber->fetch());

            // Hash password
            $hashedPassword = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            // Insert into users table
            $userSql = "
                INSERT INTO users
                (full_name, email, password, role, status)
                VALUES (?, ?, ?, 'patient', 'active')
            ";

            $userStmt = $pdo->prepare($userSql);

            $userStmt->execute([
                $fullName,
                $email,
                $hashedPassword
            ]);

            $userId = $pdo->lastInsertId();

            // Insert into patients table
            $patientSql = "
                INSERT INTO patients
                (
                    user_id,
                    patient_number,
                    date_of_birth,
                    gender,
                    phone,
                    address,
                    emergency_contact
                )
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ";

            $patientStmt = $pdo->prepare($patientSql);

            $patientStmt->execute([
                $userId,
                $patientNumber,
                $dateOfBirth !== '' ? $dateOfBirth : null,
                $gender,
                $phone !== '' ? $phone : null,
                $address !== '' ? $address : null,
                $emergencyContact !== '' ? $emergencyContact : null
            ]);

            // Commit transaction
            $pdo->commit();

            $success = "Patient registered successfully! Patient ID: "
                . $patientNumber;

            // Clear form after successful registration
            $_POST = [];

        } catch (RuntimeException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = $e->getMessage();

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Patient registration error: ' . $e->getMessage());

            if ($e->getCode() === '23000') {
                $errors[] = 'Email or patient information already exists.';
            } else {
                $errors[] = 'Database error. Please check the server error log.';
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

    <title>Register Patient | MediCare HMS</title>

    <link rel="stylesheet" href="../assets/css/admin.css">

    <style>

        .registration-container {
            max-width: 1000px;
            background: #ffffff;
            padding: 30px;
            border-radius: 14px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 22px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        .form-group label {
            color: #334155;
            font-weight: 600;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 13px;
            border: 1px solid #dbe2ea;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #2563eb;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 28px;
        }

        .register-button {
            background: #2563eb;
            color: white;
            border: none;
            padding: 13px 22px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }

        .cancel-button {
            background: #e2e8f0;
            color: #334155;
            padding: 13px 22px;
            border-radius: 8px;
            text-decoration: none;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .required {
            color: #dc2626;
        }

        @media(max-width: 650px) {

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.full {
                grid-column: auto;
            }

            .registration-container {
                padding: 18px;
            }
        }

    </style>

</head>

<body>

<main class="main-content">

    <header class="topbar">

        <div>
            <h3>Patient Registration</h3>
            <p>Register a new hospital patient</p>
        </div>

    </header>

    <section class="content">

        <div class="welcome-section">

            <div>
                <h1>New Patient Registration</h1>
                <p>Enter the patient's personal information.</p>
            </div>

        </div>

        <?php if ($success): ?>

            <div class="alert-success">

                <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>

                <p>
                    <a href="index.php">Go to Patient List</a>
                </p>

            </div>

        <?php endif; ?>

        <?php if (!empty($errors)): ?>

            <div class="alert-error">

                <?php foreach ($errors as $error): ?>

                    <p>
                        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                    </p>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

        <div class="registration-container">

            <form method="POST" action="">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
                >

                <div class="form-grid">

                    <div class="form-group full">

                        <label for="full_name">
                            Full Name <span class="required">*</span>
                        </label>

                        <input
                            type="text"
                            id="full_name"
                            name="full_name"
                            maxlength="150"
                            value="<?= htmlspecialchars($_POST['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="Enter patient's full name"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label for="email">
                            Email Address <span class="required">*</span>
                        </label>

                        <input
                            type="email"
                            id="email"
                            name="email"
                            maxlength="150"
                            value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="patient@example.com"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label for="password">
                            Account Password <span class="required">*</span>
                        </label>

                        <input
                            type="password"
                            id="password"
                            name="password"
                            minlength="8"
                            placeholder="Minimum 8 characters"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label for="gender">
                            Gender <span class="required">*</span>
                        </label>

                        <select id="gender" name="gender" required>

                            <option value="">Select gender</option>
                            <option value="male" <?= (($_POST['gender'] ?? '') === 'male') ? 'selected' : '' ?>>Male</option>
                            <option value="female" <?= (($_POST['gender'] ?? '') === 'female') ? 'selected' : '' ?>>Female</option>
                            <option value="other" <?= (($_POST['gender'] ?? '') === 'other') ? 'selected' : '' ?>>Other</option>

                        </select>

                    </div>

                    <div class="form-group">

                        <label for="date_of_birth">
                            Date of Birth
                        </label>

                        <input
                            type="date"
                            id="date_of_birth"
                            name="date_of_birth"
                            max="<?= date('Y-m-d') ?>"
                            value="<?= htmlspecialchars($_POST['date_of_birth'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        >

                    </div>

                    <div class="form-group">

                        <label for="phone">
                            Phone Number
                        </label>

                        <input
                            type="tel"
                            id="phone"
                            name="phone"
                            maxlength="30"
                            value="<?= htmlspecialchars($_POST['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="09XXXXXXXX"
                        >

                    </div>

                    <div class="form-group">

                        <label for="emergency_contact">
                            Emergency Contact
                        </label>

                        <input
                            type="tel"
                            id="emergency_contact"
                            name="emergency_contact"
                            maxlength="30"
                            value="<?= htmlspecialchars($_POST['emergency_contact'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="Emergency contact number"
                        >

                    </div>

                    <div class="form-group full">

                        <label for="address">
                            Address
                        </label>

                        <textarea
                            id="address"
                            name="address"
                            rows="3"
                            maxlength="255"
                            placeholder="Enter patient's address"
                        ><?= htmlspecialchars($_POST['address'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>

                    </div>

                </div>

                <div class="form-actions">

                    <button type="submit" class="register-button">
                        Register Patient
                    </button>

                    <a href="index.php" class="cancel-button">
                        Cancel
                    </a>

                </div>

            </form>

        </div>

    </section>

</main>

</body>
</html>
