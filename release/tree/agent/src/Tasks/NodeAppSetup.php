<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\TaskRejectedException;

/**
 * node.setup — nayi Node.js app register karo (cPanel "Setup Node.js App"):
 * app dir + sample entry (agar missing) + systemd unit (user ke naam se,
 * Restart=always, log file me output) + enable + start. Idempotent.
 */
final class NodeAppSetup extends NodeTask
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = $this->account($payload, $ctx);
        $app      = $this->appName($payload);
        $entry    = strtolower(trim((string) ($payload['entry'] ?? 'app.js')));
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,30}\.(js|mjs|cjs)$/', $entry) !== 1) {
            throw new TaskRejectedException('invalid entry file: jaise app.js / server.mjs (max 34 chars)');
        }
        $port = (int) ($payload['port'] ?? 3000);

        $fs   = $this->fs($ctx);
        $dir  = $this->appDir($username, $app);
        $node = $this->nodeBin();

        $fs->mkdir($this->appsRoot($username), 0755);
        $fs->chownName($this->appsRoot($username), $username, $username);
        $fs->mkdir($dir, 0755);

        $entryPath = $dir . '/' . $entry;
        $created = false;
        if (!$fs->exists($entryPath)) {
            $fs->write($entryPath, $this->sampleApp(), 0644);
            $created = true;
        }
        $fs->write($dir . '/.alphacp.json', json_encode([
            'entry' => $entry, 'port' => $port, 'created' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES) . "\n", 0644);
        $fs->chownName($dir, $username, $username);
        $fs->chownName($entryPath, $username, $username);
        $fs->chownName($dir . '/.alphacp.json', $username, $username);

        $unitBody = "# AlphaCP Node.js app (d13) — {$username}/{$app}\n"
            . "[Unit]\nDescription=AlphaCP Node.js app {$username}/{$app}\nAfter=network.target\n\n"
            . "[Service]\nType=simple\nUser={$username}\nGroup={$username}\n"
            . "WorkingDirectory={$dir}\n"
            . "Environment=NODE_ENV=production\nEnvironment=PORT={$port}\n"
            . "ExecStart={$node} {$dir}/{$entry}\n"
            . "Restart=always\nRestartSec=3\nTimeoutStopSec=15\n"
            . "StandardOutput=append:{$dir}/app.log\nStandardError=append:{$dir}/app.log\n"
            . "NoNewPrivileges=yes\nPrivateTmp=yes\n\n"
            . "[Install]\nWantedBy=multi-user.target\n";
        $fs->write($this->unitPath($username, $app), $unitBody, 0644);

        $this->systemctl($ctx, ['daemon-reload'], 30);
        $r = $this->systemctl($ctx, ['enable', '--now', $this->unit($username, $app)], 45);
        if (!$r->ok()) {
            $ctx->log->warning("enable --now failed: " . trim($r->stderr));
        }
        usleep(700_000);

        $ctx->log->info("node app {$username}/{$app} setup (entry={$entry}, port={$port})");

        return [
            'username'      => $username,
            'app'           => $app,
            'entry'         => $entry,
            'port'          => $port,
            'dir'           => $dir,
            'sample_created' => $created,
            'state'         => $this->unitState($ctx, $username, $app),
        ];
    }

    private function sampleApp(): string
    {
        return "// AlphaCP sample Node.js app — apna code is file me likho\n"
            . "const http = require('http');\n"
            . "const port = process.env.PORT || 3000;\n"
            . "http.createServer((req, res) => {\n"
            . "  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });\n"
            . "  res.end('<h1>AlphaCP Node.js app chal rahi hai!</h1><p>Port ' + port + '</p>');\n"
            . "}).listen(port, '127.0.0.1');\n"
            . "console.log('AlphaCP node app listening on ' + port);\n";
    }
}
