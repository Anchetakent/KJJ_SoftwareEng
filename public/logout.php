<?php
require_once dirname(__DIR__) . '/app/includes/security.php';
start_secure_session();

session_unset();     // Remove all session variables
session_destroy();   // Destroy the session
header("Location: index.php"); // Redirect back to login page
exit();
?>
