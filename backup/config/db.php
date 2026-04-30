<?php
$host = "127.0.0.1";
$dbUsername = "root"; 
$dbPassword = "";     
$dbName = "class_record_db";

// Connecting using default port (3306)
$conn = new mysqli($host, $dbUsername, $dbPassword, $dbName);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>