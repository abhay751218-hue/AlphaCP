<?php

declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\WebDisk;

/**
 * webdisk.list — account ke provisioned WebDAV (Web Disk) accounts.
 *
 * Source of truth = `<home>/etc/webdisk.conf` ke managed comments (agent-side);
 * panel isi list se Web Disk page render karta hai (cPanel parity, audit B3).
 *
 * @acp-task webdisk.list
 */
final class WebDiskList implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        [$username, $wd] = WebDisk::forAccount($payload, $ctx);

        return [
            'username' => $username,
            'accounts' => $wd->entries($username),
            'realm'    => WebDisk::REALM,
            'status'   => 'ok',
        ];
    }
}
