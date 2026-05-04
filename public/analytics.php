<?php
require_once dirname(__DIR__) . '/app/includes/security.php';
start_secure_session();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

if ($_SESSION['role'] !== 'Faculty') {
    header('Location: dashboard.php');
    exit;
}

$redirect = 'dashboard.php';
if (!empty($_GET['section'])) {
    $redirect .= '?section=' . urlencode($_GET['section']) . '&tab=Analytics';
}

header('Location: ' . $redirect);
exit;
