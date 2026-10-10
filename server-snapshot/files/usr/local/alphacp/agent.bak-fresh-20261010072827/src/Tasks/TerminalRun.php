<?php

declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\CommandResult;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;

/**
 * terminal.run — WHM-style "Terminal" (non-interactive): read-only whitelist.
 *
 * Panel (system.manage perm) bhi wahi whitelist lagata hai, aur agent yahan
 * DOBARA validate karta hai (defense in depth): chaining/redirect chars
 * (`;|&`$><\`) banned, sirf known read-only binaries argv-array se chalte hain.
 * Web-FPM se Process chalana proc_open disabled hone ki wajah se 500 deta tha (B1).
 *
 * @acp-task terminal.run
 */
final class TerminalRun implements TaskInterface
{
    /** Allowed command prefixes (panel ke TerminalController se identical). */
    private const ALLOWED = [
        'ls', 'pwd', 'whoami', 'date', 'uname', 'df', 'free', 'uptime',
        'git status', 'php -v', 'node -v', 'cat ',
    ];

    /** binary name → absolute path candidates (allowlist me jo maujood ho). */
    private const BINS = [
        'ls'      => ['/bin/ls', '/usr/bin/ls'],
        'pwd'     => ['/bin/pwd', '/usr/bin/pwd'],
        'whoami'  => ['/usr/bin/whoami', '/bin/whoami'],
        'date'    => ['/bin/date', '/usr/bin/date'],
        'uname'   => ['/bin/uname', '/usr/bin/uname'],
        'df'      => ['/bin/df', '/usr/bin/df'],
        'free'    => ['/usr/bin/free', '/bin/free'],
        'uptime'  => ['/usr/bin/uptime', '/bin/uptime'],
        'git'     => ['/usr/bin/git', '/usr/local/bin/git'],
        'php'     => ['/usr/bin/php8.4', '/usr/bin/php'],
        'node'    => ['/usr/bin/node', '/usr/local/bin/node'],
        'cat'     => ['/bin/cat', '/usr/bin/cat'],
    ];

    public function handle(array $payload, TaskContext $ctx): array
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('terminal.run requires PathGuard roots');
        }
        $cmd = trim((string) ($payload['command'] ?? ''));
        if ($cmd === '' || !$this->safe($cmd)) {
            throw new TaskRejectedException('command allowed nahi (sirf read-only whitelist)');
        }

        $argv = $this->argv($cmd, $ctx);
        $r = $ctx->cmd->run($argv, 30);

        return [
            'command' => $cmd,
            'output'  => substr($r->stdout, 0, 20000),
            'error'   => substr($r->stderr, 0, 2000),
            'exit'    => $r->exitCode,
            'status'  => $r->ok() ? 'ok' : 'failed',
        ];
    }

    /** Whitelist + chaining-char check (panel jaisa hi, dobara). */
    private function safe(string $cmd): bool
    {
        if (preg_match('/[;&|`$><\\\\]/', $cmd) === 1) {
            return false;
        }
        foreach (self::ALLOWED as $prefix) {
            if (str_starts_with($cmd, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Command string → argv array (koi shell nahi; tokens sirf whitespace se). */
    private function argv(string $cmd, TaskContext $ctx): array
    {
        $tokens = preg_split('/\s+/', $cmd) ?: [];
        $first = $tokens[0] ?? '';

        if (str_starts_with($cmd, 'git status')) {
            return [$this->bin('git'), 'status'];
        }
        if (str_starts_with($cmd, 'php -v')) {
            return [$this->bin('php'), '-v'];
        }
        if (str_starts_with($cmd, 'node -v')) {
            return [$this->bin('node'), '-v'];
        }
        if (str_starts_with($cmd, 'cat ')) {
            $file = $tokens[1] ?? '';
            if ($file === '') {
                throw new TaskRejectedException('cat ke liye file chahiye');
            }
            (new SafeFs($ctx->paths))->assert($file);

            return [$this->bin('cat'), $file];
        }

        $args = array_slice($tokens, 1);
        foreach ($args as $a) {
            if (str_starts_with($a, '-') && $a !== '-' && preg_match('/^-{1,2}[a-zA-Z0-9-]+$/', $a) !== 1) {
                throw new TaskRejectedException('invalid option: ' . $a);
            }
        }

        return array_merge([$this->bin($first)], $args);
    }

    private function bin(string $name): string
    {
        foreach (self::BINS[$name] ?? [] as $b) {
            if (is_file($b)) {
                return $b;
            }
        }

        return self::BINS[$name][0] ?? ('/usr/bin/' . $name);
    }
}
