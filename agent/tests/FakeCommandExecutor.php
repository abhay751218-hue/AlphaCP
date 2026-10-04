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

    /** @var array<string, string> username => login shell path */
    public array $shells = [];

    public ?string $crontabBody = null;

    /** @var list<string>|null overrides the plain `tar --list` output */
    public ?array $tarListLines = null;

    /** @var list<string>|null overrides the verbose `tar --list --verbose` output */
    public ?array $tarVerboseLines = null;

    /** @var list<string>|null files materialised on `tar --extract` (relative to --directory) */
    public ?array $tarExtractPaths = null;

    /** @var array<string, list<string>> member name => plain `tar --list <member>` output */
    public array $tarMemberList = [];

    /** @var array<string, list<string>> member name => verbose `tar --list --verbose <member>` output */
    public array $tarMemberVerbose = [];

    /** @var list<string>|null `tar --list` output when the archive is a nested homedir.tar */
    public ?array $tarNestedList = null;

    /** @var list<string>|null verbose output when the archive is a nested homedir.tar */
    public ?array $tarNestedVerbose = null;

    /** @var list<string>|null files materialised when a nested homedir.tar is extracted */
    public ?array $tarNestedExtractPaths = null;

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
            'tar' => $this->handleTar($argv),
            default => new CommandResult($argv, 0, '', '', 1),
        };
    }

    /** @param list<string> $argv */
    private function getent(array $argv): CommandResult
    {
        $user = $argv[2] ?? '';
        if (($argv[1] ?? '') === 'passwd' && isset($this->users[$user])) {
            $gecos = $this->users[$user];
            $shell = $this->shells[$user] ?? '/usr/sbin/nologin';
            $line = "{$user}:x:1500:1500:{$gecos}:/home/{$user}:{$shell}\n";
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
        foreach ($argv as $i => $arg) {
            if ($arg === '-s' && isset($argv[$i + 1])) {
                $this->shells[$user] = $argv[$i + 1];
            }
        }
        return new CommandResult($argv, 0, '', '', 1);
    }

    /** @param list<string> $argv */
    private function handleTar(array $argv): CommandResult
    {
        $fileIndex = array_search('--file', $argv, true);
        $path = is_int($fileIndex) ? (string) ($argv[$fileIndex + 1] ?? '') : '';
        if ($path === '') {
            return new CommandResult($argv, 2, '', 'tar: missing --file', 1);
        }
        $nested = str_ends_with($path, '/homedir.tar') || str_ends_with($path, '/homedir');
        $member = null;
        $dashIndex = array_search('--', $argv, true);
        if (is_int($dashIndex) && isset($argv[$dashIndex + 1])) {
            $member = (string) $argv[$dashIndex + 1];
        }
        if (in_array('--create', $argv, true)) {
            if (@file_put_contents($path, "fake-gzip-tar-archive\n") === false) {
                return new CommandResult($argv, 2, '', 'tar: cannot create archive', 1);
            }
            return new CommandResult($argv, 0, '', '', 1);
        }
        if (in_array('--list', $argv, true) && is_file($path)) {
            if (in_array('--verbose', $argv, true)) {
                if ($member !== null && isset($this->tarMemberVerbose[$member])) {
                    $lines = $this->tarMemberVerbose[$member];
                } elseif ($nested) {
                    $lines = $this->tarNestedVerbose ?? [
                        'drwxr-xr-x 1500/1500 0 2026-10-03 16:00 ./public_html/',
                        '-rw-r--r-- 1500/1500 14 2026-10-03 16:00 ./public_html/index.php',
                    ];
                } else {
                    $lines = $this->tarVerboseLines ?? [
                        'drwxr-xr-x 1500/1500 0 2026-10-03 16:00 alicehost/',
                        'drwxr-xr-x 1500/1500 0 2026-10-03 16:00 alicehost/public_html/',
                        '-rw-r--r-- 1500/1500 21 2026-10-03 16:00 alicehost/public_html/index.php',
                    ];
                }
                return new CommandResult($argv, 0, implode("\n", $lines) . "\n", '', 1);
            }
            if ($member !== null && isset($this->tarMemberList[$member])) {
                $lines = $this->tarMemberList[$member];
            } elseif ($nested) {
                $lines = $this->tarNestedList ?? ['./public_html/', './public_html/index.php'];
            } else {
                $lines = $this->tarListLines ?? ['alicehost/', 'alicehost/public_html/', 'alicehost/public_html/index.php'];
            }
            return new CommandResult($argv, 0, implode("\n", $lines) . "\n", '', 1);
        }
        if (in_array('--extract', $argv, true)) {
            $dirIndex = array_search('--directory', $argv, true);
            $target = is_int($dirIndex) ? (string) ($argv[$dirIndex + 1] ?? '') : '';
            if ($target === '' || !is_dir($target)) {
                return new CommandResult($argv, 2, '', 'tar: cannot chdir', 1);
            }
            if ($nested) {
                $paths = $this->tarNestedExtractPaths ?? ['./public_html/index.php' => 'nested import'];
            } else {
                $paths = $this->tarExtractPaths ?? [
                    'alicehost/public_html/index.php' => '<?php echo "restored";',
                    'alicehost/public_html/restored.txt' => 'restored file',
                ];
            }
            foreach ($paths as $key => $rel) {
                $file = $target . '/' . (is_int($key) ? $rel : $key);
                if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0755, true) && !is_dir(dirname($file))) {
                    return new CommandResult($argv, 2, '', 'tar: mkdir failed', 1);
                }
                if (@file_put_contents($file, is_int($key) ? 'restored' : $rel) === false) {
                    return new CommandResult($argv, 2, '', 'tar: write failed', 1);
                }
            }
            return new CommandResult($argv, 0, '', '', 1);
        }
        return new CommandResult($argv, 2, '', 'tar: archive missing', 1);
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
