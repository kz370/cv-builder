<?php
$config = require 'config.php';
$pdo = new PDO("mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}", $config['username'], $config['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Fetch Data
$meta = [];
foreach($pdo->query("SELECT * FROM meta") as $row) $meta[$row['key']] = $row['value'];

// Determine Section Order
$defaultOrder = ['profile', 'skills', 'experience', 'packages', 'education', 'certifications', 'languages'];
$order = $defaultOrder;
if (!empty($meta['section_order'])) {
    $order = json_decode($meta['section_order'], true);
}

// Fetch Section Content
function getTable($pdo, $table) {
    try { return $pdo->query("SELECT * FROM `$table` ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) { return []; }
}
$skills = getTable($pdo, 'skills');
$experience = getTable($pdo, 'experience');
$experience_items = getTable($pdo, 'experience_items');
$packages = getTable($pdo, 'packages');
$education = getTable($pdo, 'education');
$certifications = getTable($pdo, 'certifications');
$languages = getTable($pdo, 'languages');
$custom_sections = getTable($pdo, 'custom_sections');

$isPreview = isset($_GET['preview']) && $_GET['preview'] == '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($meta['header_name'] ?? 'CV'); ?></title>
    <link rel="stylesheet" href="style.css">
    
    <?php if ($isPreview): ?>
    <style>
        /* Highlight updated elements momentarily */
        .highlight-update { animation: flashHighlight 1s ease-out; }
        @keyframes flashHighlight { 0% { background-color: #fef08a; } 100% { background-color: transparent; } }
    </style>
    <?php endif; ?>
</head>
<body>

    <!-- Floating Actions -->
    <div class="no-print">
        <?php if (!$isPreview): ?>
        <a href="admin.php" target="_blank" class="btn-floating btn-secondary" title="Edit CV">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                <path d="M12.854.146a.5.5 0 0 0-.707 0L10.5 1.793 14.207 5.5l1.647-1.646a.5.5 0 0 0 0-.708l-3-3zm.646 6.061L9.793 2.5 3.293 9H3.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.207l6.5-6.5zm-7.468 7.468A.5.5 0 0 1 6 13.5V13h-.5a.5.5 0 0 1-.5-.5V12h-.5a.5.5 0 0 1-.5-.5V11h-.5a.5.5 0 0 1-.5-.5V10h-.5a.499.499 0 0 1-.175-.032l-.179.178a.5.5 0 0 0-.11.168l-2 5a.5.5 0 0 0 .65.65l5-2a.5.5 0 0 0 .168-.11l.178-.178z"/>
            </svg>
            Edit
        </a>
        <?php endif; ?>
        
        <button onclick="window.print()" class="btn-floating" title="Print / Save PDF">
             <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                <path d="M2.5 8a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1z"/>
                <path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2H5zM4 3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2H4V3zm1 5a2 2 0 0 0-2 2v1H2a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v-1a2 2 0 0 0-2-2H5zm7 2v3a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1z"/>
            </svg>
            Print
        </button>
    </div>

    <div class="cv-container">
        
        <!-- Header -->
        <header class="header">
            <h1 id="cv-name"><?php echo htmlspecialchars($meta['header_name'] ?? 'Your Name'); ?></h1>
            <div class="role" id="cv-role"><?php echo htmlspecialchars($meta['header_role'] ?? 'Professional Role'); ?></div>
            
            <div class="contact-info">
                <?php if (!empty($meta['header_location'])): ?>
                    <span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                        <span id="cv-location"><?php echo htmlspecialchars($meta['header_location']); ?></span>
                    </span>
                    |
                <?php endif; ?>
                
                <?php if (!empty($meta['header_phone'])): ?>
                    <span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                        <span id="cv-phone"><?php echo htmlspecialchars($meta['header_phone']); ?></span>
                    </span>
                    |
                <?php endif; ?>
                
                <?php if (!empty($meta['header_email'])): ?>
                    <span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                        <span id="cv-email"><a href="mailto:<?php echo htmlspecialchars($meta['header_email']); ?>"><?php echo htmlspecialchars($meta['header_email']); ?></a></span>
                    </span>
                <?php endif; ?>

                <?php if (!empty($meta['header_linkedin'])): ?>
                    <br>
                    <span>
                       <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"></path><rect x="2" y="9" width="4" height="12"></rect><circle cx="4" cy="4" r="2"></circle></svg>
                       <span id="cv-linkedin"><a href="https://<?php echo htmlspecialchars($meta['header_linkedin']); ?>" target="_blank"><?php echo htmlspecialchars($meta['header_linkedin']); ?></a></span>
                    </span>
                <?php endif; ?>

                <?php 
                $headerExtras = getTable($pdo, 'header_extras');
                foreach($headerExtras as $extra):
                    $val = htmlspecialchars($extra['value']);
                    if (!empty($extra['url'])) {
                        $val = '<a href="'.htmlspecialchars($extra['url']).'" target="_blank">'.$val.'</a>';
                    }
                ?>
                    <br class="mobile-break">
                    <span class="header-extra-item">
                        <?php if(!empty($extra['label'])): ?>
                            <strong><?= htmlspecialchars($extra['label']) ?>:</strong> 
                        <?php endif; ?>
                        <?= $val ?>
                    </span>
                <?php endforeach; ?>
            </div>
            <hr>
        </header>

        <!-- Dynamic Sections -->
        <?php 
        // Ensure orphaned custom sections appear
        foreach($custom_sections as $cs) {
            $key = 'custom_' . $cs['id'];
            if(!in_array($key, $order)) {
                $order[] = $key;
            }
        }

        foreach ($order as $sectionKey):
            // Check custom sections first
            if (strpos($sectionKey, 'custom_') === 0) {
                $cId = substr($sectionKey, 7);
                $cSection = null;
                foreach ($custom_sections as $cs) { if ($cs['id'] == $cId) { $cSection = $cs; break; } }
                
                if ($cSection): ?>
                   <section data-section="custom-<?php echo $cSection['id']; ?>">
                       <div class="section-title" data-id="custom-title-<?php echo $cSection['id']; ?>"><?php echo htmlspecialchars($cSection['title']); ?></div>
                       <div class="content" data-id="custom-content-<?php echo $cSection['id']; ?>">
                           <?php echo nl2br(htmlspecialchars($cSection['content'])); ?>
                       </div>
                   </section>
                   <hr>
                <?php endif;
                continue;
            }

            switch ($sectionKey) {
                case 'profile':
                    if (!empty($meta['profile_summary'])): ?>
                    <section data-section="profile">
                        <div class="section-title">PROFILE</div>
                        <div class="content">
                            <p id="cv-profile-summary"><?php echo nl2br(htmlspecialchars($meta['profile_summary'])); ?></p>
                        </div>
                    </section>
                    <hr>
                    <?php endif;
                    break;

                case 'skills':
                    if (!empty($skills)): ?>
                    <section data-section="skills">
                        <div class="section-title">TECHNICAL SKILLS</div>
                        <div class="content skills-list">
                            <?php foreach ($skills as $skill): ?>
                                <div class="skill-item">
                                    <span class="skills-label" data-id="skill-category-<?php echo $skill['id']; ?>"><?php echo htmlspecialchars($skill['category']); ?>:</span>
                                    <span data-id="skill-content-<?php echo $skill['id']; ?>"><?php echo htmlspecialchars($skill['content']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <hr>
                    <?php endif;
                    break;

                case 'experience':
                    if (!empty($experience)): ?>
                    <section data-section="experience">
                        <div class="section-title">PROFESSIONAL EXPERIENCE</div>
                        <?php foreach ($experience as $job): ?>
                            <div class="job-entry">
                                <div class="job-top-row">
                                    <span class="job-role" data-id="exp-role-<?php echo $job['id']; ?>"><?php echo htmlspecialchars($job['role']); ?></span>
                                </div>
                                <div class="job-sub-row">
                                    <span class="company-info">
                                        <span data-id="exp-company-<?php echo $job['id']; ?>"><?php echo htmlspecialchars($job['company']); ?></span>
                                        <?php if(!empty($job['location'])): ?> | <span data-id="exp-location-<?php echo $job['id']; ?>"><?php echo htmlspecialchars($job['location']); ?></span><?php endif; ?>
                                    </span>
                                    <span class="job-date" data-id="exp-date-<?php echo $job['id']; ?>"><?php echo htmlspecialchars($job['date_range']); ?></span>
                                </div>
                                
                                <?php 
                                $jobItems = array_filter($experience_items, function($i) use ($job) { return $i['experience_id'] == $job['id']; }); 
                                ?>
                                <?php if (!empty($jobItems)): ?>
                                    <ul>
                                        <?php foreach ($jobItems as $item): ?>
                                            <?php if ($item['item_type'] == 'project'): ?>
                                                <div class="project-block">
                                                    <span class="project-name" data-id="exp-item-project-<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['project_name']); ?></span>
                                                    <li data-id="exp-item-content-<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['content']); ?></li>
                                                </div>
                                            <?php else: ?>
                                                <li data-id="exp-item-content-<?php echo $item['id']; ?>"><?php echo htmlspecialchars($item['content']); ?></li>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </section>
                    <hr>
                    <?php endif;
                    break;

                case 'packages':
                    if (!empty($packages)): ?>
                    <section data-section="packages">
                        <div class="section-title">PROJECTS</div>
                         <div class="content">
                            <ul style="list-style: none; margin-left: 0;">
                                <?php foreach ($packages as $pkg): ?>
                                    <li style="margin-bottom: 6px;">
                                        <a href="<?php echo htmlspecialchars($pkg['url']); ?>" target="_blank" style="font-weight:700; color:var(--text-color); text-decoration:none;" data-id="pkg-name-<?php echo $pkg['id']; ?>"><?php echo htmlspecialchars($pkg['name']); ?></a>
                                        <span style="font-weight: 500; color: #555;"> | <?php echo htmlspecialchars($pkg['type'] ?? 'Open Source'); ?></span>
                                        <span style="color:#555;"> - <span data-id="pkg-desc-<?php echo $pkg['id']; ?>"><?php echo htmlspecialchars($pkg['description']); ?></span></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </section>
                    <hr>
                    <?php endif;
                    break;

                case 'education':
                    if (!empty($education)): ?>
                   <section data-section="education">
                       <div class="section-title">EDUCATION</div>
                       <?php foreach ($education as $edu): ?>
                           <div class="job-entry">
                               <div class="job-top-row">
                                   <span class="job-role" data-id="edu-degree-<?php echo $edu['id']; ?>"><?php echo htmlspecialchars($edu['degree']); ?></span>
                                   <span class="job-date" data-id="edu-date-<?php echo $edu['id']; ?>"><?php echo htmlspecialchars($edu['date_range']); ?></span>
                               </div>
                               <div class="job-sub-row" data-id="edu-institution-<?php echo $edu['id']; ?>"><?php echo htmlspecialchars($edu['institution']); ?></div>
                           </div>
                       <?php endforeach; ?>
                   </section>
                   <hr>
                   <?php endif;
                   break;

                case 'certifications':
                    if (!empty($certifications)): ?>
                    <section data-section="certifications">
                        <div class="section-title">CERTIFICATIONS</div>
                         <ul>
                            <?php foreach ($certifications as $cert): ?>
                                <li data-id="cert-name-<?php echo $cert['id']; ?>"><?php echo htmlspecialchars($cert['name']); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                    <hr>
                    <?php endif;
                    break;
                
                case 'languages':
                   if (!empty($languages)): ?>
                        <section data-section="languages">
                            <div class="section-title">LANGUAGES</div>
                             <div class="skills-list"> 
                                <?php foreach ($languages as $lang): ?>
                                    <div><strong data-id="lang-name-<?php echo $lang['id']; ?>"><?php echo htmlspecialchars($lang['language']); ?></strong>: <span data-id="lang-proficiency-<?php echo $lang['id']; ?>"><?php echo htmlspecialchars($lang['proficiency']); ?></span></div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                        <hr>
                   <?php endif;
                   break;
            }
        endforeach;
        ?>
    </div>
</body>
</html>
