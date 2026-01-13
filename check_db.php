<?php
$config = require 'config.php';
try {
    $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
    $pdo = new PDO($dsn, $config['username'], $config['password']);
    echo "Connected successfully. Checking tables...\n";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    print_r($tables);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
