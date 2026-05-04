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
// Also provide a PDO instance for prepared statements (used in new admin features)
try {
    $dsn = "mysql:host={$host};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUsername, $dbPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    // If PDO fails, keep mysqli available but warn in logs
    error_log('PDO connection failed: ' . $e->getMessage());
    $pdo = null;
}
?>