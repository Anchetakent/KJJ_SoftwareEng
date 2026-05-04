<?php
// config/db.php
require_once dirname(__DIR__) . '/app/includes/env.php';

$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$dbUsername = $_ENV['DB_USER'] ?? 'root';
$dbPassword = $_ENV['DB_PASS'] ?? '';
$dbName = $_ENV['DB_NAME'] ?? 'class_record_db';

$conn = new mysqli($host, $dbUsername, $dbPassword, $dbName);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>