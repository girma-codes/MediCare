
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

// Verify CSRF token
$token = $_POST['csrf_token'] ?? '';

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $token)
) {
    http_response_code(403);
    exit("Invalid security token. Please try again.");
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    header("Location: index.php?error=invalid");
    exit;
}

try {
    $pdo->beginTransaction();

    // Check whether doctors are assigned to this department
    $check = $pdo->prepare("
        SELECT COUNT(*)
        FROM doctors
        WHERE department_id = :id
    ");
    $check->execute(['id' => $id]);

    $doctorCount = (int) $check->fetchColumn();

    if ($doctorCount > 0) {
        $pdo->rollBack();
        header("Location: index.php?error=assigned");
        exit;
    }

    // Check whether department exists
    $delete = $pdo->prepare("
        DELETE FROM departments
        WHERE id = :id
    ");
    $delete->execute(['id' => $id]);

    if ($delete->rowCount() === 0) {
        $pdo->rollBack();
        header("Location: index.php?error=notfound");
        exit;
    }

    $pdo->commit();

    header("Location: index.php?success=deleted");
    exit;

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($e->getMessage());

    header("Location: index.php?error=delete_failed");
    exit;
}
