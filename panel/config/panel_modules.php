<?php
declare(strict_types=1);

/**
 * Panel navigation — mirrors cPanel's section layout 1:1 (docs/09-cpanel-parity-checklist.md).
 * Every tile carries the roadmap step that will build it, so the UI doubles as
 * living progress. `live` = true means it works today.
 */
return [
    'sections' => [
        [
            'key' => 'files', 'name' => 'Files', 'icon' => 'folder',
            'tiles' => [
                ['File Manager', 'S6'], ['Images', 'S6'], ['Directory Privacy', 'S6'],
                ['Disk Usage', 'S6'], ['Web Disk', 'S6'], ['FTP Accounts', 'S6'],
                ['FTP Connections', 'S6'], ['Git Version Control', 'S6'], ['Trash', 'S6'],
                ['Backup', 'S10'], ['Backup Wizard', 'S10'], ['File Restoration', 'S10'],
            ],
        ],
        [
            'key' => 'email', 'name' => 'Email', 'icon' => 'mail',
            'tiles' => [
                ['Email Accounts', 'S7'], ['Forwarders', 'S7'], ['Email Routing', 'S7'],
                ['Autoresponders', 'S7'], ['Default Address', 'S7'], ['Mailing Lists', 'S7'],
                ['Track Delivery', 'S7'], ['Global Email Filters', 'S7'], ['Email Filters', 'S7'],
                ['Email Deliverability', 'S7'], ['Address Importer', 'S7'], ['Spam Filters', 'S7'],
                ['Encryption', 'S7'], ['BoxTrapper', 'S7'], ['Calendar & Contacts', 'S7'],
                ['Email Disk Usage', 'S7'], ['Webmail', 'S7'],
            ],
        ],
        [
            'key' => 'domains', 'name' => 'Domains', 'icon' => 'globe',
            'tiles' => [
                ['Domains', 'S5'], ['Subdomains', 'S5'], ['Addon Domains', 'S5'],
                ['Aliases', 'S5'], ['Redirects', 'S5'], ['Zone Editor', 'S9'], ['Dynamic DNS', 'S9'],
            ],
        ],
        [
            'key' => 'databases', 'name' => 'Databases', 'icon' => 'database',
            'tiles' => [
                ['MySQL Databases', 'S8'], ['MySQL Database Wizard', 'S8'],
                ['phpMyAdmin', 'S8'], ['Remote MySQL', 'S8'],
            ],
        ],
        [
            'key' => 'metrics', 'name' => 'Metrics', 'icon' => 'chart',
            'tiles' => [
                ['Visitors', 'S11'], ['Errors', 'S11'], ['Bandwidth', 'S11'],
                ['Raw Access', 'S11'], ['Awstats', 'S11'], ['Webalizer', 'S11'],
                ['Resource Usage', 'S11'], ['Site Quality Monitoring', 'S11'],
            ],
        ],
        [
            'key' => 'security', 'name' => 'Security', 'icon' => 'shield',
            'tiles' => [
                ['SSH Access', 'S6'], ['IP Blocker', 'S13'], ['SSL/TLS', 'S5'],
                ['SSL/TLS Status', 'S5'], ['Two-Factor Authentication', 'S2B', 'live' => true],
                ['Password & Security', 'S2B', 'live' => true], ['Leech Protection', 'S13'],
                ['ModSecurity', 'S13'], ['Security Policy', 'S13'],
            ],
        ],
        [
            'key' => 'software', 'name' => 'Software', 'icon' => 'box',
            'tiles' => [
                ['App Installer (Softaculous-style)', 'S14'], ['WordPress Toolkit', 'S14'],
                ['WP Guardian', 'S14'], ['Node.js Selector', 'S14'], ['Optimize Website', 'S14'],
                ['MultiPHP Manager', 'S5'], ['MultiPHP INI Editor', 'S5'], ['PHP Composer', 'S14'],
            ],
        ],
        [
            'key' => 'advanced', 'name' => 'Advanced', 'icon' => 'sliders',
            'tiles' => [
                ['Cron Jobs', 'S5'], ['Track DNS', 'S9'], ['Indexes', 'S5'], ['Error Pages', 'S5'],
                ['MIME Types', 'S5'], ['Apache Handlers', 'S5'], ['Network Tools', 'S11'],
                ['Terminal', 'S6'], ['Hotlink Protection', 'S13'], ['Site IP Address', 'S3'],
            ],
        ],
        [
            'key' => 'preferences', 'name' => 'Preferences', 'icon' => 'user',
            'tiles' => [
                ['Getting Started', 'S2B', 'live' => true], ['User Manager', 'S2B'],
                ['Contact Information', 'S2B', 'live' => true], ['Change Password', 'S2B', 'live' => true],
                ['Change Language', 'S2B'], ['Change Style', 'S2B'], ['Shortcuts', 'S2B'],
            ],
        ],
    ],
];
