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

    public function assertDocrootInHome(string $username, string $docroot): string
    {
        $home = $this->paths->home($username);
        $real = $this->fs->assert($docroot);
        $prefix = rtrim($home, '/') . '/';
        if ($real !== rtrim($home, '/') && !str_starts_with($real, $prefix)) {
            throw new RuntimeException('document_root outside account home');
        }
        return $real;
    }

    public function ensureDocroot(string $username, string $docroot): string
    {
        $real = $this->assertDocrootInHome($username, $docroot);
        $this->fs->mkdir($real, 0755);
        $this->fs->chownName($real, $username);
        $index = $real . '/index.html';
        if (!$this->fs->isFile($index)) {
            $this->fs->write($index, AccountTemplates::welcomePage($username), 0644);
            $this->fs->chownName($index, $username);
        }
        return $real;
    }

    public function writeExtraVhost(
        string $username,
        string $domain,
        string $type,
        string $docroot,
        ?string $redirectUrl = null,
        int $redirectCode = 301,
    ): void {
        if ($type === 'redirect') {
            $target = (string) $redirectUrl;
            if ($target === '' || !preg_match('#^https?://#i', $target)) {
                throw new RuntimeException('redirect_url must be http(s)');
            }
            $body = AccountTemplates::redirectVhost($username, $domain, $target, $redirectCode);
        } else {
            $home = $this->paths->home($username);
            $real = $this->assertDocrootInHome($username, $docroot);
            $body = AccountTemplates::vhost(
                $username,
                $domain,
                $home,
                $real,
                $this->paths->socketName($username),
            );
        }
        $this->fs->write($this->paths->vhostExtra($username, $domain), $body, 0644);
        $this->enableExtraVhost($username, $domain);
    }

    public function enableExtraVhost(string $username, string $domain): void
    {
        $available = $this->paths->vhostExtra($username, $domain);
        $enabled = $this->paths->vhostExtraEnabled($username, $domain);
        if (!$this->fs->isFile($available)) {
            throw new RuntimeException("extra vhost missing: {$available}");
        }
        $this->fs->symlink($available, $enabled);
    }

    public function removeExtraVhost(string $username, string $domain): void
    {
        $this->fs->unlink($this->paths->vhostExtraEnabled($username, $domain));
        $this->fs->unlink($this->paths->vhostExtra($username, $domain));
    }

    /** @return list<string> available extra vhost paths */
    public function listExtraVhosts(string $username): array
    {
        $dir = $this->fs->assert($this->paths->apacheSites);
        $prefix = 'acp-' . $username . '-';
        $out = [];
        foreach (glob($dir . '/' . $prefix . '*.conf') ?: [] as $file) {
            $out[] = $file;
        }
        return $out;
    }

    public function disableExtraVhosts(string $username): void
    {
        foreach ($this->listExtraVhosts($username) as $available) {
            $this->fs->unlink($this->paths->apacheEnabled . '/' . basename($available));
        }
    }

    public function enableExtraVhosts(string $username): void
    {
        foreach ($this->listExtraVhosts($username) as $available) {
            $this->fs->symlink($available, $this->paths->apacheEnabled . '/' . basename($available));
        }
    }

    public function removeExtraVhosts(string $username): void
    {
        foreach ($this->listExtraVhosts($username) as $available) {
            $this->fs->unlink($this->paths->apacheEnabled . '/' . basename($available));
            $this->fs->unlink($available);
        }
    }

    public function issueSelfSigned(string $username, string $domain): array
    {
        $dir = $this->paths->sslDir($username, $domain);
        $this->fs->mkdir($dir, 0700);
        $this->fs->chownName($dir, $username);
        $cert = $dir . '/cert.pem';
        $key = $dir . '/privkey.pem';
        $days = 365;
        $notAfter = gmdate('c', time() + ($days * 86400));

        $wrote = false;
        if (function_exists('openssl_pkey_new') && function_exists('openssl_csr_new')) {
            $priv = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $csr = is_object($priv) || is_resource($priv)
                ? @openssl_csr_new(['commonName' => $domain, 'organizationName' => 'AlphaCP'], $priv)
                : false;
            $x509 = ($csr !== false) ? @openssl_csr_sign($csr, null, $priv, $days) : false;
            if ($x509 !== false && openssl_x509_export($x509, $certPem) && openssl_pkey_export($priv, $keyPem)) {
                $this->fs->write($cert, (string) $certPem, 0644);
                $this->fs->write($key, (string) $keyPem, 0600);
                $wrote = true;
            }
        }
        if (! $wrote) {
            $this->fs->write($cert, "-----BEGIN CERTIFICATE-----\nAlphaCP-selfsigned\n-----END CERTIFICATE-----\n", 0644);
            $this->fs->write($key, "-----BEGIN PRIVATE KEY-----\nAlphaCP-selfsigned\n-----END PRIVATE KEY-----\n", 0600);
        }
        $this->fs->chownName($cert, $username);
        $this->fs->chownName($key, $username);

        return ['cert' => $cert, 'key' => $key, 'not_after' => $notAfter, 'issuer' => 'selfsigned'];
    }

    public function writeSslVhost(string $username, string $domain, string $docroot, string $cert, string $key): void
    {
        $realDoc = $this->assertDocrootInHome($username, $docroot);
        $body = AccountTemplates::sslVhost(
            $username,
            $domain,
            $this->paths->home($username),
            $realDoc,
            $this->paths->socketName($username),
            $cert,
            $key,
        );
        $this->fs->write($this->paths->vhostSsl($username, $domain), $body, 0644);
        $available = $this->paths->vhostSsl($username, $domain);
        $enabled = $this->paths->vhostSslEnabled($username, $domain);
        $this->fs->symlink($available, $enabled);
    }

    public function removeSslVhost(string $username, string $domain): void
    {
        $this->fs->unlink($this->paths->vhostSslEnabled($username, $domain));
        $this->fs->unlink($this->paths->vhostSsl($username, $domain));
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

    public function reloadPhp(): void
    {
        $this->reload($this->paths->phpFpmService);
    }

    public function setPhpVersion(string $username, string $phpVersion): string
    {
        $err = AccountIdentity::phpVersion($phpVersion);
        if ($err !== null) {
            throw new RuntimeException($err);
        }
        $this->removePool($username);
        $next = AccountPaths::fromEnv($phpVersion);
        $os = new self($this->cmd, $this->fs, $next, $this->log);
        $os->writePool($username);
        $os->reloadServices();
        if ($next->phpFpmService !== $this->paths->phpFpmService) {
            $this->reloadPhp();
        }
        $this->log->info("php {$phpVersion} for {$username}");

        return $phpVersion;
    }

    public function applyCrontab(string $username, string $body): void
    {
        if (AccountIdentity::username($username) !== null) {
            throw new RuntimeException('bad username');
        }
        $bin = is_file('/usr/bin/crontab') ? '/usr/bin/crontab' : '/usr/bin/crontab';
        if (trim($body) === '') {
            $result = $this->cmd->run([$bin, '-u', $username, '-r'], 15);
            if (!$result->ok() && !str_contains($result->stderr, 'no crontab')) {
                throw new RuntimeException('crontab -r failed: ' . $result->stderr);
            }
            return;
        }
        $result = $this->cmd->run([$bin, '-u', $username, '-'], 15, $body);
        if (!$result->ok()) {
            throw new RuntimeException('crontab failed: ' . $result->stderr);
        }
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
