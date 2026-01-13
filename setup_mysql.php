<?php
// setup_mysql.php
// This script migrates cv_data.json to MySQL

$config = require 'config.php';
$jsonFile = 'cv_data.json';

if (!file_exists($jsonFile)) {
    die("Error: cv_data.json not found.");
}

try {
    // 1. Connect to MySQL Server (without DB selected yet)
    $dsn = "mysql:host={$config['host']};charset={$config['charset']}";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    
    $pdo = new PDO($dsn, $config['username'], $config['password'], $options);
    
    // 2. Create Database
    echo "Creating database '{$config['dbname']}' if not exists...\n";
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$config['dbname']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$config['dbname']}`");

    // 3. Create Tables
    $commands = [
        "CREATE TABLE IF NOT EXISTS meta (
            `key` VARCHAR(255) PRIMARY KEY,
            `value` TEXT
        )",
        "CREATE TABLE IF NOT EXISTS skills (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category VARCHAR(255),
            content TEXT,
            sort_order INT DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS experience (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company VARCHAR(255),
            role VARCHAR(255),
            location VARCHAR(255),
            date_range VARCHAR(255),
            sort_order INT DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS experience_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            experience_id INT,
            item_type ENUM('bullet', 'project'), 
            project_name VARCHAR(255),
            content TEXT,
            sort_order INT DEFAULT 0,
            FOREIGN KEY (experience_id) REFERENCES experience(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS packages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) UNIQUE,
            description TEXT,
            url VARCHAR(255),
            sort_order INT DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS education (
            id INT AUTO_INCREMENT PRIMARY KEY,
            degree VARCHAR(255),
            institution VARCHAR(255),
            date_range VARCHAR(255),
            sort_order INT DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS certifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) UNIQUE,
            sort_order INT DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS languages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            language VARCHAR(255) UNIQUE,
            proficiency VARCHAR(255),
            sort_order INT DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS custom_sections (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255),
            content TEXT,
            sort_order INT DEFAULT 0
        )"
    ];

    foreach ($commands as $cmd) {
        $pdo->exec($cmd);
    }
    echo "Tables created.\n";

    // 4. Check if data exists, empty tables to re-import
    // (Optional: Only empty if you want a clean slate on every run)
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $tables = ['meta', 'skills', 'experience', 'experience_items', 'packages', 'education', 'certifications', 'languages', 'custom_sections'];
    foreach ($tables as $table) {
        $pdo->exec("TRUNCATE TABLE `$table`");
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    // 5. Import Data
    $jsonData = file_get_contents($jsonFile);
    $data = json_decode($jsonData, true);

    if (!$data) {
        die("Error decoding JSON.");
    }

    $pdo->beginTransaction();

    // Meta
    $stmt = $pdo->prepare("INSERT INTO meta (`key`, `value`) VALUES (:key, :value)");
    foreach ($data['header'] as $k => $v) {
        $stmt->execute([':key' => "header_$k", ':value' => $v]);
    }
    $stmt->execute([':key' => 'profile_summary', ':value' => $data['profile']]);
    if (isset($data['open_source_packages']['profile_link'])) {
         $stmt->execute([':key' => 'packagist_url', ':value' => $data['open_source_packages']['profile_link']]);
    }

    // Skills
    $stmt = $pdo->prepare("INSERT INTO skills (category, content, sort_order) VALUES (:cat, :content, :order)");
    $i = 0;
    foreach ($data['technical_skills'] as $cat => $val) {
        $stmt->execute([':cat' => $cat, ':content' => $val, ':order' => $i++]);
    }

    // Experience
    $stmtExp = $pdo->prepare("INSERT INTO experience (company, role, location, date_range, sort_order) VALUES (?, ?, ?, ?, ?)");
    $stmtItem = $pdo->prepare("INSERT INTO experience_items (experience_id, item_type, project_name, content, sort_order) VALUES (?, ?, ?, ?, ?)");
    
    $i = 0;
    foreach ($data['professional_experience'] as $job) {
        $stmtExp->execute([
            $job['company'], 
            $job['role'], 
            $job['location'] ?? '', 
            $job['date'], 
            $i++
        ]);
        $expId = $pdo->lastInsertId();

        if (!empty($job['points'])) {
            $j = 0;
            foreach ($job['points'] as $point) {
                $stmtItem->execute([$expId, 'bullet', null, $point, $j++]);
            }
        }

        if (!empty($job['projects'])) {
            $j = 100; 
            foreach ($job['projects'] as $proj) {
                foreach ($proj['points'] as $point) {
                    $stmtItem->execute([$expId, 'project', $proj['name'], $point, $j++]);
                }
            }
        }
    }

    // Packages
    if (!empty($data['open_source_packages']['packages'])) {
        $stmt = $pdo->prepare("INSERT INTO packages (name, description, sort_order) VALUES (?, ?, ?)");
        $i = 0;
        foreach ($data['open_source_packages']['packages'] as $pkg) {
            $stmt->execute([$pkg['name'], $pkg['description'], $i++]);
        }
    }

    // Education
    if (!empty($data['education'])) {
        $stmt = $pdo->prepare("INSERT INTO education (degree, institution, date_range, sort_order) VALUES (?, ?, ?, ?)");
        $i = 0;
        foreach ($data['education'] as $edu) {
            $stmt->execute([$edu['degree'], $edu['institution'], $edu['date'], $i++]);
        }
    }

    // Certifications
    if (!empty($data['certifications'])) {
        $stmt = $pdo->prepare("INSERT INTO certifications (name, sort_order) VALUES (?, ?)");
        $i = 0;
        foreach ($data['certifications'] as $cert) {
            $stmt->execute([$cert, $i++]);
        }
    }

    // Languages
    if (!empty($data['languages'])) {
        $stmt = $pdo->prepare("INSERT INTO languages (language, proficiency, sort_order) VALUES (?, ?, ?)");
        $i = 0;
        foreach ($data['languages'] as $lang => $prof) {
            $stmt->execute([$lang, $prof, $i++]);
        }
    }

    $pdo->commit();
    echo "Data imported successfully into MySQL database '{$config['dbname']}'.";

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("DB Error: " . $e->getMessage());
}
?>
