<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * cPanel-jaise dashboard ka data source.
 *
 * Har tile = docs/09-cpanel-parity-checklist.md ki ek row.
 *   status: 'live'   = abhi kaam karta hai (route diya hua hai)
 *           'step'   = us step me banega (number ke saath dikhta hai)
 *           'addon'  = optional module (baad me, chahein to)
 *
 * Naya module banane par: yahan tile ka status 'live' kar do aur route do.
 * Ise chhod kar dashboard me kahin tiles hardcode na karo.
 */
final class ModuleCatalog
{
    /**
     * @return array<string, array{label:string, icon:string, items:list<array<string,mixed>>}>
     */
    public static function sections(): array
    {
        return [
            'files' => [
                'label' => 'Files',
                'icon'  => 'folder',
                'audience' => 'cpanel',
                'items' => [
                    ['name' => 'File Manager',        'step' => 'S6',  'status' => 'live', 'route' => 'files.index'],
                    ['name' => 'Images',              'step' => 'S6',  'status' => 'live', 'route' => 'images.index'],
                    ['name' => 'Directory Privacy',   'step' => 'S6',  'status' => 'live', 'route' => 'privacy.index'],
                    ['name' => 'Disk Usage',          'step' => 'S6',  'status' => 'live', 'route' => 'disk.index'],
                    ['name' => 'FTP Accounts',        'step' => 'S6',  'status' => 'live', 'route' => 'ftp.index'],
                    ['name' => 'Web Disk',          'step' => 'S6',  'status' => 'live', 'route' => 'webdisk.index'],
                    ['name' => 'Backup',              'step' => 'S10', 'status' => 'live', 'route' => 'backup.index'],
                    ['name' => 'Backup Wizard',       'step' => 'S10', 'status' => 'live', 'route' => 'backup-wizard.index'],
                    ['name' => 'Git Version Control', 'step' => 'S6',  'status' => 'live', 'route' => 'git.index'],
                    ['name' => 'File Restoration',    'step' => 'S10', 'status' => 'live', 'route' => 'file-restoration.index'],
                    ['name' => 'Trash',               'step' => 'S6',  'status' => 'live', 'route' => 'trash.index'],
                ],
            ],
            'email' => [
                'label' => 'Email',
                'icon'  => 'mail',
                'audience' => 'cpanel',
                'items' => [
                    ['name' => 'Email Accounts',      'step' => 'S7', 'status' => 'live', 'route' => 'email.index'],
                    ['name' => 'Forwarders',          'step' => 'S7', 'status' => 'live', 'route' => 'forwarders.index'],
                    ['name' => 'Autoresponders',      'step' => 'S7', 'status' => 'live', 'route' => 'autoresponders.index'],
                    ['name' => 'Default Address',     'step' => 'S7', 'status' => 'live', 'route' => 'default-address.index'],
                    ['name' => 'Email Filters',       'step' => 'S7', 'status' => 'live', 'route' => 'email-filters.index'],
                    ['name' => 'Deliverability',      'step' => 'S7', 'status' => 'live', 'route' => 'deliverability.index'],
                    ['name' => 'Spam Filters',        'step' => 'S7', 'status' => 'live', 'route' => 'spam-filters.index'],
                    ['name' => 'Mailing Lists',       'step' => 'S7', 'status' => 'live', 'route' => 'mailing-lists.index'],
                    ['name' => 'Email Routing',       'step' => 'S7', 'status' => 'live', 'route' => 'email-routing.index'],
                    ['name' => 'Track Delivery',      'step' => 'S7', 'status' => 'live', 'route' => 'track-delivery.index'],
                    ['name' => 'Global Email Filters', 'step' => 'S7', 'status' => 'live', 'route' => 'global-filters.index'],
                    ['name' => 'Address Importer',    'step' => 'S7', 'status' => 'live', 'route' => 'address-importer.index'],
                    ['name' => 'Encryption',          'step' => 'S7', 'status' => 'live', 'route' => 'encryption.index'],
                    ['name' => 'BoxTrapper',          'step' => 'S7', 'status' => 'live', 'route' => 'boxtrapper.index'],
                    ['name' => 'Calendar',            'step' => 'S7', 'status' => 'live', 'route' => 'calendar.index'],
                    ['name' => 'Email Disk Usage',    'step' => 'S7', 'status' => 'live', 'route' => 'email-disk.index'],
                    ['name' => 'Webmail',             'step' => 'S7', 'status' => 'live', 'route' => 'webmail.index'],
                ],
            ],
            'domains' => [
                'label' => 'Domains',
                'icon'  => 'globe',
                'audience' => 'cpanel',
                'items' => [
                    ['name' => 'Domains',        'step' => 'S5', 'status' => 'live', 'route' => 'domains.index'],
                    ['name' => 'Subdomains',     'step' => 'S5', 'status' => 'live', 'route' => 'domains.index'],
                    ['name' => 'Addon Domains',  'step' => 'S5', 'status' => 'live', 'route' => 'domains.index'],
                    ['name' => 'Aliases',        'step' => 'S5', 'status' => 'live', 'route' => 'domains.index'],
                    ['name' => 'Redirects',      'step' => 'S5', 'status' => 'live', 'route' => 'domains.index'],
                    ['name' => 'Zone Editor',    'step' => 'S9', 'status' => 'live', 'route' => 'zone-editor.index'],
                    ['name' => 'Dynamic DNS',    'step' => 'S9', 'status' => 'live', 'route' => 'dynamic-dns.index'],
                ],
            ],
            'databases' => [
                'label' => 'Databases',
                'icon'  => 'database',
                'audience' => 'cpanel',
                'items' => [
                    ['name' => 'MySQL Databases',  'step' => 'S8', 'status' => 'live', 'route' => 'mysql.index'],
                    ['name' => 'Database Wizard',  'step' => 'S8', 'status' => 'live', 'route' => 'mysql-wizard.index'],
                    ['name' => 'MySQL Users',      'step' => 'S8', 'status' => 'live', 'route' => 'mysql-users.index'],
                    ['name' => 'phpMyAdmin',       'step' => 'S8', 'status' => 'live', 'route' => 'phpmyadmin.index'],
                    ['name' => 'Remote MySQL',     'step' => 'S8', 'status' => 'live', 'route' => 'remote-mysql.index'],
                    ['name' => 'PostgreSQL',       'step' => 'post-v1', 'status' => 'addon'],
                ],
            ],
            'metrics' => [
                'label' => 'Metrics',
                'icon'  => 'chart',
                'audience' => 'cpanel',
                'items' => [
                    ['name' => 'Visitors',        'step' => 'S11', 'status' => 'live', 'route' => 'metrics.index'],
                    ['name' => 'Errors',          'step' => 'S11', 'status' => 'live', 'route' => 'errors-log.index'],
                    ['name' => 'Bandwidth',       'step' => 'S11', 'status' => 'live', 'route' => 'metrics.index'],
                    ['name' => 'Raw Access',      'step' => 'S11', 'status' => 'live', 'route' => 'raw-access.index'],
                    ['name' => 'Awstats',         'step' => 'S11', 'status' => 'live', 'route' => 'awstats.index'],
                    ['name' => 'Resource Usage',  'step' => 'S11', 'status' => 'live', 'route' => 'monitoring.index'],
                ],
            ],
            'security' => [
                'label' => 'Security',
                'icon'  => 'shield',
                'audience' => 'both',
                'items' => [
                    ['name' => 'Two-Factor Auth', 'step' => 'S2B', 'status' => 'live', 'route' => 'security.index'],
                    ['name' => 'Password & Security', 'step' => 'S2B', 'status' => 'live', 'route' => 'security.index'],
                    ['name' => 'Active Sessions', 'step' => 'S2B', 'status' => 'live', 'route' => 'security.sessions'],
                    ['name' => 'SSL/TLS',        'step' => 'S5',  'status' => 'live', 'route' => 'ssl.index', 'audience' => 'cpanel'],
                    ['name' => 'SSL/TLS Status', 'step' => 'S5',  'status' => 'live', 'route' => 'ssl.index', 'audience' => 'cpanel'],
                    ['name' => 'IP Blocker',     'step' => 'S13', 'status' => 'live', 'route' => 'ip-blocker.index'],
                    ['name' => 'ModSecurity',    'step' => 'S13', 'status' => 'live', 'route' => 'security-tools.index'],
                    ['name' => 'SSH Access',     'step' => 'S6',  'status' => 'live', 'route' => 'ssh.index', 'audience' => 'cpanel'],
                    ['name' => 'Hotlink Protection', 'step' => 'S13', 'status' => 'live', 'route' => 'secextra.hotlink'],
                ],
            ],
            'software' => [
                'label' => 'Software',
                'icon'  => 'box',
                'audience' => 'cpanel',
                'items' => [
                    ['name' => 'App Installer',      'step' => 'S14', 'status' => 'live', 'route' => 'apps.index'],
                    ['name' => 'WordPress Toolkit',  'step' => 'S14', 'status' => 'live', 'route' => 'apps.index'],
                    ['name' => 'MultiPHP Manager',   'step' => 'S5',  'status' => 'live', 'route' => 'php.index'],
                    ['name' => 'MultiPHP INI Editor', 'step' => 'S5', 'status' => 'live', 'route' => 'php.ini'],
                    ['name' => 'Node.js Selector',   'step' => 'S14', 'status' => 'step'],
                    ['name' => 'Optimize Website',   'step' => 'S14', 'status' => 'live', 'route' => 'optimize.index'],
                    ['name' => 'PHP Composer',       'step' => 'S14', 'status' => 'step'],
                ],
            ],
            'advanced' => [
                'label' => 'Advanced',
                'icon'  => 'cog',
                'audience' => 'cpanel',
                'items' => [
                    ['name' => 'Cron Jobs',      'step' => 'S5',  'status' => 'live', 'route' => 'cron.index'],
                    ['name' => 'Track DNS',      'step' => 'S9',  'status' => 'live', 'route' => 'track-dns.index'],
                    ['name' => 'Indexes',        'step' => 'S5',  'status' => 'live', 'route' => 'indexes.index'],
                    ['name' => 'Error Pages',    'step' => 'S5',  'status' => 'live', 'route' => 'errorpages.index'],
                    ['name' => 'MIME Types',     'step' => 'S5',  'status' => 'live', 'route' => 'mime.index'],
                    ['name' => 'Apache Handlers','step' => 'S5',  'status' => 'live', 'route' => 'handlers.index'],
                    ['name' => 'Terminal',       'step' => 'S6',  'status' => 'live', 'route' => 'terminal.index'],
                    ['name' => 'Network Tools',  'step' => 'S11', 'status' => 'live', 'route' => 'network-tools.index'],
                ],
            ],
            'whm-accounts' => [
                'label' => 'Account Functions',
                'icon'  => 'server',
                'audience' => 'whm',
                'items' => [
                    ['name' => 'Create a New Account', 'step' => 'S3', 'status' => 'live', 'route' => 'accounts.create'],
                    ['name' => 'List Accounts',        'step' => 'S3', 'status' => 'live', 'route' => 'accounts.index'],
                    ['name' => 'User Manager',         'step' => 'S2B', 'status' => 'live', 'route' => 'users.index'],
                ],
            ],
            'whm-packages' => [
                'label' => 'Packages',
                'icon'  => 'box',
                'audience' => 'whm',
                'items' => [
                    ['name' => 'Add/Edit/Delete Packages', 'step' => 'S4', 'status' => 'live', 'route' => 'packages.index'],
                ],
            ],
            'whm-dns' => [
                'label' => 'DNS Functions',
                'icon'  => 'globe',
                'audience' => 'whm',
                'items' => [
                    ['name' => 'DNS Zone Manager',    'step' => 'S9', 'status' => 'live', 'route' => 'dns-zones.index'],
                    ['name' => 'Add / Delete a DNS Zone', 'step' => 'S9', 'status' => 'live', 'route' => 'dns-zones.index'],
                    ['name' => 'Add an A Entry for Your Hostname', 'step' => 'S9', 'status' => 'live', 'route' => 'hostname-a.index'],
                    ['name' => 'Edit Zone Templates', 'step' => 'S9', 'status' => 'live', 'route' => 'zone-templates.index'],
                    ['name' => 'Nameserver Record Report', 'step' => 'S9', 'status' => 'live', 'route' => 'ns-report.index'],
                    ['name' => 'Park a Domain',       'step' => 'S9', 'status' => 'live', 'route' => 'park-domain.index'],
                    ['name' => 'Perform a DNS Cleanup', 'step' => 'S9', 'status' => 'live', 'route' => 'dns-cleanup.index'],
                    ['name' => 'Set Zone TTL',        'step' => 'S9', 'status' => 'live', 'route' => 'zone-ttl.index'],
                    ['name' => 'Setup/Edit Domain Forwarding', 'step' => 'S9', 'status' => 'live', 'route' => 'domain-forward.index'],
                    ['name' => 'Synchronize DNS Records', 'step' => 'S9', 'status' => 'live', 'route' => 'dns-sync.index'],
                    ['name' => 'Nameserver Selection', 'step' => 'S9', 'status' => 'live', 'route' => 'nameserver-selection.index'],
                    ['name' => 'DNS Cluster',         'step' => 'S15', 'status' => 'live', 'route' => 'dns-cluster.index'],
                ],
            ],
            'whm-backup' => [
                'label' => 'Backup',
                'icon'  => 'folder',
                'audience' => 'whm',
                'items' => [
                    ['name' => 'Backup Configuration',  'step' => 'S10', 'status' => 'live', 'route' => 'backup-config.index'],
                    ['name' => 'Backup Destinations',   'step' => 'S10', 'status' => 'live', 'route' => 'backup-destinations.index'],
                    ['name' => 'Restore a Backup',      'step' => 'S10', 'status' => 'live', 'route' => 'backup-restoration.index'],
                    ['name' => 'Backup User Selection', 'step' => 'S10', 'status' => 'live', 'route' => 'backup-user-selection.index'],
                    ['name' => 'File and Directory Restoration', 'step' => 'S10', 'status' => 'live', 'route' => 'file-directory-restoration.index'],
                ],
            ],
            'whm-transfer' => [
                'label' => 'Transfers',
                'icon'  => 'chart',
                'audience' => 'whm',
                'items' => [
                    ['name' => 'Transfer Tool',        'step' => 'S10', 'status' => 'live', 'route' => 'transfer-tool.index'],
                    ['name' => 'Transfer or Restore a cPanel Account', 'step' => 'S10', 'status' => 'live', 'route' => 'transfer-restore.index'],
                    ['name' => 'Review Transfers and Restores', 'step' => 'S10', 'status' => 'live', 'route' => 'transfer-review.index'],
                ],
            ],
            'whm-security' => [
                'label' => 'Security Center',
                'icon'  => 'shield',
                'audience' => 'whm',
                'items' => [
                    ['name' => 'Security Policies', 'step' => 'S13', 'status' => 'live', 'route' => 'security-policies.index'],
                    ['name' => 'Audit Log',         'step' => 'S2B', 'status' => 'live', 'route' => 'audit.index'],
                    ['name' => 'Manage API Tokens', 'step' => 'S12', 'status' => 'live', 'route' => 'api-tokens.index'],
                ],
            ],
            'whm-email' => [
                'label' => 'Email (Server)',
                'icon'  => 'send',
                'audience' => 'whm',
                'items' => [
                    ['name' => 'Mail Queue Manager', 'step' => 'S7', 'status' => 'live', 'route' => 'mail-queue.index'],
                ],
            ],
            'whm-status' => [
                'label' => 'Server Status',
                'icon'  => 'chart',
                'audience' => 'whm',
                'items' => [
                    ['name' => 'System Information', 'step' => 'S2B', 'status' => 'live', 'route' => 'system.index'],
                    ['name' => 'Service Status',     'step' => 'S2B', 'status' => 'live', 'route' => 'system.services'],
                    ['name' => 'Task Queue Monitor', 'step' => 'S2B', 'status' => 'live', 'route' => 'system.tasks'],
                ],
            ],
            'whm-config' => [
                'label' => 'Server Configuration',
                'icon'  => 'cog',
                'audience' => 'whm',
                'items' => [
                    ['name' => 'License & Trial', 'step' => 'S2C', 'status' => 'live', 'route' => 'license.index'],
                    ['name' => 'Updates',         'step' => 'S15', 'status' => 'step'],
                ],
            ],
        ];
    }

    /** Progress numbers shown on the dashboard (parity meter). */
    public static function progress(): array
    {
        $live = $planned = $addon = 0;
        foreach (self::sections() as $section) {
            foreach ($section['items'] as $item) {
                match ($item['status']) {
                    'live'  => $live++,
                    'addon' => $addon++,
                    default => $planned++,
                };
            }
        }
        $total = $live + $planned + $addon;

        return [
            'live'    => $live,
            'planned' => $planned,
            'addon'   => $addon,
            'total'   => $total,
            'percent' => $total > 0 ? (int) round($live / max(1, $total - $addon) * 100) : 0,
        ];
    }

    /**
     * WHM = root/reseller (accounts.view). cPanel = hosting customer / mail.
     * Customer ko Create Account / Packages kabhi nahi dikhte.
     */
    public static function modeFor(User $user): string
    {
        if ($user->isRoot() || $user->hasPermission('accounts.view')) {
            return 'whm';
        }
        return 'cpanel';
    }

    /**
     * @return array<string, array{label:string, icon:string, audience?:string, items:list<array<string,mixed>>}>
     */
    public static function sectionsFor(User $user): array
    {
        $mode = self::modeFor($user);
        $mailOnly = $user->role?->name === 'mail';
        $out = [];
        foreach (self::sections() as $key => $section) {
            $audience = $section['audience'] ?? 'cpanel';
            if ($audience !== $mode && $audience !== 'both') {
                continue;
            }
            if ($mailOnly && ! in_array($key, ['email', 'security'], true)) {
                continue;
            }
            $items = [];
            foreach ($section['items'] as $item) {
                $itemAudience = $item['audience'] ?? $audience;
                if ($itemAudience !== $mode && $itemAudience !== 'both') {
                    continue;
                }
                $items[] = $item;
            }
            if ($items === []) {
                continue;
            }
            $section['items'] = $items;
            $out[$key] = $section;
        }
        return $out;
    }
}
