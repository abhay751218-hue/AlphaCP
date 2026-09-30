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
                    ['name' => 'Images',              'step' => 'S6',  'status' => 'step'],
                    ['name' => 'Directory Privacy',   'step' => 'S6',  'status' => 'live', 'route' => 'privacy.index'],
                    ['name' => 'Disk Usage',          'step' => 'S6',  'status' => 'live', 'route' => 'disk.index'],
                    ['name' => 'FTP Accounts',        'step' => 'S6',  'status' => 'step'],
                    ['name' => 'Backup',              'step' => 'S10', 'status' => 'step'],
                    ['name' => 'Backup Wizard',       'step' => 'S10', 'status' => 'step'],
                    ['name' => 'Git Version Control', 'step' => 'S6',  'status' => 'step'],
                    ['name' => 'File Restoration',    'step' => 'S10', 'status' => 'step'],
                    ['name' => 'Trash',               'step' => 'S6',  'status' => 'step'],
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
                    ['name' => 'Webmail',             'step' => 'S7', 'status' => 'step'],
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
                    ['name' => 'Zone Editor',    'step' => 'S9', 'status' => 'step'],
                ],
            ],
            'databases' => [
                'label' => 'Databases',
                'icon'  => 'database',
                'audience' => 'cpanel',
                'items' => [
                    ['name' => 'MySQL Databases',  'step' => 'S8', 'status' => 'step'],
                    ['name' => 'Database Wizard',  'step' => 'S8', 'status' => 'step'],
                    ['name' => 'phpMyAdmin',       'step' => 'S8', 'status' => 'step'],
                    ['name' => 'Remote MySQL',     'step' => 'S8', 'status' => 'step'],
                    ['name' => 'PostgreSQL',       'step' => 'post-v1', 'status' => 'addon'],
                ],
            ],
            'metrics' => [
                'label' => 'Metrics',
                'icon'  => 'chart',
                'audience' => 'cpanel',
                'items' => [
                    ['name' => 'Visitors',        'step' => 'S11', 'status' => 'step'],
                    ['name' => 'Errors',          'step' => 'S11', 'status' => 'step'],
                    ['name' => 'Bandwidth',       'step' => 'S11', 'status' => 'step'],
                    ['name' => 'Raw Access',      'step' => 'S11', 'status' => 'step'],
                    ['name' => 'Awstats',         'step' => 'S11', 'status' => 'step'],
                    ['name' => 'Resource Usage',  'step' => 'S11', 'status' => 'step'],
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
                    ['name' => 'IP Blocker',     'step' => 'S13', 'status' => 'step'],
                    ['name' => 'ModSecurity',    'step' => 'S13', 'status' => 'step'],
                    ['name' => 'SSH Access',     'step' => 'S6',  'status' => 'live', 'route' => 'ssh.index', 'audience' => 'cpanel'],
                    ['name' => 'Hotlink Protection', 'step' => 'S13', 'status' => 'step'],
                ],
            ],
            'software' => [
                'label' => 'Software',
                'icon'  => 'box',
                'audience' => 'cpanel',
                'items' => [
                    ['name' => 'App Installer',      'step' => 'S14', 'status' => 'step'],
                    ['name' => 'WordPress Toolkit',  'step' => 'S14', 'status' => 'step'],
                    ['name' => 'MultiPHP Manager',   'step' => 'S5',  'status' => 'live', 'route' => 'php.index'],
                    ['name' => 'MultiPHP INI Editor', 'step' => 'S5', 'status' => 'live', 'route' => 'php.ini'],
                    ['name' => 'Node.js Selector',   'step' => 'S14', 'status' => 'step'],
                    ['name' => 'Optimize Website',   'step' => 'S14', 'status' => 'step'],
                    ['name' => 'PHP Composer',       'step' => 'S14', 'status' => 'step'],
                ],
            ],
            'advanced' => [
                'label' => 'Advanced',
                'icon'  => 'cog',
                'audience' => 'cpanel',
                'items' => [
                    ['name' => 'Cron Jobs',      'step' => 'S5',  'status' => 'live', 'route' => 'cron.index'],
                    ['name' => 'Track DNS',      'step' => 'S9',  'status' => 'step'],
                    ['name' => 'Indexes',        'step' => 'S5',  'status' => 'live', 'route' => 'indexes.index'],
                    ['name' => 'Error Pages',    'step' => 'S5',  'status' => 'live', 'route' => 'errorpages.index'],
                    ['name' => 'MIME Types',     'step' => 'S5',  'status' => 'live', 'route' => 'mime.index'],
                    ['name' => 'Apache Handlers','step' => 'S5',  'status' => 'live', 'route' => 'handlers.index'],
                    ['name' => 'Terminal',       'step' => 'S6',  'status' => 'step'],
                    ['name' => 'Network Tools',  'step' => 'S11', 'status' => 'step'],
                ],
            ],
            'server' => [
                'label' => 'WHM — Account Functions',
                'icon'  => 'server',
                'audience' => 'whm',
                'items' => [
                    ['name' => 'System Information',  'step' => 'S2B', 'status' => 'live', 'route' => 'system.index'],
                    ['name' => 'Service Status',      'step' => 'S2B', 'status' => 'live', 'route' => 'system.services'],
                    ['name' => 'Task Queue Monitor',  'step' => 'S2B', 'status' => 'live', 'route' => 'system.tasks'],
                    ['name' => 'Audit Log',           'step' => 'S2B', 'status' => 'live', 'route' => 'audit.index'],
                    ['name' => 'User Manager',        'step' => 'S2B', 'status' => 'live', 'route' => 'users.index'],
                    ['name' => 'Packages',            'step' => 'S4',  'status' => 'live', 'route' => 'packages.index'],
                    ['name' => 'Create Account',      'step' => 'S3',  'status' => 'live', 'route' => 'accounts.create'],
                    ['name' => 'List Accounts',       'step' => 'S3',  'status' => 'live', 'route' => 'accounts.index'],
                    ['name' => 'DNS Cluster',         'step' => 'S15', 'status' => 'step'],
                    ['name' => 'Backup Config',       'step' => 'S10', 'status' => 'step'],
                    ['name' => 'Security Center',     'step' => 'S13', 'status' => 'step'],
                    ['name' => 'API Tokens',          'step' => 'S12', 'status' => 'step'],
                    ['name' => 'License & Trial',      'step' => 'S2C', 'status' => 'live', 'route' => 'license.index'],
                    ['name' => 'Updates',             'step' => 'S15', 'status' => 'step'],
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
