<?php
declare(strict_types=1);

// Prevent re-installation if already locked
$lock_file = __DIR__ . '/config/installed.lock';
if (file_exists($lock_file)) {
    die('<!DOCTYPE html><html><head><title>CMS Already Installed</title><link rel="stylesheet" href="/cms/assets/css/style.css"></head><body style="padding:40px; font-family:sans-serif; text-align:center;"><h2>Installation Locked</h2><p>This CMS instance is already installed and locked for security. To re-run setup, delete <code>config/installed.lock</code>.</p><p><a href="/cms/home" style="display:inline-block; padding:10px 20px; background:#0b2545; color:#fff; text-decoration:none; border-radius:4px;">Go to Site Home</a></p></body></html>');
}

$errors = [];
$success = false;

// 1. Environment & Prerequisite Checks
$env_checks = [
    'PHP Version >= 8.1'            => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO Extension Installed'       => extension_loaded('pdo'),
    'PDO MySQL Driver Available'    => extension_loaded('pdo_mysql'),
    'JSON Extension Enabled'        => extension_loaded('json'),
    'Config Directory Writable'     => is_writable(__DIR__ . '/config') || (!file_exists(__DIR__ . '/config') && is_writable(__DIR__)),
    'Uploads Directory Writable'    => is_writable(__DIR__ . '/uploads') || (!file_exists(__DIR__ . '/uploads') && is_writable(__DIR__)),
];

$can_install = !in_array(false, $env_checks, true);

// 2. Handle Installation POST Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_install) {
    $db_host = trim($_POST['db_host'] ?? '127.0.0.1');
    $db_port = (int)($_POST['db_port'] ?? 3306);
    $db_name = trim($_POST['db_name'] ?? '');
    $db_user = trim($_POST['db_user'] ?? '');
    $db_pass = (string)($_POST['db_pass'] ?? '');

    $site_name   = trim($_POST['site_name'] ?? 'Four Rivers Nuclear Partnership');
    $admin_user  = trim($_POST['admin_user'] ?? '');
    $admin_email = trim($_POST['admin_email'] ?? '');
    $admin_pass  = (string)($_POST['admin_pass'] ?? '');

    if ($db_name === '' || $db_user === '') {
        $errors[] = 'Database Name and Database Username are required.';
    }
    if ($admin_user === '' || $admin_email === '' || strlen($admin_pass) < 8) {
        $errors[] = 'Admin credentials required (password must be at least 8 characters).';
    } elseif (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please provide a valid administrator email address.';
    }

    if (empty($errors)) {
        try {
            // Test Database Connection
            $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
            $pdo = new PDO($dsn, $db_user, $db_pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            // Execute Full Schema DDL
            $pdo->exec("
                -- 1. Users & Roles
                CREATE TABLE IF NOT EXISTS `users` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `username` VARCHAR(64) NOT NULL UNIQUE,
                    `password` VARCHAR(255) NOT NULL,
                    `email` VARCHAR(254) NOT NULL UNIQUE,
                    `status` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB;

                CREATE TABLE IF NOT EXISTS `roles` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `name` VARCHAR(32) NOT NULL UNIQUE,
                    `description` VARCHAR(255) NULL
                ) ENGINE=InnoDB;

                CREATE TABLE IF NOT EXISTS `user_roles` (
                    `user_id` INT UNSIGNED NOT NULL,
                    `role_id` INT UNSIGNED NOT NULL,
                    PRIMARY KEY (`user_id`, `role_id`),
                    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB;

                CREATE TABLE IF NOT EXISTS `permissions` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `name` VARCHAR(64) NOT NULL UNIQUE,
                    `description` VARCHAR(255) NULL
                ) ENGINE=InnoDB;

                CREATE TABLE IF NOT EXISTS `role_permissions` (
                    `role_id` INT UNSIGNED NOT NULL,
                    `permission_id` INT UNSIGNED NOT NULL,
                    PRIMARY KEY (`role_id`, `permission_id`),
                    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
                    FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB;

                -- 2. Content Types & Field Schema
                CREATE TABLE IF NOT EXISTS `content_types` (
                    `type` VARCHAR(32) PRIMARY KEY,
                    `name` VARCHAR(128) NOT NULL,
                    `description` TEXT NULL,
                    `fields_schema` JSON NOT NULL,
                    `has_body` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB;

                -- 3. Content Nodes & Revisions
                CREATE TABLE IF NOT EXISTS `nodes` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `type` VARCHAR(32) NOT NULL,
                    `title` VARCHAR(255) NOT NULL,
                    `slug` VARCHAR(255) NOT NULL UNIQUE,
                    `body` MEDIUMTEXT NULL,
                    `keywords` TEXT NULL,
                    `fields_data` JSON NULL,
                    `uid` INT UNSIGNED NOT NULL DEFAULT 1,
                    `status` VARCHAR(20) NOT NULL DEFAULT 'published',
                    `publish_on` DATETIME NULL,
                    `unpublish_on` DATETIME NULL,
                    `template` VARCHAR(64) NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_node_type` (`type`),
                    INDEX `idx_node_status` (`status`)
                ) ENGINE=InnoDB;

                CREATE TABLE IF NOT EXISTS `node_revisions` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `node_id` INT UNSIGNED NOT NULL,
                    `uid` INT UNSIGNED NOT NULL,
                    `title` VARCHAR(255) NOT NULL,
                    `body` MEDIUMTEXT NULL,
                    `fields_data` JSON NULL,
                    `log_message` VARCHAR(255) NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (`node_id`) REFERENCES `nodes`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB;

                -- 4. Taxonomy & Terms
                CREATE TABLE IF NOT EXISTS `taxonomy_vocabularies` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `machine_name` VARCHAR(32) NOT NULL UNIQUE,
                    `name` VARCHAR(64) NOT NULL
                ) ENGINE=InnoDB;

                CREATE TABLE IF NOT EXISTS `taxonomy_terms` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `vid` INT UNSIGNED NOT NULL,
                    `name` VARCHAR(128) NOT NULL,
                    `slug` VARCHAR(128) NOT NULL,
                    UNIQUE KEY `uk_term_vid_slug` (`vid`, `slug`),
                    FOREIGN KEY (`vid`) REFERENCES `taxonomy_vocabularies`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB;

                CREATE TABLE IF NOT EXISTS `node_terms` (
                    `node_id` INT UNSIGNED NOT NULL,
                    `term_id` INT UNSIGNED NOT NULL,
                    PRIMARY KEY (`node_id`, `term_id`),
                    FOREIGN KEY (`node_id`) REFERENCES `nodes`(`id`) ON DELETE CASCADE,
                    FOREIGN KEY (`term_id`) REFERENCES `taxonomy_terms`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB;

                -- 5. Hierarchical Navigation Menus
                CREATE TABLE IF NOT EXISTS `menu_items` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `menu_name` VARCHAR(32) NOT NULL DEFAULT 'main',
                    `node_id` INT UNSIGNED NULL,
                    `title` VARCHAR(128) NOT NULL,
                    `url` VARCHAR(255) NOT NULL,
                    `weight` INT NOT NULL DEFAULT 0,
                    `parent_id` INT UNSIGNED NULL DEFAULT NULL,
                    INDEX `idx_menu_name` (`menu_name`),
                    INDEX `idx_parent_id` (`parent_id`)
                ) ENGINE=InnoDB;

                -- 6. Dynamic Views Engine
                CREATE TABLE IF NOT EXISTS `views` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `machine_name` VARCHAR(64) NOT NULL UNIQUE,
                    `title` VARCHAR(128) NOT NULL,
                    `path` VARCHAR(128) NOT NULL UNIQUE,
                    `content_type` VARCHAR(32) NOT NULL,
                    `layout_style` ENUM('teaser_list', 'grid_cards', 'data_table') NOT NULL DEFAULT 'teaser_list',
                    `items_per_page` TINYINT UNSIGNED NOT NULL DEFAULT 10,
                    `sort_field` VARCHAR(32) NOT NULL DEFAULT 'created_at',
                    `sort_order` ENUM('ASC', 'DESC') NOT NULL DEFAULT 'DESC',
                    `exposed_taxonomy` TINYINT(1) NOT NULL DEFAULT 1,
                    `exposed_search` TINYINT(1) NOT NULL DEFAULT 1,
                    `header_text` TEXT NULL,
                    `status` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB;

                -- 7. System Settings & Watchdog
                CREATE TABLE IF NOT EXISTS `site_settings` (
                    `setting_key` VARCHAR(64) PRIMARY KEY,
                    `setting_value` TEXT NULL,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB;

                CREATE TABLE IF NOT EXISTS `watchdog` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `type` VARCHAR(32) NOT NULL,
                    `message` TEXT NOT NULL,
                    `severity` VARCHAR(16) NOT NULL DEFAULT 'notice',
                    `uid` INT UNSIGNED NULL,
                    `ip` VARCHAR(45) NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB;

                -- 8. Mailer Queue
                CREATE TABLE IF NOT EXISTS `emails` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `recipient` VARCHAR(254) NOT NULL,
                    `subject` VARCHAR(255) NOT NULL,
                    `body_html` MEDIUMTEXT NOT NULL,
                    `body_text` MEDIUMTEXT NULL,
                    `sender_name` VARCHAR(100) NOT NULL DEFAULT 'FRNP Communications',
                    `status` ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
                    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    `error_message` TEXT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `sent_at` DATETIME NULL
                ) ENGINE=InnoDB;

                -- 9. Custom Function: Tour Reservations
                CREATE TABLE IF NOT EXISTS `tour_reservations` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `full_name` VARCHAR(120) NOT NULL,
                    `email` VARCHAR(254) NOT NULL,
                    `phone` VARCHAR(30) NOT NULL,
                    `organization` VARCHAR(150) NULL,
                    `attendees` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                    `tour_date` DATE NOT NULL,
                    `us_citizen` TINYINT(1) NOT NULL DEFAULT 1,
                    `special_accommodations` TEXT NULL,
                    `ip_address` VARCHAR(45) NOT NULL,
                    `status` ENUM('pending', 'confirmed', 'cancelled') NOT NULL DEFAULT 'pending',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB;
            ");

            // Seed Roles & Permissions
            $pdo->exec("
                INSERT IGNORE INTO `roles` (`id`, `name`, `description`) VALUES
                (1, 'administrator', 'Full platform and administrative system access.'),
                (2, 'editor', 'Can draft, update, and manage portal content.'),
                (3, 'viewer', 'Authenticated standard viewer.');

                INSERT IGNORE INTO `permissions` (`id`, `name`, `description`) VALUES
                (1, 'administer_site', 'Administer global site settings, modules, and database.'),
                (2, 'administer_content_types', 'Configure content schemas and field display layouts.'),
                (3, 'create_content', 'Create new pages, articles, and media attachments.'),
                (4, 'edit_any_content', 'Edit and revise all existing content nodes.');

                INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`) VALUES
                (1, 1), (1, 2), (1, 3), (1, 4),
                (2, 3), (2, 4);
            ");

            // Create Administrator User
            $hashed_pass = password_hash($admin_pass, PASSWORD_BCRYPT);
            $user_stmt = $pdo->prepare('INSERT INTO `users` (`username`, `password`, `email`, `status`) VALUES (?, ?, ?, 1)');
            $user_stmt->execute([$admin_user, $hashed_pass, $admin_email]);
            $admin_uid = (int)$pdo->lastInsertId();

            $pdo->prepare('INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES (?, 1)')->execute([$admin_uid]);

            // Seed Default Content Types
            $article_schema = json_encode([
                [
                    'name'          => 'subtitle',
                    'label'         => 'Article Subtitle',
                    'type'          => 'text',
                    'placement'     => 'header_meta',
                    'label_display' => 'hidden',
                    'weight'        => 0,
                    'required'      => 0
                ],
                [
                    'name'          => 'publication_date',
                    'label'         => 'Official Release Date',
                    'type'          => 'date',
                    'placement'     => 'header_meta',
                    'label_display' => 'inline',
                    'weight'        => 10,
                    'required'      => 0
                ],
                [
                    'name'          => 'document_attachment',
                    'label'         => 'Document PDF Attachment',
                    'type'          => 'file',
                    'placement'     => 'below_body',
                    'label_display' => 'inline',
                    'weight'        => 20,
                    'required'      => 0
                ]
            ]);

            $pdo->prepare("
                INSERT IGNORE INTO `content_types` (`type`, `name`, `description`, `fields_schema`, `has_body`) VALUES
                ('page', 'Basic Page', 'Standard corporate informational page with main menu integration.', '[]', 1),
                ('article', 'News & Updates', 'Official press releases, media statements, and project milestones.', ?, 1)
            ")->execute([$article_schema]);

            // Seed Taxonomy Vocabulary & Terms
            $pdo->exec("
                INSERT IGNORE INTO `taxonomy_vocabularies` (`id`, `machine_name`, `name`) VALUES
                (1, 'topics', 'Program Topics');

                INSERT IGNORE INTO `taxonomy_terms` (`vid`, `name`, `slug`) VALUES
                (1, 'Environmental Remediation', 'environmental-remediation'),
                (1, 'Groundwater Protection', 'groundwater-protection'),
                (1, 'Facility Deactivation', 'facility-deactivation'),
                (1, 'Community Outreach', 'community-outreach'),
                (1, 'Safety & Training', 'safety-training');
            ");

            // Seed Global Site Settings
            $settings_stmt = $pdo->prepare('INSERT INTO `site_settings` (`setting_key`, `setting_value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)');
            $settings = [
                'site_name'            => $site_name,
                'site_slogan'          => 'Paducah Deactivation Project',
                'sub_tagline'          => 'A Department of Energy Cleanup Contractor',
                'footer_copyright'     => "{$site_name}, LLC. All Rights Reserved.",
                'footer_disclaimer'    => "{$site_name} manages the Deactivation Project at the Paducah Gaseous Diffusion Plant for the U.S. Department of Energy.",
                'notify_contact_email' => $admin_email,
            ];
            foreach ($settings as $k => $v) {
                $settings_stmt->execute([$k, $v]);
            }

            // Seed Initial Starter Pages
            $node_stmt = $pdo->prepare("
                INSERT INTO `nodes` (`type`, `title`, `slug`, `body`, `keywords`, `fields_data`, `uid`, `status`)
                VALUES (?, ?, ?, ?, ?, '{}', ?, 'published')
            ");

            $node_stmt->execute([
                'page',
                'Welcome to the Paducah Deactivation Project',
                'home',
                '<p>Four Rivers Nuclear Partnership, LLC (FRNP) is proud to lead the safe, compliant deactivation and environmental remediation of the Paducah Gaseous Diffusion Plant for the U.S. Department of Energy (DOE).</p><p>Our dedicated workforce prioritizes safety, operational excellence, and environmental stewardship across western Kentucky.</p>',
                'home, paducah, environmental remediation, DOE, cleanup',
                $admin_uid
            ]);
            $home_id = (int)$pdo->lastInsertId();

            $node_stmt->execute([
                'page',
                'About FRNP Leadership & Operations',
                'about-us',
                '<p>Four Rivers Nuclear Partnership represents a world-class team of nuclear cleanup professionals dedicated to stabilizing infrastructure, remediating legacy facilities, and preparing the Paducah Site for future community missions.</p>',
                'about us, leadership, mission, stewardship',
                $admin_uid
            ]);
            $about_id = (int)$pdo->lastInsertId();

            $node_stmt->execute([
                'page',
                'Program Areas & Scope',
                'program-areas',
                '<p>FRNP manages key operational programs encompassing Groundwater Protection, Environmental Monitoring, Stabilization & Deactivation, and Waste Disposition.</p>',
                'programs, groundwater, deactivation, waste',
                $admin_uid
            ]);
            $prog_id = (int)$pdo->lastInsertId();

            $node_stmt->execute([
                'page',
                'Contact Communications',
                'contact',
                '<p>For public inquiries, media relations, or stakeholder briefings, please contact Four Rivers Nuclear Partnership Communications at 5511 Hobbs Road, Kevil, KY 42053.</p>',
                'contact, office, email, phone, media inquiries',
                $admin_uid
            ]);
            $contact_id = (int)$pdo->lastInsertId();

            // Seed Default Menu Items
            $menu_stmt = $pdo->prepare('INSERT INTO `menu_items` (`menu_name`, `node_id`, `title`, `url`, `weight`, `parent_id`) VALUES (?, ?, ?, ?, ?, ?)');
            $menu_stmt->execute(['main', $home_id, 'Home', '/cms/home', 0, null]);
            $menu_stmt->execute(['main', $about_id, 'About Us', '/cms/about-us', 10, null]);
            $parent_about_id = (int)$pdo->lastInsertId();

            $menu_stmt->execute(['main', $prog_id, 'Program Areas', '/cms/program-areas', 20, null]);
            $menu_stmt->execute(['main', null, 'News & Releases', '/cms/news', 30, null]);
            $menu_stmt->execute(['main', $contact_id, 'Contact', '/cms/contact', 40, null]);

            // Add Submenu Child Link
            $menu_stmt->execute(['main', null, 'Site Tours', '/cms/tours', 10, $parent_about_id]);

            // Seed Default Views
            $pdo->exec("
                INSERT IGNORE INTO `views` (`machine_name`, `title`, `path`, `content_type`, `layout_style`, `items_per_page`, `sort_field`, `sort_order`, `exposed_taxonomy`, `exposed_search`, `header_text`) VALUES
                ('document_repository', 'Cleanup Documents & Reports', 'documents', 'article', 'data_table', 15, 'created_at', 'DESC', 1, 1, '<p>Search and download official Paducah Site environmental reports and compliance notices.</p>'),
                ('safety_bulletins', 'Safety & Operations Bulletins', 'bulletins', 'page', 'grid_cards', 9, 'created_at', 'DESC', 1, 0, '<p>Operational bulletins, site alerts, and environmental safety protocols.</p>');
            ");

            // 5. Write `config/database.php`
            $config_dir = __DIR__ . '/config';
            if (!is_dir($config_dir)) {
                mkdir($config_dir, 0755, true);
            }

            $db_config_code = "<?php\ndeclare(strict_types=1);\n\n/**\n * Auto-generated Database Configuration\n */\ndefine('DB_HOST', " . var_export($db_host, true) . ");\ndefine('DB_PORT', " . (int)$db_port . ");\ndefine('DB_NAME', " . var_export($db_name, true) . ");\ndefine('DB_USER', " . var_export($db_user, true) . ");\ndefine('DB_PASS', " . var_export($db_pass, true) . ");\n\nfunction get_db(): \\PDO {\n    static \$pdo = null;\n    if (\$pdo === null) {\n        \$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);\n        \$pdo = new \\PDO(\$dsn, DB_USER, DB_PASS, [\n            \\PDO::ATTR_ERRMODE            => \\PDO::ERRMODE_EXCEPTION,\n            \\PDO::ATTR_DEFAULT_FETCH_MODE => \\PDO::FETCH_ASSOC,\n            \\PDO::ATTR_EMULATE_PREPARES   => false,\n        ]);\n    }\n    return \$pdo;\n}\n";
            file_put_contents($config_dir . '/database.php', $db_config_code);

            // Ensure Uploads Subdirectories Exist
            $upload_dirs = [
                __DIR__ . '/uploads',
                __DIR__ . '/uploads/files',
                __DIR__ . '/custom_pages'
            ];
            foreach ($upload_dirs as $d) {
                if (!is_dir($d)) {
                    mkdir($d, 0755, true);
                }
            }

            // 6. Write Lock File
            file_put_contents($lock_file, "Installed on " . date('Y-m-d H:i:s') . " by UID {$admin_uid}\n");

            $success = true;
        } catch (\Throwable $e) {
            $errors[] = 'Database setup failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CMS Platform Installation - Four Rivers Nuclear Partnership</title>
    <style>
        :root {
            --frnp-navy: #0b2545;
            --frnp-navy-dark: #07172c;
            --frnp-gold: #c59b27;
            --frnp-bg: #f8fafc;
            --frnp-border: #cbd5e1;
            --frnp-text: #1e293b;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            background: var(--frnp-bg);
            color: var(--frnp-text);
            line-height: 1.5;
            padding-bottom: 60px;
        }
        .header {
            background: var(--frnp-navy);
            color: #fff;
            padding: 24px 20px;
            border-bottom: 4px solid var(--frnp-gold);
            text-align: center;
        }
        .header h1 { font-size: 1.6rem; letter-spacing: 0.5px; }
        .header p { color: #94a3b8; font-size: 0.9rem; margin-top: 4px; }
        .container {
            max-width: 820px;
            margin: 30px auto 0;
            background: #ffffff;
            border: 1px solid var(--frnp-border);
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            padding: 35px 40px;
        }
        .alert {
            padding: 14px 18px;
            border-radius: 6px;
            margin-bottom: 25px;
            font-size: 0.92rem;
        }
        .alert-danger { background: #fef2f2; border-left: 4px solid #b91c1c; color: #991b1b; }
        .alert-success { background: #ecfdf5; border-left: 4px solid #059669; color: #065f46; }
        .env-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
            font-size: 0.88rem;
        }
        .env-table td { padding: 8px 12px; border-bottom: 1px solid #f1f5f9; }
        .status-pass { color: #059669; font-weight: 700; text-align: right; }
        .status-fail { color: #dc2626; font-weight: 700; text-align: right; }
        .section-title {
            color: var(--frnp-navy);
            font-size: 1.15rem;
            margin: 25px 0 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #f1f5f9;
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-bottom: 15px;
        }
        .form-row { margin-bottom: 15px; }
        .form-row label {
            display: block;
            font-size: 0.86rem;
            font-weight: 600;
            color: #334155;
            margin-bottom: 5px;
        }
        .form-row input {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid var(--frnp-border);
            border-radius: 4px;
            font-size: 0.9rem;
            background: #fff;
        }
        .form-row input:focus {
            outline: none;
            border-color: var(--frnp-navy);
            box-shadow: 0 0 0 2px rgba(11, 37, 69, 0.15);
        }
        .btn-install {
            background: var(--frnp-navy);
            color: #fff;
            padding: 12px 28px;
            font-size: 1rem;
            font-weight: 600;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
            margin-top: 20px;
            transition: background 0.15s ease;
        }
        .btn-install:hover { background: var(--frnp-gold); }
    </style>
</head>
<body>
    <div class="header">
        <h1>FOUR RIVERS NUCLEAR PARTNERSHIP</h1>
        <p>CMS Platform Deployment & Database Provisioning Wizard</p>
    </div>

    <div class="container">
        <?php if ($success): ?>
            <div class="alert alert-success">
                <h3 style="margin-bottom:8px; font-size:1.1rem;">✓ Installation Completed Successfully!</h3>
                <p>The database tables have been provisioned, default content types and views seeded, and <code>config/database.php</code> has been generated.</p>
                <p style="margin-top:8px;">The installation lock (<code>config/installed.lock</code>) has been written to prevent duplicate setup executions.</p>
            </div>

            <div style="text-align: center; margin-top: 30px; display:flex; gap:15px; justify-content:center;">
                <a href="/cms/admin/login.php" style="background:#0b2545; color:#fff; text-decoration:none; padding:12px 24px; border-radius:4px; font-weight:600;">Log In to Dashboard</a>
                <a href="/cms/home" style="background:#f1f5f9; color:#0b2545; border:1px solid #cbd5e1; text-decoration:none; padding:12px 24px; border-radius:4px; font-weight:600;">View Published Site</a>
            </div>
        <?php else: ?>
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $e): ?>
                        <div>&bull; <?= htmlspecialchars($e) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Pre-flight Environment Check -->
            <h3 class="section-title">1. Server Environment Verification</h3>
            <table class="env-table">
                <tbody>
                    <?php foreach ($env_checks as $check => $passed): ?>
                        <tr>
                            <td><?= htmlspecialchars($check) ?></td>
                            <td class="<?= $passed ? 'status-pass' : 'status-fail' ?>">
                                <?= $passed ? 'PASS ✓' : 'FAIL ✗' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (!$can_install): ?>
                <div class="alert alert-danger">
                    Please correct the failed environment requirements above (e.g. directory permissions on <code>/config</code> and <code>/uploads</code>) before proceeding.
                </div>
            <?php else: ?>
                <form method="POST">
                    <!-- Blank Database Credentials -->
                    <h3 class="section-title">2. Database Credentials (Blank Database)</h3>
                    <div class="form-grid">
                        <div class="form-row">
                            <label>MySQL Host *</label>
                            <input type="text" name="db_host" value="127.0.0.1" required>
                        </div>
                        <div class="form-row">
                            <label>Port</label>
                            <input type="number" name="db_port" value="3306" required>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-row">
                            <label>Database Name *</label>
                            <input type="text" name="db_name" placeholder="frnp_cms" required autofocus>
                        </div>
                        <div class="form-row">
                            <label>Database Username *</label>
                            <input type="text" name="db_user" placeholder="root or db_user" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <label>Database Password</label>
                        <input type="password" name="db_pass" placeholder="Leave blank if no password">
                    </div>

                    <!-- Platform & Admin Setup -->
                    <h3 class="section-title">3. Site Name & Administrator Account</h3>
                    <div class="form-row">
                        <label>Site / Organization Name *</label>
                        <input type="text" name="site_name" value="Four Rivers Nuclear Partnership" required>
                    </div>
                    <div class="form-grid">
                        <div class="form-row">
                            <label>Admin Username *</label>
                            <input type="text" name="admin_user" value="admin" required>
                        </div>
                        <div class="form-row">
                            <label>Admin Email *</label>
                            <input type="email" name="admin_email" placeholder="communications@fourriversnuclearpartnership.com" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <label>Admin Password (8+ characters) *</label>
                        <input type="password" name="admin_pass" minlength="8" required>
                    </div>

                    <button type="submit" class="btn-install">Initialize Database & Install Platform</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>