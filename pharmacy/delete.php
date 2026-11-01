
<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole(['admin']);

// Only POST requests are allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

// Verify CSRF token
if (
    empty($_SESSION['csrf_token']) ||
    empty($_POST['csrf_token']) ||
    !hash_equals(
        $_SESSION['csrf_token'],
        $_POST['csrf_token']
    )
) {
    http_response_code(403);
    exit("Invalid security token. Please go back and try again.");
}

// Validate medicine ID
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    header("Location: index.php?error=invalid");
    exit;
}

try {

    $stmt = $pdo->prepare(
        "DELETE FROM medicines WHERE id = :id"
    );

    $stmt->execute([
        ':id' => $id
    ]);

    if ($stmt->rowCount() > 0) {

        header("Location: index.php?deleted=1");

    } else {

        header("Location: index.php?error=notfound");

    }

    exit;

} catch (PDOException $e) {

    error_log($e->getMessage());

    header("Location: index.php?error=delete");
    exit;

}
