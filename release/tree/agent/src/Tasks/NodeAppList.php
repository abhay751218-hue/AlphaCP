<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

/**
 * node.list — account ki saari Node.js apps (PM2-style list): naam, entry,
 * port, systemd state, PID, uptime-since aur log tail. Read-only.
 */
final class NodeAppList extends NodeTask
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = $this->account($payload, $ctx);
        $fs = $this->fs($ctx);
        $root = $this->appsRoot($username);

        $apps = [];
        if ($fs->isDir($root)) {
            foreach ($fs->listNames($root) as $name) {
                if (preg_match('/^[a-z][a-z0-9]{0,15}$/', $name) !== 1 || !$fs->isDir($root . '/' . $name)) {
                    continue;
                }
                $meta = ['entry' => 'app.js', 'port' => 0];
                $metaFile = $root . '/' . $name . '/.alphacp.json';
                if ($fs->isFile($metaFile)) {
                    $decoded = json_decode($fs->read($metaFile), true);
                    if (is_array($decoded)) {
                        $meta = array_merge($meta, $decoded);
                    }
                }
                $logTail = '';
                $logFile = $root . '/' . $name . '/app.log';
                if ($fs->isFile($logFile)) {
                    $log = $fs->read($logFile);
                    $logTail = strlen($log) > 3000 ? substr($log, -3000) : $log;
                }
                $apps[] = [
                    'name'     => $name,
                    'entry'    => (string) ($meta['entry'] ?? 'app.js'),
                    'port'     => (int) ($meta['port'] ?? 0),
                    'unit'     => $this->unit($username, $name),
                    'state'    => $fs->exists($this->unitPath($username, $name))
                        ? $this->unitState($ctx, $username, $name)
                        : ['active' => 'no-unit', 'sub' => '', 'pid' => 0, 'since' => ''],
                    'log_tail' => $logTail,
                ];
            }
        }

        return [
            'username' => $username,
            'apps'     => $apps,
            'count'    => count($apps),
            'node_bin' => is_file('/usr/bin/node') || is_file('/usr/local/bin/node'),
        ];
    }
}
