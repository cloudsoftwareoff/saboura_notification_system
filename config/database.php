<?php
function getDatabaseConnection() {
    $host = 'localhost';
    $dbname = 'saboura_notif';
    $username = 'root';
    $password = '';
    
    try {
        $db = new PDO(
            "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        return $db;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        die("Database connection failed");
    }
}

function getCurrentUserId() {
  
    session_start();
    return $_SESSION['user_id'] ?? 1;
}