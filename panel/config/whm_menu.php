<?php
declare(strict_types=1);

/**
 * WHM sidebar — mirrors the real WHM category layout (docs/09-cpanel-parity-checklist.md,
 * rows 94–182). Two flavours:
 *   · admin    → full WHM (superadmin/admin)
 *   · reseller → reseller-scoped subset (like reseller WHM in cPanel companies)
 * Every item carries its parity row (#) and roadmap step so the menu doubles as
 * living progress, exactly like config/panel_modules.php for the client panel.
 * `live` = true means it works today. `route` = real route; otherwise the item
 * lands on the shared coming-soon page.
 * Icon names must exist in resources/views/partials/icons.blade.php
 * (enforced by tools/sim/theme-check.py).
 */
return [
    'admin' => [
        [
            'key' => 'main', 'name' => 'Main', 'icon' => 'home',
            'items' => [
                ['name' => 'Home', 'route' => 'admin.dashboard', 'icon' => 'home', 'live' => true],
                ['name' => 'Server Status', 'step' => 'S11', 'parity' => 182, 'icon' => 'gauge'],
            ],
        ],
        [
            'key' => 'account-functions', 'name' => 'Account Functions', 'icon' => 'users',
            'items' => [
                ['name' => 'Create a New Account', 'step' => 'S3', 'parity' => 104, 'icon' => 'plus'],
                ['name' => 'List Accounts', 'step' => 'S3', 'parity' => 105, 'icon' => 'list', 'live' => true],
                ['name' => 'Suspend / Unsuspend', 'step' => 'S3', 'parity' => 107, 'icon' => 'lock'],
                ['name' => 'Terminate Accounts', 'step' => 'S3', 'parity' => 108, 'icon' => 'trash'],
                ['name' => 'Upgrade / Downgrade', 'step' => 'S4', 'parity' => 109, 'icon' => 'refresh'],
                ['name' => 'Quota Modification', 'step' => 'S4', 'parity' => 110, 'icon' => 'hdd'],
                ['name' => 'Password Modification', 'step' => 'S3', 'parity' => 111, 'icon' => 'key'],
                ['name' => 'Manage Shell Access', 'step' => 'S6', 'parity' => 115, 'icon' => 'terminal'],
            ],
        ],
        [
            'key' => 'account-information', 'name' => 'Account Information', 'icon' => 'info',
            'items' => [
                ['name' => 'List Suspended / Over Quota', 'step' => 'S3', 'parity' => 120, 'icon' => 'alert'],
                ['name' => 'View Bandwidth Usage', 'step' => 'S11', 'parity' => 121, 'icon' => 'chart'],
                ['name' => 'Email All Users', 'step' => 'S11', 'parity' => 117, 'icon' => 'send'],
            ],
        ],
        [
            'key' => 'packages', 'name' => 'Packages & Features', 'icon' => 'box',
            'items' => [
                ['name' => 'Add / Edit / Delete Package', 'step' => 'S4', 'parity' => 123, 'icon' => 'box'],
                ['name' => 'Feature Manager', 'step' => 'S4', 'parity' => 124, 'icon' => 'sliders'],
                ['name' => 'Feature Showcase', 'step' => 'S2B', 'parity' => 125, 'icon' => 'star'],
                ['name' => 'Reseller Center', 'step' => 'S15', 'parity' => 126, 'icon' => 'users'],
                ['name' => 'Themes / Theme Manager', 'step' => 'S2B', 'parity' => 127, 'icon' => 'image'],
            ],
        ],
        [
            'key' => 'dns', 'name' => 'DNS Functions', 'icon' => 'globe',
            'items' => [
                ['name' => 'DNS Zone Manager', 'step' => 'S9', 'parity' => 128, 'icon' => 'globe'],
                ['name' => 'Add / Delete DNS Zone', 'step' => 'S9', 'parity' => 129, 'icon' => 'plus'],
                ['name' => 'Edit Zone Templates', 'step' => 'S9', 'parity' => 131, 'icon' => 'file'],
                ['name' => 'DNS Cluster', 'step' => 'S15', 'parity' => 140, 'icon' => 'cloud'],
            ],
        ],
        [
            'key' => 'email', 'name' => 'Email (server-wide)', 'icon' => 'mail',
            'items' => [
                ['name' => 'Mail Queue Manager', 'step' => 'S7', 'parity' => 141, 'icon' => 'list'],
                ['name' => 'Mail Delivery Reports', 'step' => 'S7', 'parity' => 142, 'icon' => 'search'],
                ['name' => 'Exim Configuration Manager', 'step' => 'S7', 'parity' => 143, 'icon' => 'cog'],
                ['name' => 'Mailserver Configuration', 'step' => 'S7', 'parity' => 144, 'icon' => 'server'],
            ],
        ],
        [
            'key' => 'sql', 'name' => 'SQL / Databases', 'icon' => 'database',
            'items' => [
                ['name' => 'Manage DB Users', 'step' => 'S8', 'parity' => 149, 'icon' => 'users'],
                ['name' => 'Repair / Optimize DB', 'step' => 'S8', 'parity' => 150, 'icon' => 'wrench'],
                ['name' => 'phpMyAdmin Config', 'step' => 'S8', 'parity' => 151, 'icon' => 'globe'],
                ['name' => 'Remote MySQL', 'step' => 'S8', 'parity' => 152, 'icon' => 'cloud'],
            ],
        ],
        [
            'key' => 'security', 'name' => 'Security Center', 'icon' => 'shield',
            'items' => [
                ['name' => 'cPHulk Brute Force Protection', 'step' => 'S13', 'parity' => 153, 'icon' => 'shield'],
                ['name' => 'Host Access Control', 'step' => 'S13', 'parity' => 154, 'icon' => 'lock'],
                ['name' => 'Security Advisor', 'step' => 'S13', 'parity' => 157, 'icon' => 'check'],
                ['name' => 'Two-Factor Authentication', 'step' => 'S13', 'parity' => 159, 'icon' => 'key'],
                ['name' => 'Manage API Tokens', 'step' => 'S12', 'parity' => 170, 'icon' => 'key'],
                ['name' => 'ModSecurity Config', 'step' => 'S13', 'parity' => 162, 'icon' => 'shield-check'],
            ],
        ],
        [
            'key' => 'services', 'name' => 'Service Configuration', 'icon' => 'server',
            'items' => [
                ['name' => 'Service Manager', 'step' => 'S2B', 'parity' => 171, 'icon' => 'server', 'live' => true],
                ['name' => 'Restart Services', 'step' => 'S2B', 'parity' => 172, 'icon' => 'refresh'],
                ['name' => 'Manage Service SSL Certificates', 'step' => 'S5', 'parity' => 174, 'icon' => 'lock'],
            ],
        ],
        [
            'key' => 'server-config', 'name' => 'Server Configuration', 'icon' => 'cog',
            'items' => [
                ['name' => 'Basic Setup', 'step' => 'S2B', 'parity' => 94, 'icon' => 'info'],
                ['name' => 'Tweak Settings', 'step' => 'S13', 'parity' => 95, 'icon' => 'sliders'],
                ['name' => 'Server Time (NTP)', 'step' => 'S11', 'parity' => 100, 'icon' => 'clock'],
                ['name' => 'Terminal (root, audited)', 'step' => 'S6', 'parity' => 102, 'icon' => 'terminal'],
                ['name' => 'Update Preferences', 'step' => 'S15', 'parity' => 103, 'icon' => 'download'],
            ],
        ],
        [
            'key' => 'backup', 'name' => 'Backup / Clusters / Reboot', 'icon' => 'archive',
            'items' => [
                ['name' => 'Backup Configuration', 'step' => 'S10', 'parity' => 176, 'icon' => 'archive'],
                ['name' => 'Graceful / Forceful Reboot', 'step' => 'S2B', 'parity' => 181, 'icon' => 'refresh'],
                ['name' => 'Server Information', 'step' => 'S11', 'parity' => 182, 'icon' => 'info', 'live' => true],
            ],
        ],
        [
            'key' => 'cpanel', 'name' => 'cPanel (branding)', 'icon' => 'star',
            'items' => [
                ['name' => 'Customization / Branding', 'step' => 'S2B', 'parity' => 127, 'icon' => 'image'],
                ['name' => 'Change Style (client theme)', 'step' => 'S2B', 'parity' => 89, 'icon' => 'star'],
            ],
        ],
    ],

    // Reseller flavour — reseller-scoped subset (like reseller WHM access in
    // cPanel companies): own accounts + packages + support, no server config.
    'reseller' => [
        [
            'key' => 'main', 'name' => 'Main', 'icon' => 'home',
            'items' => [
                ['name' => 'Home', 'route' => 'reseller.dashboard', 'icon' => 'home', 'live' => true],
                ['name' => 'Server Status', 'step' => 'S11', 'parity' => 182, 'icon' => 'gauge'],
            ],
        ],
        [
            'key' => 'account-functions', 'name' => 'Account Functions', 'icon' => 'users',
            'items' => [
                ['name' => 'Create a New Account', 'step' => 'S3', 'parity' => 104, 'icon' => 'plus'],
                ['name' => 'List Accounts', 'step' => 'S3', 'parity' => 105, 'icon' => 'list', 'live' => true],
                ['name' => 'Suspend / Unsuspend', 'step' => 'S3', 'parity' => 107, 'icon' => 'lock'],
                ['name' => 'Terminate Accounts', 'step' => 'S3', 'parity' => 108, 'icon' => 'trash'],
                ['name' => 'Password Modification', 'step' => 'S3', 'parity' => 111, 'icon' => 'key'],
            ],
        ],
        [
            'key' => 'account-information', 'name' => 'Account Information', 'icon' => 'info',
            'items' => [
                ['name' => 'List Suspended / Over Quota', 'step' => 'S3', 'parity' => 120, 'icon' => 'alert'],
                ['name' => 'View Bandwidth Usage', 'step' => 'S11', 'parity' => 121, 'icon' => 'chart'],
            ],
        ],
        [
            'key' => 'packages', 'name' => 'Packages', 'icon' => 'box',
            'items' => [
                ['name' => 'Packages (my allocations)', 'step' => 'S4', 'parity' => 123, 'icon' => 'box'],
                ['name' => 'Reseller Center', 'step' => 'S15', 'parity' => 126, 'icon' => 'users'],
            ],
        ],
        [
            'key' => 'support', 'name' => 'Support', 'icon' => 'bell',
            'items' => [
                ['name' => 'Email All Users', 'step' => 'S11', 'parity' => 117, 'icon' => 'send'],
                ['name' => 'Audit Logs (my actions)', 'step' => 'S2B', 'parity' => 170, 'icon' => 'list'],
            ],
        ],
    ],
];
