
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';

// All user dashboards
$dashboards = [
    'admin' => '../admin/dashboard.php',
    'doctor' => '../doctor/dashboard.php',
    'receptionist' => '../receptionist/dashboard.php',
    'pharmacist' => '../pharmacy/dashboard.php',
    'cashier' => '../cashier/dashboard.php',
    'patient' => '../patient/dashboard.php',

    // Laboratory roles
    'lab_technician' => '../laboratory/technician/dashboard.php',
    'lab_doctor' => '../laboratory/doctor/dashboard.php'
];

// Redirect already logged-in users
if (isset($_SESSION['user_id'], $_SESSION['role'])) {

    $role = $_SESSION['role'];

    if (isset($dashboards[$role])) {
        header('Location: ' . $dashboards[$role]);
        exit;
    }
}

$error = '';

// Process login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF validation
    if (
        !isset($_POST['csrf_token'], $_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $error = 'Invalid request. Please refresh the page.';

    } else {

        $email = filter_var(
            trim($_POST['email'] ?? ''),
            FILTER_VALIDATE_EMAIL
        );

        $password = $_POST['password'] ?? '';

        if (!$email || $password === '') {

            $error = 'Please enter valid login details.';

        } else {

            $stmt = $pdo->prepare(
                "SELECT id, full_name, email, password, role, status
                 FROM users
                 WHERE email = :email
                 LIMIT 1"
            );

            $stmt->execute(['email' => $email]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (
                $user &&
                $user['status'] === 'active' &&
                password_verify($password, $user['password'])
            ) {

                $role = $user['role'];

                if (!isset($dashboards[$role])) {

                    $error = 'Dashboard not configured for this account.';

                } else {

                    // Regenerate session ID
                    session_regenerate_id(true);

                    // Store user information
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $role;

                    // Regenerate CSRF token
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                    // Redirect to dashboard
                    header('Location: ' . $dashboards[$role]);
                    exit;
                }

            } else {

                $error = 'Invalid email or password.';

            }
        }
    }
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>MediCare HMS | Login</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f1f5f9;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            width: 100%;
            max-width: 420px;
            background: white;
            padding: 35px;
            border-radius: 15px;
            box-shadow: 0 10px 35px rgba(0,0,0,0.08);
        }

        h1 {
            text-align: center;
            color: #0f172a;
            margin-bottom: 10px;
        }

        .subtitle {
            text-align: center;
            color: #64748b;
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #334155;
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 13px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 15px;
        }

        input:focus {
            outline: none;
            border-color: #2563eb;
        }

        button {
            width: 100%;
            padding: 14px;
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #1d4ed8;
        }

        .error {
            background: #fee2e2;
            color: #b91c1c;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .footer {
            text-align: center;
            margin-top: 25px;
            color: #94a3b8;
            font-size: 13px;
        }
    </style>
</head>

<body>

<div class="login-container">

    <h1>MediCare HMS</h1>

    <p class="subtitle">Hospital Management System</p>

    <?php if ($error): ?>
        <div class="error">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
        >

        <div class="form-group">

            <label for="email">Email Address</label>

            <input
                type="email"
                id="email"
                name="email"
                placeholder="Enter your email"
                required
            >

        </div>

        <div class="form-group">

            <label for="password">Password</label>

            <input
                type="password"
                id="password"
                name="password"
                placeholder="Enter your password"
                required
            >

        </div>

        <button type="submit">Login</button>

    </form>

    <div class="footer">
        © 2026 MediCare Hospital Management System
    </div>

</div>

</body>
</html>
