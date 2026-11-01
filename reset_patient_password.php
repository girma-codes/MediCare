<?php
require_once __DIR__ . '/config/database.php';

$email = 'girmamen@gmail.com';
$newPassword = '12121212';

$hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

$stmt = $pdo->prepare(
    "UPDATE users SET password = ? WHERE email = ? AND role = 'patient'"
);

$stmt->execute([$hashedPassword, $email]);

if ($stmt->rowCount() > 0) {
    echo "Patient password updated successfully!";
} else {
    echo "No account updated. Check the email and account.";
}
?>