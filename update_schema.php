<?php
$config = require 'config.php';
try {
    $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
    $pdo = new PDO($dsn, $config['username'], $config['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Adding custom_sections table...\n";
    $sql = "CREATE TABLE IF NOT EXISTS custom_sections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255),
        content TEXT,
        sort_order INT DEFAULT 0
    )";
    $pdo->exec($sql);
    echo "Done.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
