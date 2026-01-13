<?php
// setup_db.php
// This script converts the cv_data.json into a SQLite database (cv.db)

$jsonFile = 'cv_data.json';
$dbFile = 'cv.db';

if (!file_exists($jsonFile)) {
    die("Error: cv_data.json not found. Please create it first.");
}

// Delete existing DB to start fresh
if (file_exists($dbFile)) {
    unlink($dbFile);
}

try {
    $pdo = new PDO("sqlite:$dbFile");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // --- Create Tables ---
    $commands = [
        "CREATE TABLE IF NOT EXISTS meta (
            key TEXT PRIMARY KEY,
            value TEXT
        )",
        "CREATE TABLE IF NOT EXISTS skills (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category TEXT,
            content TEXT,
            sort_order INTEGER DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS experience (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company TEXT,
            role TEXT,
            location TEXT,
            date_range TEXT,
            sort_order INTEGER DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS experience_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            experience_id INTEGER,
            item_type TEXT CHECK(item_type IN ('bullet', 'project')), 
            project_name TEXT,
            content TEXT,
            sort_order INTEGER DEFAULT 0,
            FOREIGN KEY(experience_id) REFERENCES experience(id)
        )",
        "CREATE TABLE IF NOT EXISTS packages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            description TEXT,
            url TEXT,
            sort_order INTEGER DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS education (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            degree TEXT,
            institution TEXT,
            date_range TEXT,
            sort_order INTEGER DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS certifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            sort_order INTEGER DEFAULT 0
        )",
        "CREATE TABLE IF NOT EXISTS languages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            language TEXT,
            proficiency TEXT,
            sort_order INTEGER DEFAULT 0
        )"
    ];

    foreach ($commands as $cmd) {
        $pdo->exec($cmd);
    }
    
    echo "Database tables created.\n";

    // --- Import Data ---
    $jsonData = file_get_contents($jsonFile);
    $data = json_decode($jsonData, true);

    if (!$data) {
        die("Error decoding JSON.");
    }

    $pdo->beginTransaction();

    // 1. Meta (Header & Profile)
    $stmt = $pdo->prepare("INSERT INTO meta (key, value) VALUES (:key, :value)");
    foreach ($data['header'] as $k => $v) {
        $stmt->execute([':key' => "header_$k", ':value' => $v]);
    }
    $stmt->execute([':key' => 'profile_summary', ':value' => $data['profile']]);
    if (isset($data['open_source_packages']['profile_link'])) {
         $stmt->execute([':key' => 'packagist_url', ':value' => $data['open_source_packages']['profile_link']]);
    }

    // 2. Skills
    $stmt = $pdo->prepare("INSERT INTO skills (category, content, sort_order) VALUES (:cat, :content, :order)");
    $i = 0;
    foreach ($data['technical_skills'] as $cat => $val) {
        $stmt->execute([':cat' => $cat, ':content' => $val, ':order' => $i++]);
    }

    // 3. Experience
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

        // General points
        if (!empty($job['points'])) {
            $j = 0;
            foreach ($job['points'] as $point) {
                $stmtItem->execute([$expId, 'bullet', null, $point, $j++]);
            }
        }

        // Projects
        if (!empty($job['projects'])) {
            $j = 100; // Start projects after bullets
            foreach ($job['projects'] as $proj) {
                foreach ($proj['points'] as $point) {
                    $stmtItem->execute([$expId, 'project', $proj['name'], $point, $j++]);
                }
            }
        }
    }

    // 4. Packages
    if (!empty($data['open_source_packages']['packages'])) {
        $stmt = $pdo->prepare("INSERT INTO packages (name, description, sort_order) VALUES (?, ?, ?)");
        $i = 0;
        foreach ($data['open_source_packages']['packages'] as $pkg) {
            $stmt->execute([$pkg['name'], $pkg['description'], $i++]);
        }
    }

    // 5. Education
    if (!empty($data['education'])) {
        $stmt = $pdo->prepare("INSERT INTO education (degree, institution, date_range, sort_order) VALUES (?, ?, ?, ?)");
        $i = 0;
        foreach ($data['education'] as $edu) {
            $stmt->execute([$edu['degree'], $edu['institution'], $edu['date'], $i++]);
        }
    }

    // 6. Certifications
    if (!empty($data['certifications'])) {
        $stmt = $pdo->prepare("INSERT INTO certifications (name, sort_order) VALUES (?, ?)");
        $i = 0;
        foreach ($data['certifications'] as $cert) {
            $stmt->execute([$cert, $i++]);
        }
    }

    // 7. Languages
    if (!empty($data['languages'])) {
        $stmt = $pdo->prepare("INSERT INTO languages (language, proficiency, sort_order) VALUES (?, ?, ?)");
        $i = 0;
        foreach ($data['languages'] as $lang => $prof) {
            $stmt->execute([$lang, $prof, $i++]);
        }
    }

    $pdo->commit();
    echo "Data imported successfully into cv.db";

} catch (PDOException $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("DB Error: " . $e->getMessage());
}
?>
