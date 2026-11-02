
<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/auth.php';

// Admin only
requireRole(['admin']);

$success = '';
$error = '';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $error = 'Invalid request. Refresh the page.';
    } else {
        $technicianPassword = $_POST['technician_password'] ?? '';
        $doctorPassword = $_POST['doctor_password'] ?? '';

        if (strlen($technicianPassword) < 8 || strlen($doctorPassword) < 8) {
            $error = 'Both passwords must contain at least 8 characters.';
        } else {
            try {
                $pdo->beginTransaction();

                $accounts = [
                    [
                        'email' => 'labtechnician@medicare.com',
                        'password' => $technicianPassword
                    ],
                    [
                        'email' => 'labdoctor@medicare.com',
                        'password' => $doctorPassword
                    ]
                ];

                $stmt = $pdo->prepare(
                    "UPDATE users
                     SET password = :password, status = 'active'
                     WHERE email = :email
                       AND role = :role"
                );

                foreach ($accounts as $account) {
                    $role = $account['email'] === 'labtechnician@medicare.com'
                        ? 'lab_technician'
                        : 'lab_doctor';

                    $stmt->execute([
                        'password' => password_hash(
                            $account['password'],
                            PASSWORD_DEFAULT
                        ),
                        'email' => $account['email'],
                        'role' => $role
                    ]);

                    if ($stmt->rowCount() !== 1) {
                        throw new RuntimeException(
                            'Could not update account: ' . $account['email']
                        );
                    }
                }

                $pdo->commit();
                $success = 'Both laboratory accounts are now active.';

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Account setup failed. Check that both accounts exist.';
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
    <title>Setup Laboratory Accounts</title>
</head>
<body>
    <h2>Laboratory Account Setup</h2>

    <?php if ($success): ?>
        <p style="color:green;">
            <?= htmlspecialchars($success) ?>
        </p>
    <?php endif; ?>

    <?php if ($error): ?>
        <p style="color:red;">
            <?= htmlspecialchars($error) ?>
        </p>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token"
               value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

        <label>Technician Password (minimum 8 characters)</label><br>
        <input type="password" name="technician_password"
               minlength="8" required><br><br>

        <label>Laboratory Doctor Password (minimum 8 characters)</label><br>
        <input type="password" name="doctor_password"
               minlength="8" required><br><br>

        <button type="submit">Activate Laboratory Accounts</button>
    </form>
</body>
</html>
