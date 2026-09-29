<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Permission keys (RBAC). Modules check these — never hardcode role names.
 *
 * Naming: <module>.<verb>  ·  Root role bypasses all checks (see User::isRoot).
 * Nayi module banate waqt: pehle yahan key add karo, phir step ke migration me
 * role_permissions seed karo.
 */
final class PermissionCatalog
{
    /** @return array<string, array{module:string,label:string,sort:int}> */
    public static function all(): array
    {
        $defs = [
            // module => [ [key, label], ... ]
            'core' => [
                ['core.access', 'Panel access (login)'],
                ['core.self', 'Manage own profile/2FA/password'],
            ],
            'users' => [
                ['users.view', 'View panel users'],
                ['users.manage', 'Create/edit/suspend panel users'],
                ['roles.manage', 'Edit roles & permissions'],
            ],
            'audit' => [
                ['audit.view', 'View audit log'],
            ],
            'server' => [
                ['system.view', 'View server information'],
                ['system.services', 'View service status'],
                ['system.tasks', 'View task queue monitor'],
                ['system.manage', 'Manage server services'],
            ],
            'accounts' => [
                ['accounts.view', 'View hosting accounts'],
                ['accounts.create', 'Create hosting accounts'],
                ['accounts.suspend', 'Suspend/unsuspend accounts'],
                ['accounts.terminate', 'Terminate accounts'],
                ['accounts.modify', 'Modify accounts & passwords'],
            ],
            'packages' => [
                ['packages.view', 'View packages'],
                ['packages.manage', 'Create/edit packages'],
            ],
            'files' => [
                ['files.view', 'Browse files'],
                ['files.manage', 'Upload/edit/delete files'],
            ],
            'email' => [
                ['email.view', 'View email accounts'],
                ['email.manage', 'Create/manage email'],
            ],
            'domains' => [
                ['domains.view', 'View domains'],
                ['domains.manage', 'Add/remove domains'],
            ],
            'software' => [
                ['software.view', 'View MultiPHP / software'],
                ['software.manage', 'Change PHP version'],
            ],
            'cron' => [
                ['cron.view', 'View cron jobs'],
                ['cron.manage', 'Create/delete cron jobs'],
            ],
            'errorpages' => [
                ['errorpages.view', 'View error pages'],
                ['errorpages.manage', 'Edit custom error pages'],
            ],
            'indexes' => [
                ['indexes.view', 'View indexes setting'],
                ['indexes.manage', 'Change directory listing'],
            ],
            'databases' => [
                ['databases.view', 'View databases'],
                ['databases.manage', 'Create/manage databases'],
            ],
            'dns' => [
                ['dns.view', 'View DNS zones'],
                ['dns.manage', 'Edit DNS records'],
            ],
            'backup' => [
                ['backup.view', 'View backups'],
                ['backup.manage', 'Run/restore backups'],
            ],
            'monitoring' => [
                ['metrics.view', 'View stats & metrics'],
            ],
            'ssl' => [
                ['ssl.view', 'View SSL status'],
                ['ssl.manage', 'Issue/remove SSL certificates'],
            ],
            'security' => [
                ['security.view', 'View security center'],
                ['security.manage', 'Change security settings'],
            ],
            'api' => [
                ['api.view', 'View API tokens'],
                ['api.manage', 'Create/revoke API tokens'],
            ],
            'license' => [
                ['license.view', 'View license status'],
                ['license.manage', 'Activate/update license'],
            ],
            'updates' => [
                ['updates.view', 'View update status'],
                ['updates.manage', 'Run panel updates/rollback'],
            ],
        ];

        $out = [];
        $sort = 10;
        foreach ($defs as $module => $rows) {
            foreach ($rows as [$key, $label]) {
                $out[$key] = ['module' => $module, 'label' => $label, 'sort' => $sort];
                $sort += 10;
            }
        }
        return $out;
    }

    /** Default permission set per role. Root gets everything (see User::isRoot). */
    public static function defaultsForRole(string $role): array
    {
        return match ($role) {
            'root' => array_keys(self::all()),

            'reseller' => [
                'core.access', 'core.self', 'users.view', 'users.manage',
                'accounts.view', 'accounts.create', 'accounts.suspend',
                'accounts.modify', 'packages.view', 'files.view', 'files.manage',
                'email.view', 'email.manage', 'domains.view', 'domains.manage',
                'databases.view', 'databases.manage', 'dns.view', 'backup.view',
                'metrics.view', 'security.view', 'audit.view', 'system.view',
                'software.view', 'software.manage', 'cron.view', 'cron.manage',
                'ssl.view', 'ssl.manage', 'errorpages.view', 'errorpages.manage',
                'indexes.view', 'indexes.manage',
            ],

            'user' => [
                'core.access', 'core.self', 'files.view', 'files.manage',
                'email.view', 'email.manage', 'domains.view', 'domains.manage',
                'databases.view', 'dns.view', 'backup.view', 'metrics.view',
                'software.view', 'software.manage', 'cron.view', 'cron.manage',
                'ssl.view', 'ssl.manage', 'errorpages.view', 'errorpages.manage',
                'indexes.view', 'indexes.manage',
            ],

            'mail' => [
                'core.access', 'core.self', 'email.view', 'email.manage',
            ],

            default => ['core.access'],
        };
    }
}
