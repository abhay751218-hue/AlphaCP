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

    /**
     * @param  list<array{path: string, realm: string, slug: string, users: list<array{name: string, hash: string}>}> $entries
     * @return list<array{path: string, realm: string, slug: string, users: list<array{name: string, hash: string}>}>
     */
    public function setPrivacy(string $username, array $entries): array
    {
        $clean = Privacy::sanitize($entries);
        $home = $this->paths->home($username);
        $dir = $this->paths->privacyDir($username);
        $this->fs->mkdir($dir, 0750);
        $this->fs->chownName($dir, $username);
        $keepSlugs = [];
        foreach ($clean as $row) {
            $folder = Files::resolve($home, $row['path']);
            if (!$this->fs->isDir($folder)) {
                throw new RuntimeException('privacy folder does not exist: ' . $row['path']);
            }
            $lines = [];
            foreach ($row['users'] as $u) {
                $lines[] = $u['name'] . ':' . $u['hash'];
            }
            $ht = $dir . '/' . $row['slug'] . '.htpasswd';
            $this->fs->write($ht, implode("\n", $lines) . "\n", 0640);
            $this->fs->chownName($ht, $username);
            $keepSlugs[$row['slug']] = true;
        }
        foreach ($this->fs->listNames($dir) as $name) {
            if (!str_ends_with($name, '.htpasswd')) {
                continue;
            }
            $slug = substr($name, 0, -9);
            if (!isset($keepSlugs[$slug])) {
                $this->fs->unlink($dir . '/' . $name);
            }
        }
        $confPath = $this->paths->privacyConf($username);
        $this->fs->mkdir(dirname($confPath), 0750);
        $this->fs->write($confPath, AccountTemplates::privacyConf($home, $clean), 0644);
        $this->fs->chownName(dirname($confPath), $username);
        $this->ensurePrivacyInclude($username);
        $this->reload($this->paths->apacheService);
        $this->log->info('privacy ' . count($clean) . " folders for {$username}");

        return $clean;
    }

    public function ensurePrivacyInclude(string $username): void
    {
        $home = $this->paths->home($username);
        $needle = 'IncludeOptional ' . $home . '/etc/privacy.conf';
        $files = [$this->paths->vhost($username)];
        foreach ($this->listExtraVhosts($username) as $extra) {
            $files[] = $extra;
        }
        foreach ($files as $file) {
            if (!$this->fs->isFile($file)) {
                continue;
            }
            $body = (string) file_get_contents($this->fs->assert($file));
            if (str_contains($body, 'privacy.conf')) {
                continue;
            }
            if (!str_contains($body, '</VirtualHost>')) {
                continue;
            }
            $body = str_replace('</VirtualHost>', "    {$needle}\n</VirtualHost>", $body);
            $this->fs->write($file, $body, 0644);
        }
    }

    /**
     * @param  list<array{type: string, key: string, comment: string}> $keys
     * @return array{keys: list<array{type: string, key: string, comment: string}>, shell: string}
     */
    public function setSsh(string $username, array $keys, ?string $shell): array
    {
        $keys = Ssh::sanitizeKeys($keys);
        $home = $this->paths->home($username);
        $dir = Files::resolve($home, '.ssh');
        $file = Files::resolve($home, '.ssh/authorized_keys');
        if (is_link($dir) || is_link($file)) {
            throw new RuntimeException('ssh path is a symlink');
        }
        if (is_file($dir)) {
            throw new RuntimeException('ssh dir is a file');
        }
        $this->fs->mkdir($dir, 0700);
        $this->fs->chownName($dir, $username);
        $lines = [];
        foreach ($keys as $row) {
            $lines[] = Ssh::format($row);
        }
        $body = $lines === [] ? '' : implode("\n", $lines) . "\n";
        $this->fs->write($file, $body, 0600);
        $this->fs->chownName($file, $username);

        $current = $this->sshShell($username);
        if ($shell !== null) {
            $shell = Ssh::normalizeShell($shell);
            $path = $shell === 'bash' ? Ssh::BASH : $this->paths->nologin;
            $result = $this->cmd->run(['/usr/sbin/usermod', '-s', $path, $username], 15);
            if (!$result->ok()) {
                throw new RuntimeException('usermod -s failed: ' . $result->stderr);
            }
            $current = $shell;
            $this->log->info("ssh shell {$shell} for {$username}");
        }
        $this->log->info('ssh keys ' . count($keys) . " for {$username}");

        return ['keys' => $keys, 'shell' => $current];
    }

    public function sshShell(string $username): string
    {
        $result = $this->cmd->run(['/usr/bin/getent', 'passwd', $username], 10);
        if (!$result->ok()) {
            return 'nologin';
        }
        $parts = explode(':', trim($result->stdout));
        $path = $parts[6] ?? $this->paths->nologin;
        if ($path === Ssh::BASH || $path === '/usr/bin/bash') {
            return 'bash';
        }
        if ($path === $this->paths->nologin || str_ends_with($path, 'nologin')) {
            return 'nologin';
        }

        return 'other';
    }

    /**
     * @param  list<array{local: string, domain: string, hash: string, quota_mb: int}> $boxes
     * @return list<array{local: string, domain: string, hash: string, quota_mb: int}>
     */
    public function setMail(string $username, array $boxes): array
    {
        $boxes = Mail::sanitize($boxes);
        $home = $this->paths->home($username);
        [$uid, $gid] = $this->passwdIds($username);
        $dir = Files::resolve($home, 'etc/mail');
        if (is_link($dir)) {
            throw new RuntimeException('mail conf dir is a symlink');
        }
        $this->fs->mkdir($dir, 0750);
        $this->fs->chownName($dir, $username);
        $lines = [];
        foreach ($boxes as $row) {
            $rel = 'mail/' . $row['domain'] . '/' . $row['local'];
            $boxHome = Files::resolve($home, $rel);
            if (is_link($boxHome)) {
                throw new RuntimeException('maildir is a symlink: ' . $rel);
            }
            foreach (['cur', 'new', 'tmp'] as $leaf) {
                $p = Files::resolve($home, $rel . '/' . $leaf);
                if (is_link($p)) {
                    throw new RuntimeException('maildir leaf is a symlink');
                }
                $this->fs->mkdir($p, 0700);
                $this->fs->chownName($p, $username);
            }
            $lines[] = Mail::passwdLine($row, $uid, $gid, $home);
        }
        $passwd = Files::resolve($home, 'etc/mail/passwd');
        if (is_link($passwd)) {
            throw new RuntimeException('mail passwd is a symlink');
        }
        $body = $lines === [] ? '' : implode("\n", $lines) . "\n";
        $this->fs->write($passwd, $body, 0640);
        $this->fs->chownName($passwd, $username);
        $this->log->info('mail ' . count($boxes) . " mailboxes for {$username}");

        return $boxes;
    }

    /**
     * @param  list<array{local: string, domain: string, dest: string}> $rows
     * @return list<array{local: string, domain: string, dest: string}>
     */
    public function setForwards(string $username, array $rows): array
    {
        $rows = Mail::sanitizeForwards($rows);
        $home = $this->paths->home($username);
        $dir = Files::resolve($home, 'etc/mail');
        if (is_link($dir)) {
            throw new RuntimeException('mail conf dir is a symlink');
        }
        $this->fs->mkdir($dir, 0750);
        $this->fs->chownName($dir, $username);
        $file = Files::resolve($home, 'etc/mail/aliases');
        if (is_link($file)) {
            throw new RuntimeException('mail aliases is a symlink');
        }
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = Mail::aliasLine($row);
        }
        $body = $lines === [] ? '' : implode("\n", $lines) . "\n";
        $this->fs->write($file, $body, 0640);
        $this->fs->chownName($file, $username);
        $this->log->info('mail forwards ' . count($rows) . " for {$username}");

        return $rows;
    }

    /**
     * @param  list<array{local: string, domain: string, subject: string, body: string, interval_h: int}> $rows
     * @return list<array{local: string, domain: string, subject: string, body: string, interval_h: int}>
     */
    public function setAutorespond(string $username, array $rows): array
    {
        $rows = Mail::sanitizeResponders($rows);
        $home = $this->paths->home($username);
        $dir = Files::resolve($home, 'etc/mail');
        if (is_link($dir)) {
            throw new RuntimeException('mail conf dir is a symlink');
        }
        $this->fs->mkdir($dir, 0750);
        $this->fs->chownName($dir, $username);
        $file = Files::resolve($home, 'etc/mail/autorespond');
        if (is_link($file)) {
            throw new RuntimeException('mail autorespond is a symlink');
        }
        $this->fs->write($file, Mail::respondJson($rows), 0640);
        $this->fs->chownName($file, $username);
        $this->log->info('mail autorespond ' . count($rows) . " for {$username}");

        return $rows;
    }

    /**
     * @param  list<array{domain: string, dest: string}> $rows
     * @return list<array{domain: string, dest: string}>
     */
    public function setCatchalls(string $username, array $rows): array
    {
        $rows = Mail::sanitizeCatchalls($rows);
        $home = $this->paths->home($username);
        $dir = Files::resolve($home, 'etc/mail');
        if (is_link($dir)) {
            throw new RuntimeException('mail conf dir is a symlink');
        }
        $this->fs->mkdir($dir, 0750);
        $this->fs->chownName($dir, $username);
        $file = Files::resolve($home, 'etc/mail/catchall');
        if (is_link($file)) {
            throw new RuntimeException('mail catchall is a symlink');
        }
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = Mail::catchallLine($row);
        }
        $body = $lines === [] ? '' : implode("\n", $lines) . "\n";
        $this->fs->write($file, $body, 0640);
        $this->fs->chownName($file, $username);
        $this->log->info('mail catchall ' . count($rows) . " for {$username}");

        return $rows;
    }

    /** @return array{0:int,1:int} */
    private function passwdIds(string $username): array
    {
        $result = $this->cmd->run(['/usr/bin/getent', 'passwd', $username], 10);
        if (!$result->ok()) {
            throw new RuntimeException('getent passwd failed');
        }
        $parts = explode(':', trim($result->stdout));
        $uid = (int) ($parts[2] ?? 0);
        $gid = (int) ($parts[3] ?? 0);
        if ($uid < 1000 || $gid < 1000) {
            throw new RuntimeException('mailbox uid/gid out of range');
        }

        return [$uid, $gid];
    }

    /**
     * @return list<array{name: string, type: string, size: int, mode: string}>
     */
    public function listFiles(string $username, string $rel): array
    {
        $dir = Files::resolve($this->paths->home($username), $rel);
        $names = $this->fs->listNames($dir);
        $out = [];
        foreach ($names as $name) {
            if (count($out) >= Files::MAX_LIST) {
                break;
            }
            $full = $dir . '/' . $name;
            $isDir = is_dir($full);
            $out[] = [
                'name' => $name,
                'type' => $isDir ? 'dir' : 'file',
                'size' => $isDir ? 0 : (int) (@filesize($full) ?: 0),
                'mode' => sprintf('%04o', (@fileperms($full) ?: 0) & 0777),
            ];
        }

        return $out;
    }

    /**
     * Folder-wise disk usage under the account home. Symlinks skipped (no escape).
     *
     * @return array{bytes:int, truncated:bool, entries:list<array{name:string,type:string,bytes:int}>}
     */
    public function diskUsage(string $username, string $rel): array
    {
        $root = Files::resolve($this->paths->home($username), $rel);
        if (is_link($root) || !is_dir($root)) {
            throw new RuntimeException('path is not a directory');
        }
        $this->fs->assert($root);

        $nodes = 0;
        $truncated = false;
        $entries = [];
        $bytes = $this->walkUsage($root, $nodes, $truncated, $entries, true);
        usort(
            $entries,
            static fn (array $a, array $b): int => ($b['bytes'] <=> $a['bytes']) ?: strcmp($a['name'], $b['name'])
        );

        return [
            'bytes' => $bytes,
            'truncated' => $truncated,
            'entries' => $entries,
        ];
    }

    /**
     * @param  list<array{name:string,type:string,bytes:int}>  $entries
     */
    private function walkUsage(string $dir, int &$nodes, bool &$truncated, array &$entries, bool $collect): int
    {
        $nodes++;
        if ($nodes > Files::MAX_USAGE_NODES) {
            $truncated = true;

            return 0;
        }
        try {
            $names = $this->fs->listNames($dir);
        } catch (RuntimeException) {
            return 0;
        }
        if (count($names) > Files::MAX_USAGE_CHILDREN) {
            $truncated = true;
            $names = array_slice($names, 0, Files::MAX_USAGE_CHILDREN);
        }
        $sum = 0;
        foreach ($names as $name) {
            if ($nodes >= Files::MAX_USAGE_NODES) {
                $truncated = true;
                break;
            }
            $full = $dir . '/' . $name;
            if (is_link($full)) {
                continue;
            }
            try {
                $this->fs->assert($full);
            } catch (PathGuardException) {
                continue;
            }
            if (is_dir($full)) {
                $nested = [];
                $size = $this->walkUsage($full, $nodes, $truncated, $nested, false);
                if ($collect) {
                    $entries[] = ['name' => $name, 'type' => 'dir', 'bytes' => $size];
                }
                $sum += $size;
                continue;
            }
            if (!is_file($full)) {
                continue;
            }
            $nodes++;
            $size = (int) (@filesize($full) ?: 0);
            if ($collect) {
                $entries[] = ['name' => $name, 'type' => 'file', 'bytes' => $size];
            }
            $sum += $size;
        }

        return $sum;
    }

    public function mkdirFile(string $username, string $rel): string
    {
        $path = Files::resolve($this->paths->home($username), $rel);
        $this->fs->mkdir($path, 0755);
        $this->fs->chownName($path, $username);

        return $rel;
    }

    public function writeFile(string $username, string $rel, string $content): string
    {
        if (strlen($content) > Files::MAX_WRITE) {
            throw new RuntimeException('file too large (256 KiB max)');
        }
        if (str_contains($content, "\0")) {
            throw new RuntimeException('null byte not allowed in file content');
        }
        $path = Files::resolve($this->paths->home($username), $rel);
        if (is_dir($path)) {
            throw new RuntimeException('cannot write to a directory');
        }
        $this->fs->write($path, $content, 0644);
        $this->fs->chownName($path, $username);

        return $rel;
    }

    public function deleteFile(string $username, string $rel): string
    {
        $path = Files::resolve($this->paths->home($username), $rel);
        if (is_dir($path) && !is_link($path)) {
            $this->fs->rmdir($path);
        } else {
            $this->fs->unlink($path);
        }

        return $rel;
    }

    public function renameFile(string $username, string $fromRel, string $toRel): string
    {
        $toRel = Files::normalizeRel($toRel);
        if ($toRel === '') {
            throw new RuntimeException('rename target required');
        }
        $from = Files::resolve($this->paths->home($username), $fromRel);
        $to = Files::resolve($this->paths->home($username), $toRel);
        $this->fs->rename($from, $to);
        $this->fs->chownName($to, $username);

        return $toRel;
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

    public function issueLetsEncrypt(string $username, string $domain, string $docroot, string $email): array
    {
        $realDoc = $this->assertDocrootInHome($username, $docroot);
        $this->fs->mkdir($realDoc, 0755);
        $le = $this->paths->leConfigDir($username);
        $this->fs->mkdir($le . '/work', 0700);
        $this->fs->mkdir($le . '/logs', 0700);
        $this->fs->chownName($le, $username);

        $argv = [
            '/usr/bin/certbot',
            'certonly',
            '--webroot',
            '-w', $realDoc,
            '-d', $domain,
            '--non-interactive',
            '--agree-tos',
            '--config-dir', $le,
            '--work-dir', $le . '/work',
            '--logs-dir', $le . '/logs',
            '--keep-until-expiring',
            '--preferred-challenges', 'http',
        ];
        $email = strtolower(trim($email));
        if ($email !== '') {
            $argv[] = '--email';
            $argv[] = $email;
        } else {
            $argv[] = '--register-unsafely-without-email';
        }

        $result = $this->cmd->run($argv, 90);
        if (!$result->ok()) {
            throw new RuntimeException('certbot failed: ' . trim($result->stderr . ' ' . $result->stdout));
        }

        $liveCert = $le . '/live/' . $domain . '/fullchain.pem';
        $liveKey = $le . '/live/' . $domain . '/privkey.pem';
        if (!$this->fs->isFile($liveCert) || !$this->fs->isFile($liveKey)) {
            throw new RuntimeException('certbot succeeded but live cert missing');
        }

        $dir = $this->paths->sslDir($username, $domain);
        $this->fs->mkdir($dir, 0700);
        $cert = $dir . '/cert.pem';
        $key = $dir . '/privkey.pem';
        $this->fs->write($cert, (string) file_get_contents($this->fs->assert($liveCert)), 0644);
        $this->fs->write($key, (string) file_get_contents($this->fs->assert($liveKey)), 0600);
        $this->fs->chownName($dir, $username);
        $this->fs->chownName($cert, $username);
        $this->fs->chownName($key, $username);

        return [
            'cert' => $cert,
            'key' => $key,
            'not_after' => gmdate('c', time() + (90 * 86400)),
            'issuer' => 'letsencrypt',
        ];
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

    public function writePool(string $username, ?array $directives = null): void
    {
        $directives ??= $this->readUserIni($username);
        $body = AccountTemplates::pool(
            $username,
            $this->paths->home($username),
            $this->paths->socketName($username),
            $directives,
        );
        $this->fs->write($this->paths->pool($username), $body, 0644);
        $this->fs->unlink($this->paths->poolDisabled($username));
    }

    /** @param array<string, string> $directives @return array<string, string> */
    public function setIni(string $username, array $directives): array
    {
        $clean = PhpIni::sanitize($directives);
        $this->writeUserIni($username, $clean);
        $this->writePool($username, $clean);
        $this->reloadPhp();
        $this->log->info('php.ini ' . count($clean) . " keys for {$username}");
        return $clean;
    }

    /** @return array<string, string> */
    public function readUserIni(string $username): array
    {
        $path = $this->paths->phpIniFile($username);
        if (!$this->fs->isFile($path)) {
            return [];
        }
        $body = (string) file_get_contents($this->fs->assert($path));
        try {
            return PhpIni::parseFile($body);
        } catch (TaskRejectedException) {
            return [];
        }
    }

    /** @param array<string, string> $directives */
    public function writeUserIni(string $username, array $directives): void
    {
        $path = $this->paths->phpIniFile($username);
        $this->fs->mkdir(dirname($path), 0750);
        $this->fs->write($path, PhpIni::renderFile($directives), 0640);
        $this->fs->chownName(dirname($path), $username);
        $this->fs->chownName($path, $username);
    }

    /** @param array<string, string> $pages @return array<string, string> */
    public function setErrorPages(string $username, array $pages): array
    {
        $clean = ErrorPages::sanitize($pages);
        $dir = $this->paths->errorpagesDir($username);
        $this->fs->mkdir($dir, 0755);
        $this->fs->chownName($dir, $username);
        foreach (ErrorPages::CODES as $code) {
            $file = $dir . '/' . $code . '.html';
            if (!isset($clean[$code])) {
                $this->fs->unlink($file);
                continue;
            }
            $this->fs->write($file, $clean[$code], 0644);
            $this->fs->chownName($file, $username);
        }
        $confPath = $this->paths->errorpagesConf($username);
        $this->fs->mkdir(dirname($confPath), 0750);
        $this->fs->write(
            $confPath,
            AccountTemplates::errorpagesConf($this->paths->home($username), array_keys($clean)),
            0644,
        );
        $this->fs->chownName(dirname($confPath), $username);
        $this->ensureErrorPagesInclude($username);
        $this->reload($this->paths->apacheService);
        $this->log->info('error pages ' . count($clean) . " codes for {$username}");
        return $clean;
    }

    public function ensureErrorPagesInclude(string $username): void
    {
        $home = $this->paths->home($username);
        $needle = 'IncludeOptional ' . $home . '/etc/errorpages.conf';
        $files = [$this->paths->vhost($username)];
        foreach ($this->listExtraVhosts($username) as $extra) {
            $files[] = $extra;
        }
        foreach ($files as $file) {
            if (!$this->fs->isFile($file)) {
                continue;
            }
            $body = (string) file_get_contents($this->fs->assert($file));
            if (str_contains($body, 'errorpages.conf')) {
                continue;
            }
            if (!str_contains($body, '</VirtualHost>')) {
                continue;
            }
            $body = str_replace('</VirtualHost>', "    {$needle}\n</VirtualHost>", $body);
            $this->fs->write($file, $body, 0644);
        }
    }

    /** @param list<array{handler: string, ext: string}> $mappings @return list<array{handler: string, ext: string}> */
    public function setHandlers(string $username, array $mappings): array
    {
        $clean = Handlers::sanitize($mappings);
        $confPath = $this->paths->handlersConf($username);
        $this->fs->mkdir(dirname($confPath), 0750);
        $this->fs->write(
            $confPath,
            AccountTemplates::handlersConf($clean),
            0644,
        );
        $this->fs->chownName(dirname($confPath), $username);
        $this->ensureHandlersInclude($username);
        $this->reload($this->paths->apacheService);
        $this->log->info('handlers ' . count($clean) . " for {$username}");

        return $clean;
    }

    public function ensureHandlersInclude(string $username): void
    {
        $home = $this->paths->home($username);
        $needle = 'IncludeOptional ' . $home . '/etc/handlers.conf';
        $files = [$this->paths->vhost($username)];
        foreach ($this->listExtraVhosts($username) as $extra) {
            $files[] = $extra;
        }
        foreach ($files as $file) {
            if (!$this->fs->isFile($file)) {
                continue;
            }
            $body = (string) file_get_contents($this->fs->assert($file));
            if (str_contains($body, 'handlers.conf')) {
                continue;
            }
            if (!str_contains($body, '</VirtualHost>')) {
                continue;
            }
            $body = str_replace('</VirtualHost>', "    {$needle}\n</VirtualHost>", $body);
            $this->fs->write($file, $body, 0644);
        }
    }

    /** @param list<array{mime: string, ext: string}> $mappings @return list<array{mime: string, ext: string}> */
    public function setMimeTypes(string $username, array $mappings): array
    {
        $clean = MimeTypes::sanitize($mappings);
        $confPath = $this->paths->mimeConf($username);
        $this->fs->mkdir(dirname($confPath), 0750);
        $this->fs->write(
            $confPath,
            AccountTemplates::mimeConf($clean),
            0644,
        );
        $this->fs->chownName(dirname($confPath), $username);
        $this->ensureMimeInclude($username);
        $this->reload($this->paths->apacheService);
        $this->log->info('mime types ' . count($clean) . " for {$username}");

        return $clean;
    }

    public function ensureMimeInclude(string $username): void
    {
        $home = $this->paths->home($username);
        $needle = 'IncludeOptional ' . $home . '/etc/mime.conf';
        $files = [$this->paths->vhost($username)];
        foreach ($this->listExtraVhosts($username) as $extra) {
            $files[] = $extra;
        }
        foreach ($files as $file) {
            if (!$this->fs->isFile($file)) {
                continue;
            }
            $body = (string) file_get_contents($this->fs->assert($file));
            if (str_contains($body, 'mime.conf')) {
                continue;
            }
            if (!str_contains($body, '</VirtualHost>')) {
                continue;
            }
            $body = str_replace('</VirtualHost>', "    {$needle}\n</VirtualHost>", $body);
            $this->fs->write($file, $body, 0644);
        }
    }

    public function setIndexes(string $username, string $mode): string
    {
        $mode = Indexes::normalize($mode);
        $confPath = $this->paths->indexesConf($username);
        $this->fs->mkdir(dirname($confPath), 0750);
        $this->fs->write(
            $confPath,
            AccountTemplates::indexesConf($this->paths->home($username), $mode),
            0644,
        );
        $this->fs->chownName(dirname($confPath), $username);
        $this->ensureIndexesInclude($username);
        $this->reload($this->paths->apacheService);
        $this->log->info("indexes {$mode} for {$username}");
        return $mode;
    }

    public function ensureIndexesInclude(string $username): void
    {
        $home = $this->paths->home($username);
        $needle = 'IncludeOptional ' . $home . '/etc/indexes.conf';
        $files = [$this->paths->vhost($username)];
        foreach ($this->listExtraVhosts($username) as $extra) {
            $files[] = $extra;
        }
        foreach ($files as $file) {
            if (!$this->fs->isFile($file)) {
                continue;
            }
            $body = (string) file_get_contents($this->fs->assert($file));
            $body = str_replace('Options -Indexes +FollowSymLinks', 'Options +FollowSymLinks', $body);
            if (!str_contains($body, 'indexes.conf') && str_contains($body, '</VirtualHost>')) {
                $body = str_replace('</VirtualHost>', "    {$needle}\n</VirtualHost>", $body);
            }
            $this->fs->write($file, $body, 0644);
        }
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
