<?php

declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\WebDisk;

/**
 * webdisk.delete — WebDAV (Web Disk) login hatata hai.
 *
 * Digest entry + managed conf entry remove; aakhri account delete hone par
 * conf+digest files bhi clean ho jati hain (IncludeOptional missing tolerate
 * karta hai, reload se Alias/DAV block hat jata hai).
 *
 * @acp-task webdisk.delete
 */
final class WebDiskDelete implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        [$username, $wd] = WebDisk::forAccount($payload, $ctx);
        $login = (string) ($payload['login'] ?? '');

        $wd->delete($username, $login);

        return [
            'username' => $username,
            'login'    => $login,
            'accounts' => count($wd->entries($username)),
            'status'   => 'ok',
        ];
    }
}
