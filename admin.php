<?php
session_start();
$config = require 'config.php';
$pdo = new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}", $config['username'], $config['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$message = '';
$error = '';

// Check for flash messages
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// --- HANDLING JSON EXPORT (Must be before any HTML output) ---
if (isset($_GET['action']) && $_GET['action'] === 'export_json') {
    $tables = ['meta', 'skills', 'experience', 'experience_items', 'packages', 'education', 'certifications', 'languages', 'custom_sections', 'header_extras'];
    $data = [];
    foreach ($tables as $t) {
        $stmt = $pdo->query("SELECT * FROM `$t`");
        $data[$t] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="cv_data_backup_' . date('Y-m-d') . '.json"');
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

// --- POST HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        $msg = '';

        if ($action === 'update_meta') {
            $pdo->beginTransaction();
            foreach ($_POST['meta'] as $key => $value) {
                if ($key === 'header_linkedin') {
                     // Strip protocol if user pasted full URL
                     $value = preg_replace('#^https?://#', '', $value);
                }
                $stmt = $pdo->prepare("INSERT INTO meta (`key`, `value`) VALUES (:key, :value) ON DUPLICATE KEY UPDATE `value` = :value");
                $stmt->execute([':key' => $key, ':value' => $value]);
            }
            $pdo->commit();
            $msg = "Header & Meta updated successfully.";
        }
        elseif ($action === 'save_item') {
            $table = $_POST['table'];
            $id = $_POST['id'] ?? '';
            $fields = $_POST['fields'] ?? []; 
            
            $allowed = ['skills', 'education', 'certifications', 'languages', 'custom_sections', 'experience', 'packages', 'header_extras'];
            if (!in_array($table, $allowed)) throw new Exception("Invalid table");

            if ($id) {
                $set = [];
                $params = [];
                foreach ($fields as $col => $val) {
                    $set[] = "`$col` = ?";
                    $params[] = $val;
                }
                $params[] = $id;
                $sql = "UPDATE `$table` SET " . implode(', ', $set) . " WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            } else {
                $cols = array_keys($fields);
                $placeholders = array_fill(0, count($cols), '?');
                $sql = "INSERT INTO `$table` (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_values($fields));
            }
            $msg = "Item saved.";
        }
        elseif ($action === 'delete_item') {
            $table = $_POST['table'];
            $id = $_POST['id'];
            $allowed = ['skills', 'education', 'certifications', 'languages', 'custom_sections', 'experience', 'experience_items', 'packages', 'header_extras'];
            if (in_array($table, $allowed)) {
                $pdo->prepare("DELETE FROM `$table` WHERE id = ?")->execute([$id]);
                $msg = "Item deleted.";
            }
        }
        elseif ($action === 'save_exp_item') {
            $itemId = $_POST['item_id'] ?? '';
            $expId = $_POST['experience_id'];
            $type = $_POST['type'];
            $content = $_POST['content'];
            $sysType = ($type == 'project') ? 'project' : 'bullet';
            $pName = ($type == 'project') ? $_POST['project_name'] : null;

            if ($itemId) {
                $stmt = $pdo->prepare("UPDATE experience_items SET content=?, item_type=?, project_name=? WHERE id=?");
                $stmt->execute([$content, $sysType, $pName, $itemId]);
            } else {
                $stmtOrder = $pdo->prepare("SELECT MAX(sort_order) FROM experience_items WHERE experience_id=?");
                $stmtOrder->execute([$expId]);
                $max = $stmtOrder->fetchColumn(); 
                $next = ($max === false) ? 0 : $max + 1;
                $stmt = $pdo->prepare("INSERT INTO experience_items (experience_id, item_type, project_name, content, sort_order) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$expId, $sysType, $pName, $content, $next]);
            }
            $msg = "Experience point saved.";
        }
        elseif ($action === 'save_order') {
            $table = $_POST['table'];
            $orderList = explode(',', $_POST['order']);
            if ($table === 'sections') {
                $json = json_encode($orderList);
                $pdo->prepare("INSERT INTO meta (`key`, `value`) VALUES ('section_order', ?) ON DUPLICATE KEY UPDATE `value`=?")->execute([$json, $json]);
                $msg = "Section layout saved.";
            } else {
                $allowed = ['skills', 'education', 'certifications', 'languages', 'custom_sections', 'experience', 'experience_items', 'packages', 'header_extras'];
                if (in_array($table, $allowed)) {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("UPDATE `$table` SET sort_order = ? WHERE id = ?");
                    foreach ($orderList as $index => $id) {
                        $stmt->execute([$index, $id]);
                    }
                    $pdo->commit();
                    $msg = "Order updated.";
                }
            }
        }
        elseif ($action === 'import_json') {
            if (!isset($_FILES['json_file']) || $_FILES['json_file']['error'] != 0) throw new Exception("File upload failed.");
            $jsonContent = file_get_contents($_FILES['json_file']['tmp_name']);
            $data = json_decode($jsonContent, true);
            if (!$data) throw new Exception("Invalid JSON file.");
            $mode = $_POST['import_mode'];
            $pdo->beginTransaction();
            $tables = ['meta', 'skills', 'experience', 'experience_items', 'packages', 'education', 'certifications', 'languages', 'custom_sections', 'header_extras'];
            if ($mode === 'replace') {
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                foreach ($tables as $t) $pdo->exec("DELETE FROM `$t`");
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                foreach ($data as $table => $rows) {
                    if (!in_array($table, $tables) || empty($rows)) continue;
                    $cols = array_keys($rows[0]);
                    $placeholders = array_fill(0, count($cols), '?');
                    $sql = "INSERT INTO `$table` (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")";
                    $stmt = $pdo->prepare($sql);
                    foreach ($rows as $row) $stmt->execute(array_values($row));
                }
                $msg = "Database replaced with imported data.";
            } elseif ($mode === 'update') {
                 foreach ($data as $table => $rows) {
                    if (!in_array($table, $tables) || empty($rows)) continue;
                    if ($table === 'meta') {
                         $stmt = $pdo->prepare("INSERT INTO meta (`key`, `value`) VALUES (:key, :value) ON DUPLICATE KEY UPDATE `value` = :value");
                         foreach ($rows as $row) $stmt->execute([':key' => $row['key'], ':value' => $row['value']]);
                    } else {
                        $cols = array_keys($rows[0]);
                        $updateParts = [];
                        foreach ($cols as $c) if ($c != 'id') $updateParts[] = "`$c` = VALUES(`$c`)";
                        $sql = "INSERT INTO `$table` (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ") ON DUPLICATE KEY UPDATE " . implode(', ', $updateParts);
                        $stmt = $pdo->prepare($sql);
                        foreach ($rows as $row) $stmt->execute(array_values($row));
                    }
                 }
                 $msg = "Data merged/updated.";
            }
            $pdo->commit();
        }

        if ($msg) $_SESSION['flash_message'] = $msg;
        
        // Post-Redirect-Get
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash_error'] = $e->getMessage();
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// --- DATA FETCHING ---
function getTable($pdo, $table, $orderBy='sort_order ASC') {
    try { return $pdo->query("SELECT * FROM `$table` ORDER BY $orderBy")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) { return []; } 
}
function getMeta($pdo) {
    $meta = [];
    foreach($pdo->query("SELECT * FROM meta") as $row) $meta[$row['key']] = $row['value'];
    return $meta;
}

$tab = $_GET['tab'] ?? 'meta';
$meta = getMeta($pdo);

// Section Order Logic
$defaultSections = [
    ['id' => 'profile', 'title' => 'Profile Summary'],
    ['id' => 'skills', 'title' => 'Technical Skills'],
    ['id' => 'experience', 'title' => 'Professional Experience'],
    ['id' => 'packages', 'title' => 'Projects'],
    ['id' => 'education', 'title' => 'Education'],
    ['id' => 'certifications', 'title' => 'Certifications'],
    ['id' => 'languages', 'title' => 'Languages']
];
$customSections = getTable($pdo, 'custom_sections');
foreach($customSections as $cs) $defaultSections[] = ['id' => 'custom_' . $cs['id'], 'title' => $cs['title'] . ' (Custom)'];

$currentOrder = [];
if (!empty($meta['section_order'])) {
    $savedIds = json_decode($meta['section_order'], true);
    foreach ($savedIds as $sid) {
        foreach ($defaultSections as $ds) { if ($ds['id'] == $sid) { $currentOrder[] = $ds; break; } }
    }
    foreach ($defaultSections as $ds) {
        $found = false;
        foreach ($currentOrder as $co) { if ($co['id'] == $ds['id']) { $found = true; break;} }
        if (!$found) $currentOrder[] = $ds;
    }
} else {
    $currentOrder = $defaultSections;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CV Manager</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>
    <style>
        :root {
            /* Palette: Slate/Blue */
            --primary: #3b82f6; /* Blue 500 */
            --primary-hover: #2563eb; /* Blue 600 */
            --primary-light: #eff6ff;
            --secondary: #64748b; /* Slate 500 */
            --bg-body: #f3f4f6; /* Gray 100 */
            --bg-sidebar: #1e293b; /* Slate 800 */
            --bg-surface: #ffffff;
            --border-color: #e5e7eb; /* Gray 200 */
            --text-main: #111827; /* Gray 900 */
            --text-muted: #6b7280; /* Gray 500 */
            --danger: #ef4444; /* Red 500 */
            --sidebar-width: 280px;
        }

        /* Basic Reset & Typography */
        * { box-sizing: border-box; outline: none; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            margin: 0;
            display: flex;
            height: 100vh;
            overflow: hidden;
            font-size: 14px;
        }
        h1, h2, h3, h4 { margin: 0; font-weight: 600; }
        a { text-decoration: none; color: inherit; transition: 0.2s; }
        ul { list-style: none; padding: 0; margin: 0; }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            background-color: var(--bg-sidebar);
            color: #e2e8f0;
            display: flex;
            flex-direction: column;
            border-right: 1px solid #0f172a;
            z-index: 50;
        }
        .sidebar-header {
            padding: 24px;
            border-bottom: 1px solid #334155;
            background: rgba(0,0,0,0.1);
        }
        .sidebar-header h2 { font-size: 1.1rem; letter-spacing: 0.5px; color: #fff; }
        
        .nav { flex: 1; padding-top: 10px; overflow-y: auto; }
        .nav a {
            display: flex;
            align-items: center;
            padding: 12px 24px;
            font-weight: 500;
            border-left: 3px solid transparent;
        }
        .nav a:hover { background-color: rgba(255,255,255,0.05); color: #fff; }
        .nav a.active {
            background-color: rgba(255,255,255,0.1);
            color: #fff;
            border-left-color: var(--primary);
        }
        
        .nav-footer {
            padding: 20px;
            border-top: 1px solid #334155;
            background: rgba(0,0,0,0.2);
        }

        /* Main Content */
        .main {
            flex: 1;
            padding: 40px;
            overflow-y: auto;
            position: relative;
            transition: margin-right 0.3s ease;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        .header h1 { font-size: 1.75rem; color: var(--text-main); }
        
        /* Cards */
        .card {
            background: var(--bg-surface);
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid var(--border-color);
        }
        .card h3 {
            font-size: 1.1rem;
            color: var(--text-main);
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-color);
        }

        /* Forms */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 16px;
        }
        .form-group { margin-bottom: 20px; }
        label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-muted);
            font-weight: 500;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }
        input[type="text"], input[type="email"], textarea, select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            background: #fff;
            border-radius: 6px;
            font-size: 0.95rem;
            font-family: inherit;
            transition: all 0.2s;
            color: var(--text-main);
        }
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
        }
        textarea { resize: vertical; min-height: 80px; }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            font-size: 0.9rem;
            gap: 8px;
        }
        .btn-primary {
            background-color: var(--primary);
            color: #fff;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }
        .btn-primary:hover { background-color: var(--primary-hover); transform: translateY(-1px); }
        .btn-secondary {
            background-color: #fff;
            color: var(--text-main);
            border: 1px solid var(--border-color);
        }
        .btn-secondary:hover { background-color: #f9fafb; border-color: #d1d5db; }
        .btn-danger {
            background-color: transparent;
            color: var(--danger);
            padding: 8px; /* Icon only typically */
            border: 1px solid transparent;
        }
        .btn-danger:hover { background-color: #fef2f2; border-color: #fee2e2; }
        
        .btn-view-site { 
            background: var(--primary); 
            color: #fff; 
            border: none;
            margin-top: 0;
            display: block; 
            width: 100%;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            padding: 12px;
            text-align: center;
            border-radius: 8px;
            font-weight: 600;
        }
        .btn-view-site:hover { background: var(--primary-hover); transform: translateY(-1px); }
        
        .btn-toggle-preview { 
            background: transparent; 
            border: 1px solid #475569; 
            color: #cbd5e1; 
            margin-top: 12px;
            display: block; 
            width: 100%;
            padding: 12px;
            text-align: center;
            border-radius: 8px;
            font-weight: 600;
        }
        .btn-toggle-preview:hover { border-color: #fff; color: #fff; background: rgba(255,255,255,0.05); }

        /* Custom File Input */
        .file-upload-wrapper {
            position: relative;
            margin-bottom: 1rem;
        }
        .file-upload-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 30px;
            border: 2px dashed var(--border-color);
            border-radius: 8px;
            background: #fafafa;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
        }
        .file-upload-label:hover {
            border-color: var(--primary);
            background: var(--primary-light);
            color: var(--primary);
        }
        .file-upload-label strong { font-size: 1rem; display: block; margin-bottom: 4px; }
        .file-upload-label span { font-size: 0.85rem; opacity: 0.8; }
        input[type="file"] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        /* Input Group */
        .input-group {
            display: flex;
            align-items: center;
        }
        .input-group-addon {
            background: #f1f5f9;
            border: 1px solid var(--border-color);
            border-right: none;
            padding: 10px 12px;
            color: var(--text-muted);
            border-top-left-radius: 6px;
            border-bottom-left-radius: 6px;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            font-weight: 500;
        }
        .input-group input {
            border-top-left-radius: 0 !important;
            border-bottom-left-radius: 0 !important;
            flex: 1;
        }

        /* Items List */
        .data-item {
            display: flex;
            align-items: center; /* Center vertically */
            background: #fff;
            border: 1px solid var(--border-color);
            margin-bottom: -1px; /* collapse borders */
            padding: 16px;
        }
        .data-item:first-child { border-top-left-radius: 8px; border-top-right-radius: 8px; }
        .data-item:last-child { border-bottom-left-radius: 8px; border-bottom-right-radius: 8px; margin-bottom: 0; }
        
        .drag-handle {
            color: #cbd5e1;
            margin-right: 16px;
            cursor: grab;
            font-size: 1.2rem;
        }
        .drag-handle:hover { color: #64748b; }
        .data-content { flex: 1; margin-right: 16px; }
        
        /* Alerts */
        .alert {
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-weight: 500;
            display: flex; align-items: center; gap: 10px;
        }
        .alert-success { background-color: #effdf5; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background-color: #fef2f2; color: #991b1b; border: 1px solid #fee2e2; }

        /* Preview Panel */
        .preview-panel {
            position: fixed;
            top: 0;
            right: -55%;
            width: 55%;
            height: 100vh;
            background: #cbd5e1;
            border-left: 1px solid #94a3b8;
            box-shadow: -10px 0 25px rgba(0,0,0,0.15);
            transition: right 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            z-index: 200;
            display: flex;
            flex-direction: column;
        }
        .preview-panel.active { right: 0; }
        .preview-header {
            padding: 16px 24px;
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .preview-content {
            flex: 1;
            background: #64748b;
            padding: 40px;
            overflow: auto;
            display: flex;
            justify-content: center;
        }
        #cvFrame {
            width: 210mm;
            min-height: 297mm;
            background: #fff;
            box-shadow: 0 0 20px rgba(0,0,0,0.3);
            border: none;
        }
        body.preview-active .main { margin-right: 55%; }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>CV Builder</h2>
        </div>
        <nav class="nav">
            <a href="?tab=meta" class="<?= $tab=='meta'?'active':'' ?>">📝 Header & Meta</a>
            <a href="?tab=experience" class="<?= $tab=='experience'?'active':'' ?>">💼 Experience</a>
            <a href="?tab=skills" class="<?= $tab=='skills'?'active':'' ?>">🛠 Skills</a>
            <a href="?tab=education" class="<?= $tab=='education'?'active':'' ?>">🎓 Education & Certs</a>
            <a href="?tab=packages" class="<?= $tab=='packages'?'active':'' ?>">📦 Projects</a>
            <a href="?tab=languages" class="<?= $tab=='languages'?'active':'' ?>">🌐 Languages</a>
            <a href="?tab=section_order" class="<?= $tab=='section_order'?'active':'' ?>">🔃 Layout & Sections</a>
            <a href="?tab=import_export" class="<?= $tab=='import_export'?'active':'' ?>">💾 Import / Export</a>
        </nav>
        <div class="nav-footer">
            <a href="index.php" target="_blank" class="btn-view-site">View Site ↗</a>
            <button class="btn-toggle-preview" onclick="togglePreview()">👁 Toggle Preview</button>
        </div>
    </div>
    
    <div class="main">
        <?php if($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <?php if($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <?php if ($tab === 'meta'): ?>
            <div class="header"><h1>Header & Profile</h1></div>
            <div class="card">
                <h3>Main Details</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="update_meta">
                    <div class="form-row">
                        <div class="form-group"><label>Name</label><input type="text" name="meta[header_name]" value="<?= htmlspecialchars($meta['header_name']??'') ?>"></div>
                        <div class="form-group"><label>Current Role</label><input type="text" name="meta[header_role]" value="<?= htmlspecialchars($meta['header_role']??'') ?>"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Location</label><input type="text" name="meta[header_location]" value="<?= htmlspecialchars($meta['header_location']??'') ?>"></div>
                        <div class="form-group"><label>Phone</label><input type="text" name="meta[header_phone]" value="<?= htmlspecialchars($meta['header_phone']??'') ?>"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Email</label><input type="email" name="meta[header_email]" value="<?= htmlspecialchars($meta['header_email']??'') ?>"></div>
                        <div class="form-group">
                            <label>LinkedIn</label>
                            <div class="input-group">
                                <div class="input-group-addon">https://</div>
                                <input type="text" name="meta[header_linkedin]" value="<?= htmlspecialchars($meta['header_linkedin']??'') ?>" placeholder="linkedin.com/in/username">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Profile Summary</label>
                        <textarea name="meta[profile_summary]" rows="6"><?= htmlspecialchars($meta['profile_summary']??'') ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </form>
            </div>

            <div class="card">
                 <h3>Custom Header Fields</h3>
                 <ul class="data-list" id="header-extras-list">
                    <?php foreach(getTable($pdo, 'header_extras') as $row): ?>
                    <li class="data-item" data-id="<?= $row['id'] ?>">
                        <div class="drag-handle">☰</div>
                        <div class="data-content">
                            <form method="POST" class="form-row" style="margin-bottom:0;">
                                <input type="hidden" name="action" value="save_item">
                                <input type="hidden" name="table" value="header_extras">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <input type="text" name="fields[label]" value="<?= htmlspecialchars($row['label']) ?>" placeholder="Label (e.g. Portfolio)" style="width:100%;">
                                <input type="text" name="fields[value]" value="<?= htmlspecialchars($row['value']) ?>" placeholder="Display Text" style="width:100%;">
                                <input type="text" name="fields[url]" value="<?= htmlspecialchars($row['url']) ?>" placeholder="URL (Optional)" style="width:100%;">
                                <button type="submit" class="btn btn-primary" style="width:auto;">Save</button>
                            </form>
                        </div>
                        <div class="data-actions">
                             <form method="POST" onsubmit="return confirm('Delete?');">
                                <input type="hidden" name="action" value="delete_item">
                                <input type="hidden" name="table" value="header_extras">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <button type="submit" class="btn btn-danger">×</button>
                            </form>
                        </div>
                    </li>
                    <?php endforeach; ?>
                 </ul>
                 <div style="padding:16px;">
                    <form method="POST" class="form-row">
                        <input type="hidden" name="action" value="save_item">
                        <input type="hidden" name="table" value="header_extras">
                        <input type="text" name="fields[label]" placeholder="Label (e.g. Website)">
                        <input type="text" name="fields[value]" placeholder="Text (e.g. mysite.com)">
                        <input type="text" name="fields[url]" placeholder="URL (Optional)">
                        <button type="submit" class="btn btn-primary">Add Field</button>
                    </form>
                </div>
            </div>
            
             <script>
                new Sortable(document.getElementById('header-extras-list'), { handle: '.drag-handle', animation: 150, onEnd: function (evt) {
                    var ids = []; document.querySelectorAll('#header-extras-list .data-item').forEach(el => ids.push(el.getAttribute('data-id')));
                    saveOrder('header_extras', ids);
                }});
            </script>

        <?php elseif ($tab === 'skills'): ?>
            <div class="header"><h1>Technical Skills</h1></div>
            <div class="card">
                <h3>Manage Skills</h3>
                <ul class="data-list" id="skills-list">
                    <?php 
                    $skills = getTable($pdo, 'skills');
                    foreach($skills as $row): 
                    ?>
                    <li class="data-item" data-id="<?= $row['id'] ?>">
                        <div class="drag-handle">☰</div>
                        <div class="data-content">
                            <form method="POST" style="display:flex; gap:10px; width:100%;">
                                <input type="hidden" name="action" value="save_item">
                                <input type="hidden" name="table" value="skills">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <input type="text" name="fields[category]" value="<?= htmlspecialchars($row['category']) ?>" style="width:150px; font-weight:600;">
                                <input type="text" name="fields[content]" value="<?= htmlspecialchars($row['content']) ?>" style="flex:1;">
                                <button type="submit" class="btn btn-primary">Save</button>
                            </form>
                        </div>
                        <div class="data-actions">
                             <form method="POST" onsubmit="return confirm('Delete?');">
                                <input type="hidden" name="action" value="delete_item">
                                <input type="hidden" name="table" value="skills">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <button type="submit" class="btn btn-danger">×</button>
                            </form>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <div style="padding:16px; background:#f8fafc; border-top:1px solid #e2e8f0;">
                    <form method="POST" style="display:flex; gap:10px;">
                        <input type="hidden" name="action" value="save_item">
                        <input type="hidden" name="table" value="skills">
                        <input type="text" name="fields[category]" placeholder="Category" style="width:150px;">
                        <input type="text" name="fields[content]" placeholder="List of skills..." style="flex:1;">
                        <button type="submit" class="btn btn-primary">Add New</button>
                    </form>
                </div>
            </div>
            <script>
                new Sortable(document.getElementById('skills-list'), {
                    handle: '.drag-handle',
                    animation: 150,
                    onEnd: function (evt) {
                        var itemEl = evt.item;
                        var newOrder = [];
                        document.querySelectorAll('#skills-list .data-item').forEach(el => newOrder.push(el.getAttribute('data-id')));
                        saveOrder('skills', newOrder);
                    }
                });
            </script>

        <?php elseif ($tab === 'experience'): ?>
             <?php if (isset($_GET['edit_id'])): 
                $jobId = $_GET['edit_id'];
                $stmt = $pdo->prepare("SELECT * FROM experience WHERE id = ?"); $stmt->execute([$jobId]);
                $job = $stmt->fetch(PDO::FETCH_ASSOC);
                $items = $pdo->prepare("SELECT * FROM experience_items WHERE experience_id = ? ORDER BY sort_order ASC");
                $items->execute([$jobId]);
                $jobItems = $items->fetchAll(PDO::FETCH_ASSOC);
             ?>
                <div class="header">
                    <h1>Edit Role</h1>
                    <a href="?tab=experience" class="btn btn-secondary">← Back</a>
                </div>
                <div class="card">
                     <form method="POST">
                        <input type="hidden" name="action" value="save_item">
                        <input type="hidden" name="table" value="experience">
                        <input type="hidden" name="id" value="<?= $job['id'] ?>">
                        <div class="form-row">
                            <div class="form-group"><label>Company</label><input type="text" name="fields[company]" value="<?= htmlspecialchars($job['company']) ?>"></div>
                            <div class="form-group"><label>Role</label><input type="text" name="fields[role]" value="<?= htmlspecialchars($job['role']) ?>"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label>Location</label><input type="text" name="fields[location]" value="<?= htmlspecialchars($job['location']) ?>"></div>
                            <div class="form-group"><label>Date Range</label><input type="text" name="fields[date_range]" value="<?= htmlspecialchars($job['date_range']) ?>"></div>
                        </div>
                        <button type="submit" class="btn btn-primary">Update Details</button>
                    </form>
                </div>
                
                <div class="card">
                    <h3>Highlights & Projects</h3>
                     <ul class="data-list" id="exp-items-list">
                        <?php foreach($jobItems as $item): ?>
                        <li class="data-item" data-id="<?= $item['id'] ?>">
                            <div class="drag-handle">☰</div>
                            <div class="data-content">
                                <form method="POST">
                                    <input type="hidden" name="action" value="save_exp_item">
                                    <input type="hidden" name="experience_id" value="<?= $job['id'] ?>">
                                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                    <div style="display:flex; gap:10px; margin-bottom:5px;">
                                        <select name="type" style="width:120px;">
                                            <option value="bullet" <?= $item['item_type']=='bullet'?'selected':'' ?>>Bullet</option>
                                            <option value="project" <?= $item['item_type']=='project'?'selected':'' ?>>Project</option>
                                        </select>
                                        <input type="text" name="project_name" value="<?= htmlspecialchars($item['project_name']??'') ?>" placeholder="Project Name">
                                    </div>
                                    <textarea name="content" rows="2"><?= htmlspecialchars($item['content']) ?></textarea>
                                    <div style="margin-top:5px;">
                                        <button type="submit" class="btn btn-primary" style="padding: 5px 10px;">Save</button>
                                    </div>
                                </form>
                            </div>
                            <div class="data-actions">
                                <form method="POST" onsubmit="return confirm('Delete?');">
                                    <input type="hidden" name="action" value="delete_item">
                                    <input type="hidden" name="table" value="experience_items">
                                    <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                    <button type="submit" class="btn btn-danger">×</button>
                                </form>
                            </div>
                        </li>
                        <?php endforeach; ?>
                     </ul>
                     <div style="padding:16px;">
                        <h4>Add New Highlight</h4>
                        <form method="POST">
                            <input type="hidden" name="action" value="save_exp_item">
                            <input type="hidden" name="experience_id" value="<?= $job['id'] ?>">
                            <div style="display:flex; gap:10px; margin-bottom:5px;">
                                <select name="type" style="width:120px;">
                                    <option value="bullet">Bullet</option>
                                    <option value="project">Project</option>
                                </select>
                                <input type="text" name="project_name" placeholder="Project Name">
                            </div>
                            <textarea name="content" rows="2" placeholder="Description..."></textarea>
                            <button type="submit" class="btn btn-primary" style="margin-top:10px;">Add</button>
                        </form>
                    </div>
                </div>
                 <script>
                    new Sortable(document.getElementById('exp-items-list'), { handle: '.drag-handle', animation: 150, onEnd: function (evt) {
                        var ids = []; document.querySelectorAll('#exp-items-list .data-item').forEach(el => ids.push(el.getAttribute('data-id')));
                        saveOrder('experience_items', ids);
                    }});
                </script>

             <?php else: ?>
                <div class="header"><h1>Experience</h1></div>
                <div class="card">
                    <ul class="data-list" id="experience-list">
                        <?php $jobs = getTable($pdo, 'experience'); foreach($jobs as $job): ?>
                        <li class="data-item" data-id="<?= $job['id'] ?>">
                            <div class="drag-handle">☰</div>
                            <div class="data-content" style="display:flex; justify-content:space-between; align-items:center;">
                                <div><strong><?= htmlspecialchars($job['company']) ?></strong><br><small><?= htmlspecialchars($job['role']) ?></small></div>
                                <a href="?tab=experience&edit_id=<?= $job['id'] ?>" class="btn btn-secondary">Edit</a>
                            </div>
                             <div class="data-actions">
                                <form method="POST" onsubmit="return confirm('Delete Job?');">
                                    <input type="hidden" name="action" value="delete_item">
                                    <input type="hidden" name="table" value="experience">
                                    <input type="hidden" name="id" value="<?= $job['id'] ?>">
                                    <button type="submit" class="btn btn-danger">×</button>
                                </form>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                     <div style="padding:16px;">
                        <form method="POST">
                            <input type="hidden" name="action" value="save_item">
                            <input type="hidden" name="table" value="experience">
                            <div class="form-row">
                                <input type="text" name="fields[company]" placeholder="Company">
                                <input type="text" name="fields[role]" placeholder="Role">
                            </div>
                            <button type="submit" class="btn btn-primary" style="margin-top:10px;">Create Job</button>
                        </form>
                    </div>
                </div>
                <script>
                    new Sortable(document.getElementById('experience-list'), { handle: '.drag-handle', animation: 150, onEnd: function (evt) {
                        var ids = []; document.querySelectorAll('#experience-list .data-item').forEach(el => ids.push(el.getAttribute('data-id')));
                        saveOrder('experience', ids);
                    }});
                </script>
             <?php endif; ?>

        <?php elseif ($tab === 'education'): ?>
             <div class="header"><h1>Education & Certs</h1></div>
             <div class="card">
                <h3>Education</h3>
                <ul class="data-list" id="edu-list">
                    <?php foreach(getTable($pdo, 'education') as $row): ?>
                    <li class="data-item" data-id="<?= $row['id'] ?>">
                         <div class="drag-handle">☰</div>
                         <div class="data-content">
                            <form method="POST" class="form-row">
                                <input type="hidden" name="action" value="save_item">
                                <input type="hidden" name="table" value="education">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <input type="text" name="fields[degree]" value="<?= htmlspecialchars($row['degree']) ?>">
                                <input type="text" name="fields[institution]" value="<?= htmlspecialchars($row['institution']) ?>">
                                <input type="text" name="fields[date_range]" value="<?= htmlspecialchars($row['date_range']) ?>">
                                <button type="submit" class="btn btn-primary" style="width:auto;">Save</button>
                            </form>
                         </div>
                         <div class="data-actions">
                             <form method="POST" onsubmit="return confirm('Delete?');">
                                <input type="hidden" name="action" value="delete_item">
                                <input type="hidden" name="table" value="education">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <button type="submit" class="btn btn-danger">×</button>
                            </form>
                         </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <div style="padding:16px;">
                    <form method="POST" class="form-row">
                        <input type="hidden" name="action" value="save_item">
                        <input type="hidden" name="table" value="education">
                         <input type="text" name="fields[degree]" placeholder="Degree">
                         <input type="text" name="fields[institution]" placeholder="Institution">
                         <input type="text" name="fields[date_range]" placeholder="Date Range">
                         <button type="submit" class="btn btn-primary">Add</button>
                    </form>
                </div>
             </div>

             <div class="card">
                <h3>Certifications</h3>
                <ul class="data-list" id="cert-list">
                    <?php foreach(getTable($pdo, 'certifications') as $row): ?>
                    <li class="data-item" data-id="<?= $row['id'] ?>">
                        <div class="drag-handle">☰</div>
                        <div class="data-content">
                            <form method="POST" class="form-row" style="width:100%;">
                                <input type="hidden" name="action" value="save_item">
                                <input type="hidden" name="table" value="certifications">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <input type="text" name="fields[name]" value="<?= htmlspecialchars($row['name']) ?>" style="flex:1;">
                                <button type="submit" class="btn btn-primary" style="width:auto;">Save</button>
                            </form>
                        </div>
                        <div class="data-actions">
                             <form method="POST" onsubmit="return confirm('Delete?');">
                                <input type="hidden" name="action" value="delete_item">
                                <input type="hidden" name="table" value="certifications">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <button type="submit" class="btn btn-danger">×</button>
                            </form>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <div style="padding:16px;">
                    <form method="POST" class="form-row">
                        <input type="hidden" name="action" value="save_item">
                        <input type="hidden" name="table" value="certifications">
                         <input type="text" name="fields[name]" placeholder="Certification Name" style="flex:1;">
                         <button type="submit" class="btn btn-primary">Add</button>
                    </form>
                </div>
             </div>

             <script>
                    new Sortable(document.getElementById('edu-list'), { handle: '.drag-handle', animation: 150, onEnd: function (evt) {
                        var ids = []; document.querySelectorAll('#edu-list .data-item').forEach(el => ids.push(el.getAttribute('data-id')));
                        saveOrder('education', ids);
                    }});
                    new Sortable(document.getElementById('cert-list'), { handle: '.drag-handle', animation: 150, onEnd: function (evt) {
                        var ids = []; document.querySelectorAll('#cert-list .data-item').forEach(el => ids.push(el.getAttribute('data-id')));
                        saveOrder('certifications', ids);
                    }});
             </script>

        <?php elseif ($tab === 'packages'): ?>
            <div class="header"><h1>Projects</h1></div>
            <div class="card">
                <h3>Manage Projects</h3>
                <ul class="data-list" id="pkg-list">
                    <?php foreach(getTable($pdo, 'packages') as $row): ?>
                    <li class="data-item" data-id="<?= $row['id'] ?>">
                        <div class="drag-handle">☰</div>
                        <div class="data-content">
                            <form method="POST" style="display:flex; gap:10px; width:100%;">
                                <input type="hidden" name="action" value="save_item">
                                <input type="hidden" name="table" value="packages">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <div style="flex:1;"><input type="text" name="fields[name]" value="<?= htmlspecialchars($row['name']) ?>" placeholder="Name" style="font-weight:600;"></div>
                                <div style="width:120px;"><input type="text" name="fields[type]" value="<?= htmlspecialchars($row['type']??'Open Source') ?>" placeholder="Type"></div>
                                <div style="flex:2;"><input type="text" name="fields[description]" value="<?= htmlspecialchars($row['description']) ?>" placeholder="Description"></div>
                                <div style="flex:1;"><input type="text" name="fields[url]" value="<?= htmlspecialchars($row['url']) ?>" placeholder="URL"></div>
                                <button type="submit" class="btn btn-primary" style="width:auto;">Save</button>
                            </form>
                        </div>
                        <div class="data-actions">
                             <form method="POST" onsubmit="return confirm('Delete?');">
                                <input type="hidden" name="action" value="delete_item">
                                <input type="hidden" name="table" value="packages">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <button type="submit" class="btn btn-danger">×</button>
                            </form>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <div style="padding:16px; background:#f8fafc; border-top:1px solid #e2e8f0;">
                    <h4>Add New Project</h4>
                     <form method="POST" class="form-row three-col" style="align-items:end;">
                        <input type="hidden" name="action" value="save_item">
                        <input type="hidden" name="table" value="packages">
                        <div class="form-group"><label>Project Name</label><input type="text" name="fields[name]" placeholder="Name" required></div>
                        <div class="form-group"><label>Type</label><input type="text" name="fields[type]" placeholder="e.g. Open Source" required></div>
                        <div class="form-group"><label>Description</label><input type="text" name="fields[description]" placeholder="Description" required></div>
                        <div class="form-group"><label>URL</label><input type="text" name="fields[url]" placeholder="https://..." required></div>
                        <button type="submit" class="btn btn-primary" style="grid-column: 1 / -1; width:auto; justify-self:start;">Add</button>
                    </form>
                </div>
            </div>
             <script>
                new Sortable(document.getElementById('pkg-list'), { handle: '.drag-handle', animation: 150, onEnd: function (evt) {
                    var ids = []; document.querySelectorAll('#pkg-list .data-item').forEach(el => ids.push(el.getAttribute('data-id')));
                    saveOrder('packages', ids);
                }});
            </script>

        <?php elseif ($tab === 'languages'): ?>
            <?php
            $levels = ['Native', 'Fluent', 'Proficient (C2)', 'Advanced (C1)', 'Intermediate (B2)', 'Conversational (B1)', 'Basic (A1/A2)'];
            $allLangs = ['Arabic', 'Chinese', 'Dutch', 'English', 'French', 'German', 'Hindi', 'Italian', 'Japanese', 'Korean', 'Portuguese', 'Russian', 'Spanish', 'Turkish'];
            
            $currentLanguages = getTable($pdo, 'languages');
            $existingLangNames = array_map('strtolower', array_column($currentLanguages, 'language'));
            
            // Filter available languages (case-insensitive check)
            $availableLangs = array_filter($allLangs, function($lang) use ($existingLangNames) {
                return !in_array(strtolower($lang), $existingLangNames);
            });
            ?>
            <div class="header"><h1>Languages</h1></div>
            <div class="card">
                <h3>Manage Languages</h3>
                <ul class="data-list" id="lang-list">
                    <?php foreach($currentLanguages as $row): ?>
                    <li class="data-item" data-id="<?= $row['id'] ?>">
                        <div class="drag-handle">☰</div>
                        <div class="data-content">
                            <form method="POST" class="form-row">
                                <input type="hidden" name="action" value="save_item">
                                <input type="hidden" name="table" value="languages">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                
                                <input type="text" value="<?= htmlspecialchars($row['language']) ?>" disabled style="background:#f1f5f9; color:#64748b; cursor:not-allowed;">
                                
                                <select name="fields[proficiency]">
                                    <?php foreach($levels as $l): ?>
                                    <option value="<?= $l ?>" <?= $row['proficiency'] == $l ? 'selected' : '' ?>><?= $l ?></option>
                                    <?php endforeach; ?>
                                </select>
                                
                                <button type="submit" class="btn btn-primary" style="width:auto;">Save</button>
                            </form>
                        </div>
                        <div class="data-actions">
                             <form method="POST" onsubmit="return confirm('Delete?');">
                                <input type="hidden" name="action" value="delete_item">
                                <input type="hidden" name="table" value="languages">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <button type="submit" class="btn btn-danger">×</button>
                            </form>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <div style="padding:16px;">
                    <form method="POST" class="form-row">
                        <input type="hidden" name="action" value="save_item">
                        <input type="hidden" name="table" value="languages">
                        
                         <select name="fields[language]">
                            <option value="" disabled selected>Select Language</option>
                            <?php foreach($availableLangs as $l): ?>
                            <option value="<?= $l ?>"><?= $l ?></option>
                            <?php endforeach; ?>
                         </select>
                         
                         <select name="fields[proficiency]">
                            <option value="" disabled selected>Select Level</option>
                            <?php foreach($levels as $l): ?>
                            <option value="<?= $l ?>"><?= $l ?></option>
                            <?php endforeach; ?>
                         </select>
                         
                         <button type="submit" class="btn btn-primary">Add</button>
                    </form>
                </div>
            </div>
             <script>
                new Sortable(document.getElementById('lang-list'), { handle: '.drag-handle', animation: 150, onEnd: function (evt) {
                    var ids = []; document.querySelectorAll('#lang-list .data-item').forEach(el => ids.push(el.getAttribute('data-id')));
                    saveOrder('languages', ids);
                }});
            </script>

        <?php elseif ($tab === 'section_order'): ?>
            <div class="header"><h1>Layout & Custom Sections</h1></div>
            <div class="card">
                <h3>Main Section Reordering</h3>
                <ul class="data-list" id="section-sort-list">
                    <?php foreach($currentOrder as $sec): ?>
                    <li class="data-item" data-id="<?= $sec['id'] ?>">
                        <div class="drag-handle">☰</div>
                        <div class="data-content"><strong><?= htmlspecialchars($sec['title']) ?></strong></div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="card">
                <h3>Manage Custom Sections</h3>
                <?php foreach(getTable($pdo, 'custom_sections') as $row): ?>
                <div style="border:1px solid #e2e8f0; padding:15px; margin-bottom:15px; border-radius:6px;">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_item">
                        <input type="hidden" name="table" value="custom_sections">
                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                        <div class="form-group"><label>Title</label><input type="text" name="fields[title]" value="<?= htmlspecialchars($row['title']) ?>"></div>
                        <div class="form-group"><label>Content</label><textarea name="fields[content]" rows="4"><?= htmlspecialchars($row['content']) ?></textarea></div>
                        <div style="display:flex; justify-content:space-between;">
                            <button type="submit" class="btn btn-primary">Save Section</button>
                            <button type="submit" form="del_sec_<?= $row['id'] ?>" class="btn btn-danger">Delete</button>
                        </div>
                    </form>
                    <form method="POST" id="del_sec_<?= $row['id'] ?>" onsubmit="return confirm('Delete section?');"><input type="hidden" name="action" value="delete_item"><input type="hidden" name="table" value="custom_sections"><input type="hidden" name="id" value="<?= $row['id'] ?>"></form>
                </div>
                <?php endforeach; ?>
                 <div style="background:#f8fafc; padding:15px; border-radius:6px;">
                    <h4>Create New Section</h4>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_item">
                        <input type="hidden" name="table" value="custom_sections">
                        <input type="text" name="fields[title]" placeholder="Title (e.g. Publications)" class="form-group">
                        <textarea name="fields[content]" placeholder="Content..." rows="3" class="form-group"></textarea>
                        <button type="submit" class="btn btn-primary">Create</button>
                    </form>
                </div>
            </div>
             <script>
                new Sortable(document.getElementById('section-sort-list'), { handle: '.drag-handle', animation: 150, onEnd: function (evt) {
                    var ids = []; document.querySelectorAll('#section-sort-list .data-item').forEach(el => ids.push(el.getAttribute('data-id')));
                    saveOrder('sections', ids);
                }});
            </script>

        <?php elseif ($tab === 'import_export'): ?>
            <div class="header"><h1>Import / Export Data</h1></div>
            <div class="card">
                <h3>Export</h3>
                <a href="?action=export_json" target="_blank" class="btn btn-secondary">⬇ Download JSON Backup</a>
            </div>
            <div class="card">
                <h3>Import</h3>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_json">
                    
                    <div class="file-upload-wrapper">
                        <label class="file-upload-label">
                            <strong id="file-label-text">Click to Upload JSON</strong>
                            <span>or drag and drop here</span>
                            <input type="file" name="json_file" accept=".json" required onchange="document.getElementById('file-label-text').innerText = this.files[0] ? this.files[0].name : 'Click to Upload JSON'">
                        </label>
                    </div>

                    <div class="form-group">
                        <label>Mode</label>
                        <div style="display:flex; gap:20px; margin-top:5px;">
                            <label style="display:inline-flex; align-items:center; gap:8px; font-weight:400; text-transform:none; cursor:pointer;">
                                <input type="radio" name="import_mode" value="update" checked style="width:auto;"> Merge/Update
                            </label>
                            <label style="display:inline-flex; align-items:center; gap:8px; font-weight:400; text-transform:none; cursor:pointer;">
                                <input type="radio" name="import_mode" value="replace" style="width:auto;"> Replace All
                            </label>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;">Start Import</button>
                </form>
            </div>
        <?php endif; ?>

    </div>

    <!-- PREVIEW PANEL -->
    <div class="preview-panel" id="previewPanel">
        <div class="preview-header">
            <span class="stats" id="pageStats">Loading stats...</span>
            <button onclick="document.getElementById('cvFrame').contentWindow.location.reload()" class="btn btn-secondary" style="padding: 5px 10px; font-size: 0.8em;">Refresh</button>
        </div>
        <div class="preview-content">
            <iframe src="index.php?preview=1" id="cvFrame" onload="calcStats()"></iframe>
        </div>
    </div>

    <script>
        // Sortable ... (existing)

        function saveOrder(table, idArray) {
            const formData = new FormData();
            formData.append('action', 'save_order');
            formData.append('table', table);
            formData.append('order', idArray.join(','));
            fetch(window.location.href, { method: 'POST', body: formData });
        }
        
        function togglePreview() {
            document.body.classList.toggle('preview-active');
            const panel = document.getElementById('previewPanel');
            panel.classList.toggle('active');
            
            const isActive = panel.classList.contains('active');
            localStorage.setItem('cv_preview_active', isActive);
            
            if(isActive) calcStats();
        }

        function fitPreview() {
            const iframe = document.getElementById('cvFrame');
            if (!iframe.contentWindow || !iframe.contentWindow.document) return;
            
            const doc = iframe.contentWindow.document;
            const container = doc.querySelector('.cv-container');
            const wrapper = document.querySelector('.preview-content');
            
            if (container && wrapper) {
                // Reset to get natural size
                container.style.transform = 'none';
                
                const neededWidth = container.offsetWidth + 40; // +margins
                const availableWidth = wrapper.offsetWidth;
                
                if (neededWidth > availableWidth) {
                    const scale = (availableWidth - 40) / neededWidth; // -40 for padding
                    container.style.transform = `scale(${scale})`;
                    container.style.transformOrigin = 'top center';
                    // Adjust body height to account for scaling empty space if needed, 
                    // but usually scaling visually is enough.
                } else {
                    container.style.transform = 'none';
                }
            }
        }

        function calcStats() {
            const iframe = document.getElementById('cvFrame');
            try {
                const A4_HEIGHT_PX = 1123; 
                const doc = iframe.contentWindow.document;
                const container = doc.querySelector('.cv-container');
                const height = container ? container.offsetHeight : doc.body.scrollHeight;
                const pageCount = Math.ceil(height / A4_HEIGHT_PX);
                document.getElementById('pageStats').innerHTML = `Height: ${height}px (~${pageCount} Page${pageCount>1?'s':''})`;
                
                // Resize iframe to fit content height so scrolling happens on the panel
                iframe.style.height = (height + 50) + 'px';

                // Inject Styles for clean preview
                const styleId = 'preview-overrides';
                if (!doc.getElementById(styleId)) {
                    const style = doc.createElement('style');
                    style.id = styleId;
                    style.innerHTML = `
                        html, body { background: transparent !important; margin: 0; padding: 0; overflow: hidden; height: auto; }
                        .cv-container { box-shadow: 0 0 10px rgba(0,0,0,0.2) !important; margin: 20px auto !important; min-height: 297mm; }
                    `;
                    doc.head.appendChild(style);
                }
                
                fitPreview();
            } catch(e) {}
        }
        
        window.addEventListener('resize', fitPreview);
        
        // --- LIVE PREVIEW LOGIC ---
        document.addEventListener('DOMContentLoaded', () => {
            // Restore Preview State
            if (localStorage.getItem('cv_preview_active') === 'true') {
                 document.body.classList.add('preview-active');
                 document.getElementById('previewPanel').classList.add('active');
            }

            // Hide alerts
            setTimeout(() => { document.querySelectorAll('.alert').forEach(el => el.style.display = 'none'); }, 4000);

            const iframe = document.getElementById('cvFrame');
            iframe.onload = () => { calcStats(); };

            // Map inputs to iframe elements
            const mappings = {
                // ... used in updatePreview
            };

            function updatePreview(e) {
                if (!iframe.contentWindow || !iframe.contentWindow.document) return;
                const doc = iframe.contentWindow.document;
                const input = e.target;
                const name = input.name;
                const val = input.value;

                // 1. Header Metadata
                const headerMap = {
                    'meta[header_name]': 'cv-name',
                    'meta[header_role]': 'cv-role',
                    'meta[header_location]': 'cv-location',
                    'meta[header_phone]': 'cv-phone',
                    'meta[header_email]': 'cv-email',
                    'meta[header_linkedin]': 'cv-linkedin',
                    'meta[profile_summary]': 'cv-profile-summary'
                };

                if (headerMap[name]) {
                  const el = doc.getElementById(headerMap[name]);
                  if (el) {
                      if (name === 'meta[header_email]') {
                          const link = el.querySelector('a') || el;
                          link.innerText = val;
                          link.href = 'mailto:' + val;
                      } else if (name === 'meta[header_linkedin]') {
                          const link = el.querySelector('a') || el;
                          link.innerText = val;
                          link.href = 'https://' + val;
                      } else if (name === 'meta[profile_summary]') {
                          el.innerHTML = val.replace(/\n/g, '<br>');
                      } else {
                          el.innerText = val;
                      }
                  }
                  // Recalc if height changes (e.g. summary wrap)
                  // Debounce this if performance hit
                  return;
                }

                // 2. Dynamic Items
                const form = input.closest('form');
                if (form) {
                    const idInput = form.querySelector('input[name="id"]');
                    const tableInput = form.querySelector('input[name="table"]');
                    const itemIdInput = form.querySelector('input[name="item_id"]'); 

                    // Standard Fields
                    if (idInput && tableInput) {
                        const id = idInput.value;
                        const table = tableInput.value;
                        const fieldMatch = name.match(/fields\[(.*?)\]/);
                        
                        if (fieldMatch) {
                            const field = fieldMatch[1];
                            let selectorPrefix = '';
                            
                            if (table === 'skills') {
                                if (field === 'category') selectorPrefix = 'skill-category-';
                                if (field === 'content') selectorPrefix = 'skill-content-';
                            } else if (table === 'experience') {
                                if (field === 'role') selectorPrefix = 'exp-role-';
                                if (field === 'company') selectorPrefix = 'exp-company-';
                                if (field === 'location') selectorPrefix = 'exp-location-';
                                if (field === 'date_range') selectorPrefix = 'exp-date-';
                            } else if (table === 'education') {
                                if (field === 'degree') selectorPrefix = 'edu-degree-';
                                if (field === 'institution') selectorPrefix = 'edu-institution-';
                                if (field === 'date_range') selectorPrefix = 'edu-date-';
                            } else if (table === 'languages') {
                                if (field === 'language') selectorPrefix = 'lang-name-';
                                if (field === 'proficiency') selectorPrefix = 'lang-proficiency-';
                            } else if (table === 'certifications') {
                                if (field === 'name') selectorPrefix = 'cert-name-';
                            }

                            if (selectorPrefix) {
                                const targetEl = doc.querySelector(`[data-id="${selectorPrefix}${id}"]`);
                                if (targetEl) {
                                    targetEl.innerText = val; 
                                    targetEl.classList.add('highlight-update');
                                    setTimeout(() => targetEl.classList.remove('highlight-update'), 1000);
                                }
                            }
                        }
                    } 
                    // Experience Items (Bullets/Projects)
                    else if (itemIdInput) { 
                        const itemId = itemIdInput.value;
                        let target = null;
                        
                        if (name === 'project_name') {
                             target = doc.querySelector(`[data-id="exp-item-project-${itemId}"]`);
                        } else if (name === 'content') {
                             target = doc.querySelector(`[data-id="exp-item-content-${itemId}"]`);
                        }
                        
                        if (target) {
                            target.innerText = val;
                            target.classList.add('highlight-update');
                            setTimeout(() => target.classList.remove('highlight-update'), 1000);
                        }
                    }
                }
            }

            // Bind
            document.body.addEventListener('input', updatePreview);
            
             // Refresh on submit
            document.querySelectorAll('form').forEach(f => {
                f.addEventListener('submit', () => {
                    const panel = document.getElementById('previewPanel');
                    if(panel.classList.contains('active')) {
                        setTimeout(() => { document.getElementById('cvFrame').contentWindow.location.reload(); }, 500);
                    }
                });
            });
        });
    </script>
</body>
</html>
