<?php

declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * cPanel "Git Version Control" — root-side git operations (audit B1).
 *
 * Panel ka web-FPM `proc_open` disabled hone ki wajah se `git clone/pull/status`
 * HTTP 500 deta tha; ab wahi kaam root agent karta hai, argv-only (koi shell
 * string nahi). Repos account-scoped hote hain: `<home>/git/<dir>` (cPanel style).
 */
final class Git
{
    private const BIN_CANDIDATES = ['/usr/bin/git', '/usr/local/bin/git', '/bin/git'];

    public function __construct(
        private readonly CommandExecutor $cmd,
        private readonly TaskLogger $log,
    ) {
    }

    /** Absolute git path (first that exists; default for fake/test envs). */
    public static function binary(): string
    {
        foreach (self::BIN_CANDIDATES as $bin) {
            if (is_file($bin)) {
                return $bin;
            }
        }

        return '/usr/bin/git';
    }

    /** Repo base inside the account home: `<home>/git`. */
    public static function base(string $home): string
    {
        return rtrim($home, '/') . '/git';
    }

    /** Names of dirs under `<home>/git` that actually contain a `.git` dir. */
    public function listRepos(SafeFs $fs, string $home): array
    {
        $base = self::base($home);
        if (!$fs->isDir($base)) {
            return [];
        }
        $repos = [];
        foreach ($fs->listNames($base) as $name) {
            if ($fs->isDir($base . '/' . $name . '/.git')) {
                $repos[] = $name;
            }
        }
        sort($repos);

        return $repos;
    }

    /** `git clone -- <url> <path>` (the `--` stops option injection from the URL). */
    public function cloneRepo(string $url, string $path, int $timeout = 120): CommandResult
    {
        $r = $this->cmd->run([self::binary(), 'clone', '--', $url, $path], $timeout);
        $this->log->info("git clone {$url} -> {$path} exit={$r->exitCode}");

        return $r;
    }

    /** `git -C <path> pull --ff-only` (safe.directory: repo user-owned ho sakta hai). */
    public function pull(string $path, int $timeout = 120): CommandResult
    {
        $r = $this->cmd->run(
            [self::binary(), '-C', $path, '-c', 'safe.directory=' . $path, 'pull', '--ff-only'],
            $timeout
        );
        $this->log->info("git pull {$path} exit={$r->exitCode}");

        return $r;
    }

    /** `git -C <path> status --porcelain`. */
    public function status(string $path, int $timeout = 30): CommandResult
    {
        return $this->cmd->run(
            [self::binary(), '-C', $path, '-c', 'safe.directory=' . $path, 'status', '--porcelain'],
            $timeout
        );
    }
}
