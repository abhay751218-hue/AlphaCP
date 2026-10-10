<?php
declare(strict_types=1);

/**
 * Panel navigation — mirrors cPanel's section layout 1:1 (docs/09-cpanel-parity-checklist.md).
 * Every tile carries the roadmap step that will build it, so the UI doubles as
 * living progress. `live` = true means it works today.
 *
 * Theme data (Paper Lantern style, cPanel #FF6C2C accent):
 *  - `color` per section  → drives sidebar icon + section header + tile icon tint.
 *  - `icon`  per tile     → unique stroke icon per tool (falls back to section icon).
 * Icon names must exist in resources/views/partials/icons.blade.php (checked by
 * tools/sim/theme-check.py).
 */
return [
    'sections' => [
        [
            'key' => 'files', 'name' => 'Files', 'icon' => 'folder', 'color' => '#3b82f6',
            'tiles' => [
                ['File Manager', 'S6', 'icon' => 'folder'],
                ['Images', 'S6', 'icon' => 'image'],
                ['Directory Privacy', 'S6', 'icon' => 'lock'],
                ['Disk Usage', 'S6', 'icon' => 'hdd'],
                ['Web Disk', 'S6', 'icon' => 'cloud'],
                ['FTP Accounts', 'S6', 'icon' => 'users'],
                ['FTP Connections', 'S6', 'icon' => 'list'],
                ['Git Version Control', 'S6', 'icon' => 'git'],
                ['Trash', 'S6', 'icon' => 'trash'],
                ['Backup', 'S10', 'icon' => 'archive'],
                ['Backup Wizard', 'S10', 'icon' => 'star'],
                ['File Restoration', 'S10', 'icon' => 'refresh'],
            ],
        ],
        [
            'key' => 'email', 'name' => 'Email', 'icon' => 'mail', 'color' => '#ff6c2c',
            'tiles' => [
                ['Email Accounts', 'S7', 'icon' => 'mail'],
                ['Forwarders', 'S7', 'icon' => 'send'],
                ['Email Routing', 'S7', 'icon' => 'globe'],
                ['Autoresponders', 'S7', 'icon' => 'refresh'],
                ['Default Address', 'S7', 'icon' => 'inbox'],
                ['Mailing Lists', 'S7', 'icon' => 'users'],
                ['Track Delivery', 'S7', 'icon' => 'gauge'],
                ['Global Email Filters', 'S7', 'icon' => 'filter'],
                ['Email Filters', 'S7', 'icon' => 'filter'],
                ['Email Deliverability', 'S7', 'icon' => 'check'],
                ['Address Importer', 'S7', 'icon' => 'download'],
                ['Spam Filters', 'S7', 'icon' => 'shield'],
                ['Encryption', 'S7', 'icon' => 'key'],
                ['BoxTrapper', 'S7', 'icon' => 'box'],
                ['Calendar & Contacts', 'S7', 'icon' => 'calendar'],
                ['Email Disk Usage', 'S7', 'icon' => 'hdd'],
                ['Webmail', 'S7', 'icon' => 'mail'],
            ],
        ],
        [
            'key' => 'domains', 'name' => 'Domains', 'icon' => 'globe', 'color' => '#22c55e',
            'tiles' => [
                ['Domains', 'S5', 'icon' => 'globe'],
                ['Subdomains', 'S5', 'icon' => 'link'],
                ['Addon Domains', 'S5', 'icon' => 'plus'],
                ['Aliases', 'S5', 'icon' => 'copy'],
                ['Redirects', 'S5', 'icon' => 'refresh'],
                ['Zone Editor', 'S9', 'icon' => 'file'],
                ['Dynamic DNS', 'S9', 'icon' => 'cloud'],
            ],
        ],
        [
            'key' => 'databases', 'name' => 'Databases', 'icon' => 'database', 'color' => '#8b5cf6',
            'tiles' => [
                ['MySQL Databases', 'S8', 'icon' => 'database'],
                ['MySQL Database Wizard', 'S8', 'icon' => 'star'],
                ['phpMyAdmin', 'S8', 'icon' => 'globe'],
                ['Remote MySQL', 'S8', 'icon' => 'server'],
            ],
        ],
        [
            'key' => 'metrics', 'name' => 'Metrics', 'icon' => 'chart', 'color' => '#14b8a6',
            'tiles' => [
                ['Visitors', 'S11', 'icon' => 'users'],
                ['Errors', 'S11', 'icon' => 'alert'],
                ['Bandwidth', 'S11', 'icon' => 'chart'],
                ['Raw Access', 'S11', 'icon' => 'file'],
                ['Awstats', 'S11', 'icon' => 'chart'],
                ['Webalizer', 'S11', 'icon' => 'chart'],
                ['Resource Usage', 'S11', 'icon' => 'gauge'],
                ['Site Quality Monitoring', 'S11', 'icon' => 'star'],
            ],
        ],
        [
            'key' => 'security', 'name' => 'Security', 'icon' => 'shield', 'color' => '#ef4444',
            'tiles' => [
                ['SSH Access', 'S6', 'icon' => 'terminal'],
                ['IP Blocker', 'S13', 'icon' => 'shield'],
                ['SSL/TLS', 'S5', 'icon' => 'lock'],
                ['SSL/TLS Status', 'S5', 'icon' => 'check'],
                ['Two-Factor Authentication', 'S2B', 'icon' => 'key', 'live' => true],
                ['Password & Security', 'S2B', 'icon' => 'lock', 'live' => true],
                ['Leech Protection', 'S13', 'icon' => 'shield'],
                ['ModSecurity', 'S13', 'icon' => 'shield-check'],
                ['Security Policy', 'S13', 'icon' => 'file'],
            ],
        ],
        [
            'key' => 'software', 'name' => 'Software', 'icon' => 'box', 'color' => '#f59e0b',
            'tiles' => [
                ['App Installer (Softaculous-style)', 'S14', 'icon' => 'box'],
                ['WordPress Toolkit', 'S14', 'icon' => 'globe'],
                ['WP Guardian', 'S14', 'icon' => 'shield-check'],
                ['Node.js Selector', 'S14', 'icon' => 'cog'],
                ['Optimize Website', 'S14', 'icon' => 'gauge'],
                ['MultiPHP Manager', 'S5', 'icon' => 'cog'],
                ['MultiPHP INI Editor', 'S5', 'icon' => 'file'],
                ['PHP Composer', 'S14', 'icon' => 'terminal'],
            ],
        ],
        [
            'key' => 'advanced', 'name' => 'Advanced', 'icon' => 'sliders', 'color' => '#64748b',
            'tiles' => [
                ['Cron Jobs', 'S5', 'icon' => 'clock'],
                ['Track DNS', 'S9', 'icon' => 'search'],
                ['Indexes', 'S5', 'icon' => 'list'],
                ['Error Pages', 'S5', 'icon' => 'alert'],
                ['MIME Types', 'S5', 'icon' => 'file'],
                ['Apache Handlers', 'S5', 'icon' => 'cog'],
                ['Network Tools', 'S11', 'icon' => 'globe'],
                ['Terminal', 'S6', 'icon' => 'terminal'],
                ['Hotlink Protection', 'S13', 'icon' => 'shield'],
                ['Site IP Address', 'S3', 'icon' => 'server'],
            ],
        ],
        [
            'key' => 'preferences', 'name' => 'Preferences', 'icon' => 'user', 'color' => '#6366f1',
            'tiles' => [
                ['Getting Started', 'S2B', 'icon' => 'star', 'live' => true],
                ['User Manager', 'S2B', 'icon' => 'users'],
                ['Contact Information', 'S2B', 'icon' => 'mail', 'live' => true],
                ['Change Password', 'S2B', 'icon' => 'key', 'live' => true],
                ['Change Language', 'S2B', 'icon' => 'globe'],
                ['Change Style', 'S2B', 'icon' => 'image'],
                ['Shortcuts', 'S2B', 'icon' => 'link'],
            ],
        ],
    ],
];
