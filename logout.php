<?php

require_once __DIR__ . '/includes/session.php';

// Clear all session data
$_SESSION = [];

// Destroy current session
session_destroy();

// Redirect to login page
header("Location: /medicare-hms/auth/login.php");
exit;

?>
