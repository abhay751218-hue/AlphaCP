<?php

declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\WebDisk;

/**
 * webdisk.create — WebDAV (Web Disk) login banata / reset karta hai.
 *
 * Upsert semantics (cPanel jaisa): same login dobara = password/permissions
 * reset. Agent digest file (`login:realm:md5(...)`) + managed Apache DAV conf
 * likhta hai, vhost include ensure karta hai aur apache reload karta hai.
 * Plaintext password kahin persist nahi hota (DB me bhi nahi).
 *
 * @acp-task webdisk.create
 */
final class WebDiskCreate implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        [$username, $wd] = WebDisk::forAccount($payload, $ctx);
        $login = (string) ($payload['login'] ?? '');
        $perm  = (string) ($payload['permissions'] ?? '');
        $pass  = (string) ($payload['password'] ?? '');

        $wd->create($username, $login, $perm, $pass);

        return [
            'username'    => $username,
            'login'       => $login,
            'permissions' => $perm,
            'accounts'    => count($wd->entries($username)),
            'status'      => 'ok',
        ];
    }
}
