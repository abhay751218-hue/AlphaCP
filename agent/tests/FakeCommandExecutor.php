<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tests;

use Alphacp\Agent\CommandExecutor;
use Alphacp\Agent\CommandResult;

/**
 * In-memory CommandExecutor for account-handler tests. Never touches the OS.
 */
final class FakeCommandExecutor implements CommandExecutor
{
    /** @var list<list<string>> */
    public array $calls = [];

    /** @var array<string, string> username => gecos */
    public array $users = [];

    /** @var array<string, true> */
    public array $locked = [];

    public ?string $crontabBody = null;

    public ?string $failWhenContains = null;

    public function run(array $argv, ?int $timeout = null, ?string $stdin = null): CommandResult
    {
        $this->calls[] = $argv;
        $line = implode(' ', $argv);
        if ($this->failWhenContains !== null && str_contains($line, $this->failWhenContains)) {
            return new CommandResult($argv, 1, '', 'injected failure: ' . $this->failWhenContains, 1);
        }

        $bin = basename((string) ($argv[0] ?? ''));
        return match ($bin) {
            'getent' => $this->getent($argv),
            'useradd' => $this->useradd($argv),
            'userdel' => $this->userdel($argv),
            'usermod' => $this->usermod($argv),
            'setquota', 'systemctl' => new CommandResult($argv, 0, "fake {$bin} ok\n", '', 1),
            'crontab' => $this->handleCrontab($argv, $stdin),
            'certbot' => $this->handleCertbot($argv),
            default => new CommandResult($argv, 0, '', '', 1),
        };
    }

    /** @param list<string> $argv */
    private function getent(array $argv): CommandResult
    {
        $user = $argv[2] ?? '';
        if (($argv[1] ?? '') === 'passwd' && isset($this->users[$user])) {
            $gecos = $this->users[$user];
            $line = "{$user}:x:1500:1500:{$gecos}:/home/{$user}:/usr/sbin/nologin\n";
            return new CommandResult($argv, 0, $line, '', 1);
        }
        return new CommandResult($argv, 2, '', 'not found', 1);
    }

    /** @param list<string> $argv */
    private function useradd(array $argv): CommandResult
    {
        $user = $argv[array_key_last($argv)] ?? '';
        $gecos = 'AlphaCP:unknown';
        foreach ($argv as $i => $arg) {
            if ($arg === '-c' && isset($argv[$i + 1])) {
                $gecos = $argv[$i + 1];
            }
        }
        if (isset($this->users[$user])) {
            return new CommandResult($argv, 9, '', 'user exists', 1);
        }
        $this->users[$user] = $gecos;
        return new CommandResult($argv, 0, '', '', 1);
    }

    /** @param list<string> $argv */
    private function userdel(array $argv): CommandResult
    {
        $user = $argv[array_key_last($argv)] ?? '';
        if (!isset($this->users[$user])) {
            return new CommandResult($argv, 6, '', 'no such user', 1);
        }
        unset($this->users[$user], $this->locked[$user]);
        return new CommandResult($argv, 0, '', '', 1);
    }

    /** @param list<string> $argv */
    private function usermod(array $argv): CommandResult
    {
        $user = $argv[array_key_last($argv)] ?? '';
        if (!isset($this->users[$user])) {
            return new CommandResult($argv, 6, '', 'no such user', 1);
        }
        if (in_array('-L', $argv, true)) {
            $this->locked[$user] = true;
        }
        if (in_array('-U', $argv, true)) {
            unset($this->locked[$user]);
        }
        return new CommandResult($argv, 0, '', '', 1);
    }

    /** @param list<string> $argv */
    private function handleCertbot(array $argv): CommandResult
    {
        $config = '';
        $domain = '';
        foreach ($argv as $i => $arg) {
            if ($arg === '--config-dir' && isset($argv[$i + 1])) {
                $config = (string) $argv[$i + 1];
            }
            if ($arg === '-d' && isset($argv[$i + 1])) {
                $domain = (string) $argv[$i + 1];
            }
        }
        if ($config === '' || $domain === '') {
            return new CommandResult($argv, 1, '', 'certbot: missing --config-dir or -d', 1);
        }
        $live = $config . '/live/' . $domain;
        if (!is_dir($live) && !@mkdir($live, 0700, true) && !is_dir($live)) {
            return new CommandResult($argv, 1, '', 'certbot: mkdir live failed', 1);
        }
        file_put_contents($live . '/fullchain.pem', "-----BEGIN CERTIFICATE-----\nLE-fake\n-----END CERTIFICATE-----\n");
        file_put_contents($live . '/privkey.pem', "-----BEGIN PRIVATE KEY-----\nLE-fake\n-----END PRIVATE KEY-----\n");
        return new CommandResult($argv, 0, "Successfully received certificate.\n", '', 1);
    }

    /** @param list<string> $argv */
    private function handleCrontab(array $argv, ?string $stdin): CommandResult
    {
        if (in_array('-r', $argv, true)) {
            $this->crontabBody = '';
            return new CommandResult($argv, 0, '', '', 1);
        }
        $this->crontabBody = (string) $stdin;
        return new CommandResult($argv, 0, '', '', 1);
    }
}
