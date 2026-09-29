<?php
declare(strict_types=1);

namespace Alphacp\Agent;

use RuntimeException;

/**
 * OS mutations for one hosting account. Every command is array-exec; every
 * path goes through SafeFs/PathGuard. Methods are idempotent where possible.
 */
final class AccountOs
{
    public function __construct(
        private readonly CommandExecutor $cmd,
        private readonly SafeFs $fs,
        private readonly AccountPaths $paths,
        private readonly TaskLogger $log,
    ) {
    }

    public function userExists(string $username): bool
    {
        $result = $this->cmd->run(['/usr/bin/getent', 'passwd', $username], 10);
        return $result->ok();
    }

    public function isOurUser(string $username): bool
    {
        $result = $this->cmd->run(['/usr/bin/getent', 'passwd', $username], 10);
        if (!$result->ok()) {
            return false;
        }
        return str_contains($result->stdout, 'AlphaCP:');
    }

    public function createUser(string $username, string $domain, string $shadowHash): void
    {
        if ($this->userExists($username)) {
            if (!$this->isOurUser($username)) {
                throw new RuntimeException("linux user '{$username}' already exists and is not an AlphaCP account");
            }
            $this->log->info("user {$username} already exists (idempotent)");
            return;
        }

        $home = $this->paths->home($username);
        $this->fs->assert($home);
        $argv = [
            '/usr/sbin/useradd',
            '-m',
            '-d', $home,
            '-s', $this->paths->nologin,
            '-c', 'AlphaCP:' . $domain,
            '-p', $shadowHash,
            $username,
        ];
        $result = $this->cmd->run($argv, 30);
        if (!$result->ok()) {
            throw new RuntimeException('useradd failed: ' . trim($result->stderr . ' ' . $result->stdout));
        }
        $this->log->info("useradd {$username}");
    }

    public function lockUser(string $username): void
    {
        if (!$this->userExists($username)) {
            $this->log->warning("lock skipped — user {$username} missing");
            return;
        }
        $result = $this->cmd->run(['/usr/sbin/usermod', '-L', $username], 15);
        if (!$result->ok()) {
            throw new RuntimeException('usermod -L failed: ' . $result->stderr);
        }
    }

    public function unlockUser(string $username): void
    {
        if (!$this->userExists($username)) {
            $this->log->warning("unlock skipped — user {$username} missing");
            return;
        }
        $result = $this->cmd->run(['/usr/sbin/usermod', '-U', $username], 15);
        if (!$result->ok()) {
            throw new RuntimeException('usermod -U failed: ' . $result->stderr);
        }
    }

    public function deleteUser(string $username): void
    {
        if (!$this->userExists($username)) {
            $this->log->info("userdel skipped — {$username} already gone");
            return;
        }
        $result = $this->cmd->run(['/usr/sbin/userdel', '-r', $username], 30);
        if (!$result->ok()) {
            $retry = $this->cmd->run(['/usr/sbin/userdel', $username], 15);
            if (!$retry->ok()) {
                throw new RuntimeException('userdel failed: ' . $result->stderr);
            }
        }
        $this->log->info("userdel {$username}");
    }

    public function ensureHome(string $username, string $domain): string
    {
        $home = $this->fs->mkdir($this->paths->home($username), 0751);
        $public = $this->fs->mkdir($home . '/public_html', 0755);
        $this->fs->mkdir($home . '/logs', 0750);
        $this->fs->mkdir($home . '/tmp', 0700);
        $this->fs->mkdir($home . '/mail', 0750);
        $index = $public . '/index.html';
        if (!$this->fs->isFile($index)) {
            $this->fs->write($index, AccountTemplates::welcomePage($domain), 0644);
        }
        $this->fs->chownName($home, $username);
        $this->fs->chownName($public, $username);
        $this->fs->chownName($home . '/logs', $username);
        $this->fs->chownName($home . '/tmp', $username);
        $this->fs->chownName($home . '/mail', $username);
        $this->fs->chownName($index, $username);
        return $home;
    }

    public function writeLiveVhost(string $username, string $domain): void
    {
        $home = $this->paths->home($username);
        $body = AccountTemplates::vhost(
            $username,
            $domain,
            $home,
            $home . '/public_html',
            $this->paths->socketName($username),
        );
        $this->fs->write($this->paths->vhost($username), $body, 0644);
        $this->enableVhost($username);
    }

    public function writeSuspendedVhost(string $username, string $domain): void
    {
        $this->ensureSuspendedPage();
        $body = AccountTemplates::suspendedVhost($username, $domain, $this->paths->suspendedRoot);
        $this->fs->write($this->paths->vhost($username), $body, 0644);
        $this->enableVhost($username);
    }

    public function enableVhost(string $username): void
    {
        $available = $this->paths->vhost($username);
        $enabled = $this->paths->vhostEnabled($username);
        if (!$this->fs->isFile($available)) {
            throw new RuntimeException("vhost missing: {$available}");
        }
        $this->fs->symlink($available, $enabled);
    }

    public function removeVhost(string $username): void
    {
        $this->fs->unlink($this->paths->vhostEnabled($username));
        $this->fs->unlink($this->paths->vhost($username));
    }

    public function writePool(string $username): void
    {
        $body = AccountTemplates::pool(
            $username,
            $this->paths->home($username),
            $this->paths->socketName($username),
        );
        $this->fs->write($this->paths->pool($username), $body, 0644);
        $this->fs->unlink($this->paths->poolDisabled($username));
    }

    public function disablePool(string $username): void
    {
        $live = $this->paths->pool($username);
        $disabled = $this->paths->poolDisabled($username);
        if ($this->fs->isFile($live)) {
            $this->fs->rename($live, $disabled);
        }
    }

    public function enablePool(string $username): void
    {
        $live = $this->paths->pool($username);
        $disabled = $this->paths->poolDisabled($username);
        if ($this->fs->isFile($disabled) && !$this->fs->isFile($live)) {
            $this->fs->rename($disabled, $live);
        } elseif (!$this->fs->isFile($live)) {
            $this->writePool($username);
        }
    }

    public function removePool(string $username): void
    {
        $this->fs->unlink($this->paths->pool($username));
        $this->fs->unlink($this->paths->poolDisabled($username));
    }

    public function setQuota(string $username, int $quotaMb): string
    {
        if ($quotaMb < 0) {
            $this->log->info('quota unlimited — setquota skip');
            return 'unlimited';
        }
        $bin = $this->setquotaBin();
        if ($bin === null) {
            $this->log->warning('setquota binary missing — quota skipped');
            return 'skipped';
        }
        $blocks = $quotaMb === 0 ? 0 : $quotaMb * 1024;
        $result = $this->cmd->run([$bin, '-u', $username, '0', (string) $blocks, '0', '0', '-a'], 15);
        if (!$result->ok()) {
            $this->log->warning('setquota failed: ' . trim($result->stderr));
            return 'failed';
        }
        return 'set';
    }

    public function reloadServices(): void
    {
        $this->reload($this->paths->apacheService);
        $this->reload($this->paths->phpFpmService);
    }

    public function ensureSuspendedPage(): void
    {
        $dir = $this->paths->suspendedRoot;
        $this->fs->mkdir($dir, 0755);
        $index = $dir . '/index.html';
        if (!$this->fs->isFile($index)) {
            $this->fs->write($index, AccountTemplates::suspendedPage(), 0644);
        }
    }

    private function reload(string $service): void
    {
        if (!preg_match('/^[a-z0-9@._:-]+$/', $service)) {
            throw new RuntimeException("refusing to reload service: {$service}");
        }
        $bin = is_file('/bin/systemctl') ? '/bin/systemctl' : '/usr/bin/systemctl';
        $result = $this->cmd->run([$bin, 'reload', $service], 30);
        if (!$result->ok()) {
            $restart = $this->cmd->run([$bin, 'reload-or-restart', $service], 30);
            if (!$restart->ok()) {
                throw new RuntimeException("systemctl reload {$service} failed: " . $result->stderr);
            }
        }
        $this->log->info("reloaded {$service}");
    }

    private function setquotaBin(): ?string
    {
        foreach (['/usr/sbin/setquota', '/usr/bin/setquota'] as $bin) {
            if (is_file($bin)) {
                return $bin;
            }
        }
        // Tests: fake executor still needs a path in argv[0].
        if (getenv('ACP_FAKE_SETQUOTA') === '1') {
            return '/usr/sbin/setquota';
        }
        return null;
    }
}
