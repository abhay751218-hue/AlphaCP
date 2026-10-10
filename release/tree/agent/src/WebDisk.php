<?php

declare(strict_types=1);

namespace Alphacp\Agent;

use Alphacp\Agent\Tasks\TaskContext;

/**
 * WebDisk (cPanel "Web Disk") — account-level WebDAV provisioning (audit B3).
 *
 * cPanel Web Disk = WebDAV se account files desktop/client se access karna.
 * Pehle panel sirf DB row likhta tha (koi asli provisioning nahi). Ab root
 * agent per-account ye cheezein manage karta hai:
 *
 *  - `<home>/etc/webdisk.digest` — Apache Digest-auth credential file
 *    (`login:realm:md5(login:realm:password)`; plaintext password kahin nahi).
 *  - `<home>/etc/webdisk.conf`   — managed Apache snippet: `Alias /webdisk <home>`
 *    + `<Directory>` me `DAV on`, Digest auth, aur read-only accounts ke liye
 *    write methods (`PUT/POST/DELETE/MKCOL/…`) deny.
 *  - vhost me `IncludeOptional <home>/etc/webdisk.conf` (baaki per-account confs
 *    jaisa — privacy/mime/handlers) + apache reload.
 *
 * Source of truth agent-side files hain; panel DB row sirf ownership/permissions
 * cache hai (schema change nahi kiya — password DB me kabhi nahi jata).
 */
final class WebDisk
{
    /** Digest realm — conf aur digest file dono me same hona zaroori hai. */
    public const REALM = 'AlphaCP-WebDisk';

    /** conf me managed entries is comment format me track hoti hain. */
    private const ENTRY_RE = '/^# acp-webdisk login=([a-zA-Z0-9._-]{1,60}) perm=(ro|rw)$/';

    public function __construct(
        private CommandExecutor $cmd,
        private SafeFs $fs,
        private AccountPaths $paths,
        private AccountOs $os,
    ) {
    }

    /**
     * Payload se account validate karke engine banata hai (webdisk.* tasks ka
     * shared bootstrap — username guard + PathGuard + "hamara account" check).
     *
     * @param  array<string, mixed> $payload
     * @return array{string, self}  [username, engine]
     */
    public static function forAccount(array $payload, TaskContext $ctx): array
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('webdisk.* requires PathGuard roots');
        }
        $username = strtolower(trim((string) ($payload['account'] ?? '')));
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        $fs    = new SafeFs($ctx->paths);
        $paths = AccountPaths::fromEnv();
        $os    = new AccountOs($ctx->cmd, $fs, $paths, $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("Linux user '{$username}' is not an AlphaCP account");
        }

        return [$username, new self($ctx->cmd, $fs, $paths, $os)];
    }

    /**
     * Provisioned WebDAV accounts (conf ke managed comments se).
     *
     * @return list<array{login: string, permissions: string}>
     */
    public function entries(string $username): array
    {
        $conf = $this->paths->webdiskConf($username);
        if (!$this->fs->isFile($conf)) {
            return [];
        }
        $out = [];
        foreach (explode("\n", $this->fs->read($conf)) as $line) {
            if (preg_match(self::ENTRY_RE, $line, $m) === 1) {
                $out[] = ['login' => $m[1], 'permissions' => $m[2]];
            }
        }

        return $out;
    }

    /**
     * Account banata ya uska password/permissions reset karta hai
     * (cPanel jaisa upsert: same login dobara = password change).
     */
    public function create(string $username, string $login, string $permissions, string $password): void
    {
        self::assertLogin($login);
        if (!in_array($permissions, ['ro', 'rw'], true)) {
            throw new TaskRejectedException('permissions must be ro or rw');
        }
        if (strlen($password) < 8 || strlen($password) > 128 || preg_match('/^[ -~]+$/', $password) !== 1) {
            throw new TaskRejectedException('password must be 8-128 printable characters');
        }

        $entries = $this->entries($username);
        $digest  = $this->digestMap($username);
        $found   = false;
        foreach ($entries as &$entry) {
            if ($entry['login'] === $login) {
                $entry['permissions'] = $permissions;
                $found = true;
            }
        }
        unset($entry);
        if (!$found) {
            $entries[] = ['login' => $login, 'permissions' => $permissions];
        }
        $digest[$login] = md5($login . ':' . self::REALM . ':' . $password);

        $this->writeAll($username, $entries, $digest);
    }

    /**
     * Account hatata hai; aakhri account delete hone par conf+digest clean
     * (IncludeOptional missing file tolerate karta hai, reload se Alias hat jata hai).
     */
    public function delete(string $username, string $login): void
    {
        self::assertLogin($login);
        $entries = array_values(array_filter(
            $this->entries($username),
            static fn (array $e): bool => $e['login'] !== $login,
        ));
        $digest = $this->digestMap($username);
        unset($digest[$login]);

        if ($entries === []) {
            foreach ([$this->paths->webdiskConf($username), $this->paths->webdiskDigest($username)] as $file) {
                if ($this->fs->isFile($file)) {
                    $this->fs->unlink($file);
                }
            }
            $this->os->reloadApache();

            return;
        }
        $this->writeAll($username, $entries, $digest);
    }

    /** conf + digest likhta hai, vhost include ensure karta hai, apache reload. */
    private function writeAll(string $username, array $entries, array $digest): void
    {
        $etc = $this->paths->home($username) . '/etc';
        $this->fs->mkdir($etc, 0750);
        $this->fs->write($this->paths->webdiskConf($username), $this->render($username, $entries), 0644);

        $lines = [];
        foreach ($entries as $entry) {
            $hash = $digest[$entry['login']] ?? null;
            if ($hash === null) {
                continue; // digest entry ke bina login provision nahi hota
            }
            $lines[] = $entry['login'] . ':' . self::REALM . ':' . $hash;
        }
        $digestFile = $this->paths->webdiskDigest($username);
        $this->fs->write($digestFile, implode("\n", $lines) . "\n", 0640);
        // www-data (apache) ko digest padhna hota hai; hashes ~= password isliye group-only read.
        $this->cmd->run(['/usr/bin/chown', 'root:www-data', $digestFile], 10);

        $this->os->ensureWebDiskInclude($username);
        $this->os->reloadApache();
    }

    /**
     * Managed Apache snippet. Read methods sab authenticated users ke liye;
     * write methods sirf `rw` logins ke liye (khali rw-list = sab denied).
     *
     * @param  list<array{login: string, permissions: string}> $entries
     */
    private function render(string $username, array $entries): string
    {
        $home = $this->paths->home($username);
        $rw = [];
        foreach ($entries as $entry) {
            if ($entry['permissions'] === 'rw') {
                $rw[] = $entry['login'];
            }
        }
        $writeRule = $rw === []
            ? '        Require all denied'
            : '        Require user ' . implode(' ', $rw);

        $lines = ['# AlphaCP WebDisk (WebDAV) — managed by alphacpd; do not edit by hand.'];
        foreach ($entries as $entry) {
            $lines[] = '# acp-webdisk login=' . $entry['login'] . ' perm=' . $entry['permissions'];
        }
        $lines[] = 'Alias /webdisk "' . $home . '"';
        $lines[] = '<Directory "' . $home . '">';
        $lines[] = '    DAV on';
        $lines[] = '    AuthType Digest';
        $lines[] = '    AuthName "' . self::REALM . '"';
        $lines[] = '    AuthDigestProvider file';
        $lines[] = '    AuthUserFile "' . $this->paths->webdiskDigest($username) . '"';
        $lines[] = '    Require valid-user';
        $lines[] = '    <LimitExcept GET HEAD PROPFIND OPTIONS REPORT>';
        $lines[] = $writeRule;
        $lines[] = '    </LimitExcept>';
        $lines[] = '</Directory>';

        return implode("\n", $lines) . "\n";
    }

    /** @return array<string, string> login => digest hash */
    private function digestMap(string $username): array
    {
        $file = $this->paths->webdiskDigest($username);
        if (!$this->fs->isFile($file)) {
            return [];
        }
        $map = [];
        foreach (explode("\n", $this->fs->read($file)) as $line) {
            $parts = explode(':', $line);
            if (count($parts) === 3 && $parts[1] === self::REALM && $parts[2] !== '') {
                $map[$parts[0]] = $parts[2];
            }
        }

        return $map;
    }

    private static function assertLogin(string $login): void
    {
        if (preg_match('/^[a-zA-Z0-9._-]{1,60}$/', $login) !== 1) {
            throw new TaskRejectedException('invalid webdisk login');
        }
    }
}
