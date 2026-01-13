<?php
$config = require 'config.php';
$pdo = new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}", $config['username'], $config['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tab = $_GET['tab'] ?? 'meta';
$message = '';

// --- ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        try {
            if ($_POST['action'] === 'update_meta') {
                $pdo->beginTransaction();
                foreach ($_POST['meta'] as $key => $value) {
                    $stmt = $pdo->prepare("INSERT INTO meta (`key`, `value`) VALUES (:key, :value) ON DUPLICATE KEY UPDATE `value` = :value");
                    $stmt->execute([':key' => $key, ':value' => $value]);
                }
                $pdo->commit();
                $message = "Meta updated!";
            }
            elseif ($_POST['action'] === 'delete_item') {
                $table = $_POST['table'];
                $id = $_POST['id'];
                if (in_array($table, ['skills', 'education', 'certifications', 'languages', 'custom_sections', 'experience', 'experience_items', 'packages'])) {
                    $stmt = $pdo->prepare("DELETE FROM `$table` WHERE id = ?");
                    $stmt->execute([$id]);
                    $message = "Item deleted.";
                }
            }
            elseif ($_POST['action'] === 'save_skill') {
                $cat = $_POST['category'];
                $content = $_POST['content'];
                $id = $_POST['id'] ?? null;
                if ($id) {
                    $stmt = $pdo->prepare("UPDATE skills SET category=?, content=? WHERE id=?");
                    $stmt->execute([$cat, $content, $id]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO skills (category, content) VALUES (?, ?)");
                    $stmt->execute([$cat, $content]);
                }
                $message = "Skill saved.";
            }
            elseif ($_POST['action'] === 'save_education') {
                 $id = $_POST['id'] ?? '';
                 if ($id) {
                     $stmt = $pdo->prepare("UPDATE education SET degree=?, institution=?, date_range=? WHERE id=?");
                     $stmt->execute([$_POST['degree'], $_POST['institution'], $_POST['date_range'], $id]);
                 } else {
                     $stmt = $pdo->prepare("INSERT INTO education (degree, institution, date_range) VALUES (?, ?, ?)");
                     $stmt->execute([$_POST['degree'], $_POST['institution'], $_POST['date_range']]);
                 }
                 $message = "Education saved.";
            }
            elseif ($_POST['action'] === 'save_custom') {
                $id = $_POST['id'] ?? '';
                if ($id) {
                    $stmt = $pdo->prepare("UPDATE custom_sections SET title=?, content=? WHERE id=?");
                    $stmt->execute([$_POST['title'], $_POST['content'], $id]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO custom_sections (title, content) VALUES (?, ?)");
                    $stmt->execute([$_POST['title'], $_POST['content']]);
                }
                $message = "Section saved.";
            }
            elseif ($_POST['action'] === 'save_experience') {
                $id = $_POST['id'] ?? '';
                if ($id) {
                    $stmt = $pdo->prepare("UPDATE experience SET company=?, role=?, location=?, date_range=? WHERE id=?");
                    $stmt->execute([$_POST['company'], $_POST['role'], $_POST['location'], $_POST['date_range'], $id]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO experience (company, role, location, date_range) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$_POST['company'], $_POST['role'], $_POST['location'], $_POST['date_range']]);
                }
                $message = "Journey updated.";
            }
            elseif ($_POST['action'] === 'save_exp_item') {
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
                     $stmt = $pdo->prepare("INSERT INTO experience_items (experience_id, item_type, project_name, content, sort_order) VALUES (?, ?, ?, ?, 99)");
                     $stmt->execute([$expId, $sysType, $pName, $content]);
                 }
                 $message = "Item saved.";
            }
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
        }
    }
}

// --- DATA FETCHING ---
function getMeta($pdo) {
    $stmt = $pdo->query("SELECT * FROM meta");
    $meta = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $meta[$row['key']] = $row['value'];
    }
    return $meta;
}

$activeData = [];
if ($tab == 'meta') {
    $activeData = getMeta($pdo);
} elseif ($tab == 'skills') {
    $activeData = $pdo->query("SELECT * FROM skills ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
} elseif ($tab == 'education') {
    $activeData = $pdo->query("SELECT * FROM education ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
} elseif ($tab == 'custom') {
    $activeData = $pdo->query("SELECT * FROM custom_sections ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
} elseif ($tab == 'experience') {
    if (isset($_GET['edit_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM experience WHERE id = ?");
        $stmt->execute([$_GET['edit_id']]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($job) {
             $stmtItems = $pdo->prepare("SELECT * FROM experience_items WHERE experience_id = ? ORDER BY sort_order ASC, id ASC");
             $stmtItems->execute([$job['id']]);
             $job['items'] = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
             $activeData = $job;
        }
    } else {
        $activeData = $pdo->query("SELECT * FROM experience ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>CV Admin</title>
    <style>
        body { font-family: sans-serif; display: flex; height: 100vh; margin: 0; }
        .sidebar { width: 220px; background: #333; color: white; padding: 20px; flex-shrink: 0; }
        .sidebar a { display: block; color: #ccc; padding: 10px; text-decoration: none; }
        .sidebar a.active { color: white; font-weight: bold; background: #444; }
        .main { flex: 1; padding: 20px; overflow-y: auto; background: #f4f4f4; }
        .card { background: white; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        input, textarea, select { width: 100%; padding: 8px; margin-bottom: 10px; border: 1px solid #ddd; box-sizing: border-box; }
        button { padding: 10px 15px; background: #007bff; color: white; border: none; cursor: pointer; border-radius: 4px; }
        button:hover { background: #0056b3; }
        button.delete { background: #dc3545; }
        button.delete:hover { background: #a71d2a; }
        button.secondary { background: #6c757d; }
        button.secondary:hover { background: #545b62; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 10px; border-bottom: 1px solid #ddd; text-align: left; }
        .alert { padding: 10px; background: #d4edda; color: #155724; margin-bottom: 20px; border-radius: 4px; }
        .header-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .nested-item { border-left: 3px solid #eee; padding-left: 15px; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="sidebar">
        <h3>CV Admin</h3>
        <a href="?tab=meta" class="<?= $tab=='meta'?'active':'' ?>">Meta / Header</a>
        <a href="?tab=experience" class="<?= $tab=='experience'?'active':'' ?>">Professional Experience</a>
        <a href="?tab=skills" class="<?= $tab=='skills'?'active':'' ?>">Technical Skills</a>
        <a href="?tab=education" class="<?= $tab=='education'?'active':'' ?>">Education</a>
        <a href="?tab=custom" class="<?= $tab=='custom'?'active':'' ?>">Custom Sections</a>
        <div style="margin-top: 30px; border-top: 1px solid #555; padding-top: 10px;">
             <a href="index.php" target="_blank">View Site ↗</a>
        </div>
    </div>
    <div class="main">
        <?php if($message): ?><div class="alert"><?= htmlspecialchars($message) ?></div><?php endif; ?>

        <?php if ($tab == 'meta'): ?>
            <div class="card">
                <h2>Edit Header & Meta</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="update_meta">
                    <label>Name</label>
                    <input type="text" name="meta[header_name]" value="<?= htmlspecialchars($activeData['header_name'] ?? '') ?>">
                    <label>Role</label>
                    <input type="text" name="meta[header_role]" value="<?= htmlspecialchars($activeData['header_role'] ?? '') ?>">
                    <label>Location</label>
                    <input type="text" name="meta[header_location]" value="<?= htmlspecialchars($activeData['header_location'] ?? '') ?>">
                    <label>Phone</label>
                    <input type="text" name="meta[header_phone]" value="<?= htmlspecialchars($activeData['header_phone'] ?? '') ?>">
                    <label>Email</label>
                    <input type="text" name="meta[header_email]" value="<?= htmlspecialchars($activeData['header_email'] ?? '') ?>">
                    <label>LinkedIn</label>
                    <input type="text" name="meta[header_linkedin]" value="<?= htmlspecialchars($activeData['header_linkedin'] ?? '') ?>">
                    <label>Profile Summary</label>
                    <textarea name="meta[profile_summary]" rows="5"><?= htmlspecialchars($activeData['profile_summary'] ?? '') ?></textarea>
                    
                    <button type="submit">Save Meta</button>
                </form>
            </div>
        <?php elseif ($tab == 'skills'): ?>
            <div class="card">
                <h2>Technical Skills</h2>
                <table>
                    <thead><tr><th>Category</th><th>Content</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach($activeData as $row): ?>
                        <tr>
                            <form method="POST">
                                <input type="hidden" name="action" value="save_skill">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <td><input type="text" name="category" value="<?= htmlspecialchars($row['category']) ?>"></td>
                                <td><input type="text" name="content" value="<?= htmlspecialchars($row['content']) ?>"></td>
                                <td>
                                    <button type="submit">Save</button>
                                    <button type="submit" name="action" value="delete_item" form="del_<?= $row['id'] ?>_form" class="delete">X</button>
                                </td>
                            </form>
                            <form method="POST" id="del_<?= $row['id'] ?>_form" onsubmit="return confirm('Delete?');">
                                <input type="hidden" name="action" value="delete_item">
                                <input type="hidden" name="table" value="skills">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                            </form>
                        </tr>
                        <?php endforeach; ?>
                         <tr>
                            <form method="POST">
                                <input type="hidden" name="action" value="save_skill">
                                <td><input type="text" name="category" placeholder="New Category"></td>
                                <td><input type="text" name="content" placeholder="Skills list..."></td>
                                <td><button type="submit">Add</button></td>
                            </form>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php elseif ($tab == 'education'): ?>
             <div class="card">
                <h2>Education</h2>
                <table>
                    <thead><tr><th>Degree</th><th>Institution</th><th>Date</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach($activeData as $row): ?>
                        <tr>
                            <form method="POST">
                                <input type="hidden" name="action" value="save_education">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <td><input type="text" name="degree" value="<?= htmlspecialchars($row['degree']) ?>"></td>
                                <td><input type="text" name="institution" value="<?= htmlspecialchars($row['institution']) ?>"></td>
                                <td><input type="text" name="date_range" value="<?= htmlspecialchars($row['date_range']) ?>"></td>
                                <td>
                                    <button type="submit">Save</button>
                                    <button type="submit" name="action" value="delete_item" form="del_edu_<?= $row['id'] ?>_form" class="delete">X</button>
                                </td>
                            </form>
                            <form method="POST" id="del_edu_<?= $row['id'] ?>_form" onsubmit="return confirm('Delete?');">
                                <input type="hidden" name="action" value="delete_item">
                                <input type="hidden" name="table" value="education">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                            </form>
                        </tr>
                        <?php endforeach; ?>
                        <tr>
                            <form method="POST">
                                <input type="hidden" name="action" value="save_education">
                                <td><input type="text" name="degree" placeholder="Degree"></td>
                                <td><input type="text" name="institution" placeholder="Institution"></td>
                                <td><input type="text" name="date_range" placeholder="Date"></td>
                                <td><button type="submit">Add</button></td>
                            </form>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php elseif ($tab == 'custom'): ?>
            <div class="card">
                <h2>Custom Sections</h2>
                <p>Add new sections to your CV.</p>
                <?php foreach($activeData as $row): ?>
                <div class="card" style="border:1px solid #eee;">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_custom">
                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                        <label>Title</label>
                        <input type="text" name="title" value="<?= htmlspecialchars($row['title']) ?>">
                        <label>Content</label>
                        <textarea name="content" rows="4"><?= htmlspecialchars($row['content']) ?></textarea>
                        <div style="text-align:right;">
                            <button type="submit">Save</button>
                            <button type="submit" name="action" value="delete_item" form="del_cust_<?= $row['id'] ?>_form" class="delete">Delete Section</button>
                        </div>
                    </form>
                    <form method="POST" id="del_cust_<?= $row['id'] ?>_form" onsubmit="return confirm('Delete?');">
                        <input type="hidden" name="action" value="delete_item">
                        <input type="hidden" name="table" value="custom_sections">
                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                    </form>
                </div>
                <?php endforeach; ?>
                
                <div class="card" style="border-top: 5px solid #007bff;">
                    <h3>Add New Section</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_custom">
                        <input type="text" name="title" placeholder="Section Title (e.g. Volunteering)">
                        <textarea name="content" rows="4" placeholder="Content..."></textarea>
                        <button type="submit">Add Section</button>
                    </form>
                </div>
            </div>
        <?php elseif ($tab == 'experience'): ?>
            <?php if (isset($_GET['edit_id'])): ?>
                <div class="header-row">
                    <h2>Edit Job</h2>
                    <a href="?tab=experience"><button class="secondary">Back to List</button></a>
                </div>
                
                <div class="card">
                     <form method="POST">
                        <input type="hidden" name="action" value="save_experience">
                        <input type="hidden" name="id" value="<?= $activeData['id'] ?>">
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                            <div><label>Company</label><input type="text" name="company" value="<?= htmlspecialchars($activeData['company']) ?>"></div>
                            <div><label>Role</label><input type="text" name="role" value="<?= htmlspecialchars($activeData['role']) ?>"></div>
                            <div><label>Location</label><input type="text" name="location" value="<?= htmlspecialchars($activeData['location']) ?>"></div>
                            <div><label>Date Range</label><input type="text" name="date_range" value="<?= htmlspecialchars($activeData['date_range']) ?>"></div>
                        </div>
                        <button type="submit">Update Job Details</button>
                    </form>
                </div>

                <h3>Bullets & Projects</h3>
                <?php foreach($activeData['items'] as $item): ?>
                <div class="card nested-item">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_exp_item">
                        <input type="hidden" name="experience_id" value="<?= $activeData['id'] ?>">
                        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                        
                        <div style="display:flex; gap:10px;">
                            <div style="width:120px;">
                                <label>Type</label>
                                <select name="type">
                                    <option value="bullet" <?= $item['item_type']=='bullet'?'selected':'' ?>>Bullet Point</option>
                                    <option value="project" <?= $item['item_type']=='project'?'selected':'' ?>>Project</option>
                                </select>
                            </div>
                            <div style="flex:1;">
                                <label>Project Name (if Project)</label>
                                <input type="text" name="project_name" value="<?= htmlspecialchars($item['project_name'] ?? '') ?>" placeholder="Project Name">
                                <label>Content</label>
                                <textarea name="content" rows="2"><?= htmlspecialchars($item['content']) ?></textarea>
                            </div>
                            <div style="display:flex; flex-direction:column; justify-content:center; gap:5px;">
                                <button type="submit" style="height:40px;">Save</button>
                                <button type="submit" name="action" value="delete_item" form="del_item_<?= $item['id'] ?>_form" class="delete" style="height:40px;">Del</button>
                            </div>
                        </div>
                    </form>
                    <form method="POST" id="del_item_<?= $item['id'] ?>_form" onsubmit="return confirm('Delete item?');">
                        <input type="hidden" name="action" value="delete_item">
                        <input type="hidden" name="table" value="experience_items">
                        <input type="hidden" name="id" value="<?= $item['id'] ?>">
                    </form>
                </div>
                <?php endforeach; ?>

                <div class="card" style="border-top: 3px solid green;">
                    <h4>Add New Item</h4>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_exp_item">
                        <input type="hidden" name="experience_id" value="<?= $activeData['id'] ?>">
                         <div style="display:flex; gap:10px;">
                            <div style="width:120px;">
                                <select name="type">
                                    <option value="bullet">Bullet Point</option>
                                    <option value="project">Project</option>
                                </select>
                            </div>
                            <div style="flex:1;">
                                <input type="text" name="project_name" placeholder="Project Name (Optional)">
                                <textarea name="content" rows="2" placeholder="Description..."></textarea>
                            </div>
                            <button type="submit">Add</button>
                        </div>
                    </form>
                </div>

            <?php else: ?>
                <!-- List Jobs -->
                <div class="header-row">
                    <h2>Experience</h2>
                </div>
                <?php foreach($activeData as $job): ?>
                <div class="card">
                    <div style="display:flex; justify-content:space-between;">
                        <div>
                            <strong><?= htmlspecialchars($job['company']) ?></strong> - <?= htmlspecialchars($job['role']) ?>
                            <br><small><?= htmlspecialchars($job['date_range']) ?></small>
                        </div>
                        <div>
                            <a href="?tab=experience&edit_id=<?= $job['id'] ?>"><button class="secondary">Edit Details & Items</button></a>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete Job? This will delete all items too.');">
                                <input type="hidden" name="action" value="delete_item">
                                <input type="hidden" name="table" value="experience">
                                <input type="hidden" name="id" value="<?= $job['id'] ?>">
                                <button type="submit" class="delete">Delete</button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="card">
                    <h3>Add New Job</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_experience">
                        <input type="text" name="company" placeholder="Company">
                        <input type="text" name="role" placeholder="Role">
                        <input type="text" name="date_range" placeholder="Date Range">
                        <button type="submit">Add Job</button>
                    </form>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
