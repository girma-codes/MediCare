
<?php

require_once __DIR__ . '/session.php';

// Check whether user is logged in
function requireLogin(): void {

    if (!isset($_SESSION['user_id'])) {

        header("Location: /medicare-hms/auth/login.php");
        exit;

    }
}

// Check user role permissions
function requireRole(array $allowedRoles): void {

    requireLogin();

    if (
        !isset($_SESSION['role']) ||
        !in_array($_SESSION['role'], $allowedRoles, true)
    ) {

        http_response_code(403);

        exit("403 Forbidden: You do not have permission.");

    }
}

?>
