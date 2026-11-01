
<?php

require_once __DIR__ . '/config/database.php';

// እዚህ የራስህን አዲስ Password አስገባ
$newPassword = '12121212!';

if ($newPassword === 'ChangeThisToYourOwnStrongPassword123!') {
    exit('Please set your own password in the PHP file first.');
}

$hash = password_hash($newPassword, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("
    UPDATE users
    SET password = :password
    WHERE email = :email
      AND role = 'admin'
");

$stmt->execute([
    'password' => $hash,
    'email' => 'girmamengex@gmail.com'
]);

if ($stmt->rowCount() > 0) {
    echo 'Admin password updated successfully!';
} else {
    echo 'No password change was made. Check the account or password.';
}

?>
