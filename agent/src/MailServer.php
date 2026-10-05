<?php
declare(strict_types=1);

namespace Alphacp\Agent;

use Throwable;

/**
 * S7 — Exim4 (MTA) + Dovecot (IMAP/POP3) for the mailboxes the panel already
 * stores as JSON/bcrypt.
 *
 * The panel/agent already write, per account:
 *   ~/etc/mail/passwd   → `user@domain:{BLF-CRYPT}<bcrypt>:uid:gid::/home/u/mail/d/l::quota`
 *   ~/etc/mail/aliases  → `user@domain: destination`
 *   ~/mail/<domain>/<local>/{cur,new,tmp}
 *
 * Until now nothing consumed them. This class aggregates those per-account
 * files into the two files the real daemons read:
 *   /etc/dovecot/alphacp-users      (Dovecot passwd-file passdb+userdb)
 *   /etc/exim4/alphacp-domains      (domains this server accepts mail for)
 *   /etc/exim4/alphacp-recipients   (`user@domain: /maildir uid gid`)
 *   /etc/exim4/alphacp-aliases      (forwarders)
 *
 * Same rules as BindServer:
 *  - config is built in a temp file, validated (`update-exim4.conf` +
 *    `exim4 -bV`, `doveconf`), and only then put in place; a bad config
 *    restores the backup instead of taking the mail server down;
 *  - argv only, never a shell string;
 *  - idempotent — setup()/sync() can run on every update.
 *
 * Server par exim4 chalu karne se pehle dono taraf se check hota hai:
 * config valid (`exim4 -bV`) aur Dovecot wali (`doveconf -n`).
 */
final class MailServer
{
    // ---- binaries (distro ke hisaab se alag jagah ho sakte hain) ----
    public const EXIM = '/usr/sbin/exim4';
    public const DOVECOT = '/usr/sbin/dovecot';
    public const DOVEADM = '/usr/bin/doveadm';
    public const DOVECONF = '/usr/sbin/doveconf';
    public const UPDATE_EXIM = '/usr/sbin/update-exim4.conf';

    public const EXIM_PATHS = ['/usr/sbin/exim4', '/usr/bin/exim4', '/usr/sbin/exim', '/usr/local/sbin/exim4'];
    public const DOVECOT_PATHS = ['/usr/sbin/dovecot', '/usr/bin/dovecot', '/usr/local/sbin/dovecot'];
    public const DOVEADM_PATHS = ['/usr/bin/doveadm', '/usr/sbin/doveadm', '/usr/local/bin/doveadm'];
    public const DOVECONF_PATHS = ['/usr/sbin/doveconf', '/usr/bin/doveconf', '/usr/local/sbin/doveconf'];
    public const UPDATE_EXIM_PATHS = ['/usr/sbin/update-exim4.conf', '/usr/bin/update-exim4.conf'];

    // ---- config files (sab env-overridable: tests kabhi asli /etc ko nahi chhute) ----
    private const DEFAULT_EXIM_TEMPLATE = '/etc/exim4/exim4.conf.template';
    private const DEFAULT_EXIM_DOMAINS = '/etc/exim4/alphacp-domains';
    private const DEFAULT_EXIM_RECIPIENTS = '/etc/exim4/alphacp-recipients';
    private const DEFAULT_EXIM_ALIASES = '/etc/exim4/alphacp-aliases';
    private const DEFAULT_DOVECOT_USERS = '/etc/dovecot/alphacp-users';
    private const DEFAULT_DOVECOT_CONF = '/etc/dovecot/conf.d/99-alphacp.conf';

    private const MANAGED_BEGIN = '# >>> AlphaCP managed (mail.server) — haath se edit mat karo';
    private const MANAGED_END = '# <<< AlphaCP managed (mail.server)';

    public const CMD_TIMEOUT = 60;

    /** @var list<string> */
    private array $tempFiles = [];

    public function __construct(
        private readonly CommandExecutor $cmd,
        private readonly TaskLogger $log,
    ) {
    }

    // -------------------------------------------------------------- paths ----

    public function eximTemplate(): string
    {
        return self::pathEnv('ACP_MAIL_EXIM_TEMPLATE', self::DEFAULT_EXIM_TEMPLATE);
    }

    public function eximTemplateBackup(): string
    {
        return $this->eximTemplate() . '.acp-orig';
    }

    public function domainsFile(): string
    {
        return self::pathEnv('ACP_MAIL_EXIM_DOMAINS', self::DEFAULT_EXIM_DOMAINS);
    }

    public function recipientsFile(): string
    {
        return self::pathEnv('ACP_MAIL_EXIM_RECIPIENTS', self::DEFAULT_EXIM_RECIPIENTS);
    }

    public function aliasesFile(): string
    {
        return self::pathEnv('ACP_MAIL_EXIM_ALIASES', self::DEFAULT_EXIM_ALIASES);
    }

    public function dovecotUsersFile(): string
    {
        return self::pathEnv('ACP_MAIL_DOVECOT_USERS', self::DEFAULT_DOVECOT_USERS);
    }

    public function dovecotConfFile(): string
    {
        return self::pathEnv('ACP_MAIL_DOVECOT_CONF', self::DEFAULT_DOVECOT_CONF);
    }

    private static function pathEnv(string $key, string $default): string
    {
        $value = getenv($key);
        if (!is_string($value) || $value === '' || $value[0] !== '/') {
            return $default;
        }

        return rtrim($value, '/');
    }

    // -------------------------------------------------------------- status ----

    /** @return array<string, mixed> */
    public function status(): array
    {
        $out = [
            'installed'   => $this->installed(),
            'exim'        => null,
            'dovecot'     => null,
            'exim_config' => null,
            'dovecot_config' => null,
            'services'    => ['exim4' => false, 'dovecot' => false],
            'domains'     => 0,
            'mailboxes'   => 0,
            'aliases'     => 0,
        ];
        if (!$out['installed']) {
            return $out + ['error' => 'exim4/dovecot installed nahi hain (updater install karta hai)'];
        }

        $exim = $this->cmd->run([self::bin('ACP_MAIL_EXIM', self::EXIM, self::EXIM_PATHS), '-bV'], self::CMD_TIMEOUT);
        $out['exim'] = $exim->ok() ? self::firstLine($exim->stdout) : self::cleanError($exim);
        $out['exim_config'] = $exim->ok() ? 'ok' : self::cleanError($exim);

        $dov = $this->cmd->run([self::bin('ACP_MAIL_DOVECOT', self::DOVECOT, self::DOVECOT_PATHS), '--version'], self::CMD_TIMEOUT);
        $out['dovecot'] = $dov->ok() ? trim((string) $dov->stdout) : self::cleanError($dov);

        $dovecotConfig = $this->cmd->run([self::bin('ACP_MAIL_DOVECONF', self::DOVECONF, self::DOVECONF_PATHS), '-n'], self::CMD_TIMEOUT);
        $out['dovecot_config'] = $dovecotConfig->ok() ? 'ok' : self::cleanError($dovecotConfig);

        foreach (['exim4', 'dovecot'] as $unit) {
            $res = $this->cmd->run(['/bin/systemctl', 'is-active', $unit], 20);
            $out['services'][$unit] = $res->ok() && trim((string) $res->stdout) === 'active';
        }

        $out['domains'] = count($this->readLines($this->domainsFile()));
        $out['mailboxes'] = count($this->mailboxLines());
        $out['aliases'] = count($this->readLines($this->aliasesFile()));

        return $out;
    }

    public function installed(): bool
    {
        return self::have('ACP_MAIL_EXIM', self::EXIM_PATHS)
            && self::have('ACP_MAIL_DOVECOT', self::DOVECOT_PATHS)
            && self::have('ACP_MAIL_DOVEADM', self::DOVEADM_PATHS);
    }

    // --------------------------------------------------------------- setup ----

    /**
     * Idempotent provisioning: aggregate files, Dovecot conf, Exim template,
     * validate, then start. Runs fine on every update.
     *
     * @return array<string, mixed>
     */
    public function setup(): array
    {
        if (!$this->installed()) {
            throw new TaskRejectedException(
                'exim4/dovecot nahi mile — dhoondha: ' . implode(', ', self::EXIM_PATHS) . ' / '
                . implode(', ', self::DOVECOT_PATHS) . ' (updater install karta hai)'
            );
        }

        $agg = $this->syncFiles();

        // ---- Dovecot: virtual users file + managed conf ----
        $dovecotConf = $this->renderDovecotConf();
        $this->writeManaged($this->dovecotConfFile(), $dovecotConf, 0644);
        $dovecotCheck = $this->cmd->run(
            [self::bin('ACP_MAIL_DOVECONF', self::DOVECONF, self::DOVECONF_PATHS), '-n'],
            self::CMD_TIMEOUT,
        );
        if (!$dovecotCheck->ok()) {
            @unlink($this->dovecotConfFile());
            throw new TaskRejectedException('dovecot config reject: ' . self::cleanError($dovecotCheck));
        }

        // ---- Exim: template (backup ke saath) + generate + validate ----
        if (is_file($this->eximTemplate()) && !is_file($this->eximTemplateBackup())) {
            @copy($this->eximTemplate(), $this->eximTemplateBackup());
        }
        $this->writeManaged($this->eximTemplate(), $this->renderEximTemplate(), 0644);
        $generate = $this->cmd->run(
            [self::bin('ACP_MAIL_UPDATE_EXIM', self::UPDATE_EXIM, self::UPDATE_EXIM_PATHS)],
            self::CMD_TIMEOUT,
        );
        $eximCheck = $this->cmd->run(
            [self::bin('ACP_MAIL_EXIM', self::EXIM, self::EXIM_PATHS), '-bV'],
            self::CMD_TIMEOUT,
        );
        if (!$generate->ok() || !$eximCheck->ok()) {
            $this->restoreEximTemplate();
            throw new TaskRejectedException(
                'exim config reject (purani config wapas): ' . self::cleanError($generate) . ' / ' . self::cleanError($eximCheck)
            );
        }

        foreach (['exim4', 'dovecot'] as $unit) {
            $this->cmd->run(['/bin/systemctl', 'enable', $unit], self::CMD_TIMEOUT);
            $restart = $this->cmd->run(['/bin/systemctl', 'restart', $unit], self::CMD_TIMEOUT);
            if (!$restart->ok()) {
                $this->cmd->run(['/bin/systemctl', 'start', $unit], self::CMD_TIMEOUT);
            }
        }

        $status = $this->status();
        $configured = $this->markConfigured();

        return [
            'ok'          => true,
            'configured'  => $configured,
            'domains'     => $agg['domains'],
            'mailboxes'   => $agg['mailboxes'],
            'aliases'     => $agg['aliases'],
            'exim_config' => $status['exim_config'] ?? null,
            'dovecot_config' => $status['dovecot_config'] ?? null,
            'services'    => $status['services'] ?? [],
        ];
    }

    /**
     * Rebuild the aggregate files from every account (new mailbox, new domain,
     * new forwarder). Daemons read these files per lookup — no restart needed.
     *
     * @return array<string, int>
     */
    public function syncFiles(): array
    {
        $root = AccountPaths::fromEnv()->accountsRoot;
        $users = [];
        $recipients = [];
        $aliases = [];
        $domains = [];

        foreach (glob($root . '/*') ?: [] as $dir) {
            if (!is_dir($dir) || is_link($dir)) {
                continue;
            }
            $username = basename($dir);
            if (AccountIdentity::username($username) !== null) {
                continue;
            }
            $home = $dir;

            foreach ($this->readLines($home . '/etc/mail/passwd') as $line) {
                $fields = explode(':', $line);
                if (count($fields) < 6) {
                    continue;
                }
                $addr = strtolower(trim($fields[0]));
                $uid = trim($fields[2]);
                $gid = trim($fields[3]);
                $maildir = trim($fields[5]);
                $at = strpos($addr, '@');
                if ($at === false || preg_match('/^[a-z0-9._-]+@[a-z0-9.-]+$/', $addr) !== 1) {
                    continue;
                }
                if ($maildir === '' || !str_starts_with($maildir, $home . '/')) {
                    continue;   // doosre account ka maildir kabhi accept nahi
                }
                if (!preg_match('/^[0-9]+$/', $uid) || !preg_match('/^[0-9]+$/', $gid)) {
                    continue;
                }
                $users[$addr] = $line;
                $recipients[$addr] = $addr . ': ' . $maildir . ' ' . $uid . ' ' . $gid;
                $domains[substr($addr, $at + 1)] = true;
            }

            foreach ($this->readLines($home . '/etc/mail/aliases') as $line) {
                $line = trim($line);
                if ($line === '' || !str_contains($line, ':')) {
                    continue;
                }
                [$key, $dest] = explode(':', $line, 2);
                $key = strtolower(trim($key));
                $dest = trim($dest);
                if ($key === '' || $dest === '' || preg_match('/^[a-z0-9._@-]+$/', $key) !== 1) {
                    continue;
                }
                $aliases[$key] = $key . ': ' . $dest;
            }
        }

        ksort($users);
        ksort($recipients);
        ksort($aliases);
        $domainList = array_keys($domains);
        sort($domainList);

        $this->writeManaged($this->dovecotUsersFile(), $users === [] ? '' : implode("\n", $users) . "\n", 0640, 'dovecot');
        $this->writeManaged($this->recipientsFile(), $recipients === [] ? '' : implode("\n", $recipients) . "\n", 0644);
        $this->writeManaged($this->aliasesFile(), $aliases === [] ? '' : implode("\n", $aliases) . "\n", 0644);
        $this->writeManaged($this->domainsFile(), $domainList === [] ? '' : implode("\n", $domainList) . "\n", 0644);

        return [
            'domains'   => count($domainList),
            'mailboxes' => count($users),
            'aliases'   => count($aliases),
        ];
    }

    /**
     * Sachchi tasdeeq: exim ka routing test (`exim4 -bt`) + Dovecot ka user
     * lookup (`doveadm user`). Dono asli daemons se poochhte hain.
     *
     * @return array<string, mixed>
     */
    public function verify(string $address): array
    {
        $address = strtolower(trim($address));
        if (preg_match('/^[a-z0-9._-]+@[a-z0-9.-]+$/', $address) !== 1) {
            throw new TaskRejectedException('invalid email address');
        }
        if (!$this->installed()) {
            throw new TaskRejectedException('exim4/dovecot nahi mile');
        }

        $route = $this->cmd->run(
            [self::bin('ACP_MAIL_EXIM', self::EXIM, self::EXIM_PATHS), '-bt', $address],
            self::CMD_TIMEOUT,
        );
        $user = $this->cmd->run(
            [self::bin('ACP_MAIL_DOVEADM', self::DOVEADM, self::DOVEADM_PATHS), 'user', $address],
            self::CMD_TIMEOUT,
        );

        $routed = $route->ok()
            && !str_contains((string) $route->stdout, 'cannot be resolved')
            && !str_contains((string) $route->stdout, 'Unrouteable address');

        return [
            'address'  => $address,
            'route'    => trim((string) $route->stdout),
            'routed'   => $routed,
            'mailbox'  => trim((string) $user->stdout),
            'has_mailbox' => $user->ok() && trim((string) $user->stdout) !== '',
        ];
    }

    /** @return list<string> */
    public function mailboxes(): array
    {
        $out = [];
        foreach ($this->mailboxLines() as $line) {
            $fields = explode(':', $line);
            $addr = strtolower(trim($fields[0] ?? ''));
            if ($addr !== '') {
                $out[] = $addr;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------ rendering ----

    private function renderDovecotConf(): string
    {
        $users = $this->dovecotUsersFile();
        $lines = [
            self::MANAGED_BEGIN,
            '# AlphaCP S7 — virtual mailboxes jo panel banata hai',
            '# har account ka ~/etc/mail/passwd yahan aggregate hota hai',
            'passdb {',
            '  driver = passwd-file',
            '  args = scheme=BLF-CRYPT ' . $users,
            '}',
            'userdb {',
            '  driver = passwd-file',
            '  args = scheme=BLF-CRYPT ' . $users,
            '}',
            '# mailbox khud Maildir hai (home field usi ko point karta hai)',
            'mail_location = maildir:~/',
            'mail_home = %h',
            'protocols = imap pop3',
            'listen = 127.0.0.1, ::1',
            'disable_plaintext_auth = no',
            'auth_mechanisms = plain login',
            'mail_plugins = $mail_plugins quota',
            'protocol imap {',
            '  mail_plugins = $mail_plugins imap_quota',
            '}',
            'service imap-login {',
            '  inet_listener imap {',
            '    port = 143',
            '  }',
            '}',
            'service pop3-login {',
            '  inet_listener pop3 {',
            '    port = 110',
            '  }',
            '}',
            self::MANAGED_END,
        ];

        return implode("\n", $lines) . "\n";
    }

    private function renderEximTemplate(): string
    {
        $domains = $this->domainsFile();
        $recipients = $this->recipientsFile();
        $aliases = $this->aliasesFile();

        return <<<EXIM
        # AlphaCP managed exim4 configuration (mail.server)
        # Asli distro wali template ki copy: {$this->eximTemplateBackup()}
        # Ye file har update par dobara likhi jati hai — haath se edit mat karo.

        # 25 par sab interfaces (incoming mail isi se aati hai).
        # IPv6 na ho to Exim sirf warning dekar IPv4 par chal deta hai.
        local_interfaces = <; 0.0.0.0 ; ::0
        domainlist local_domains = lsearch;{$domains}
        domainlist relay_to_domains =
        hostlist   relay_from_hosts = 127.0.0.1 : ::1

        acl_smtp_rcpt = acl_check_rcpt
        acl_smtp_data = acl_check_data

        never_users = root
        host_lookup = *
        rfc1413_hosts =
        rfc1413_query_timeout = 0s
        ignore_bounce_errors_after = 2d
        timeout_frozen_after = 7d
        smtp_banner = \$smtp_active_hostname ESMTP AlphaCP
        message_size_limit = 50M

        begin acl

        acl_check_rcpt:
          accept hosts = : +relay_from_hosts

          deny domains = +local_domains
               local_parts = ^[./|] : ^.*[@%!/|`#&?] : ^.*/\\.\\./
               message = restricted characters in address

          accept domains = +local_domains
                 endpass
                 verify = recipient

          deny message = relay not permitted

        acl_check_data:
          accept

        begin routers

        # 1) forwarders (aliases) — pehle ye, phir mailbox
        alphacp_aliases:
          driver = redirect
          domains = +local_domains
          allow_fail
          allow_defer
          qualify_preserve_domain
          retry_use_local_part
          data = \${lookup{\$local_part@\$domain}lsearch{{$aliases}}}

        # 2) asli mailbox -> Maildir (uid/gid mailbox ke hisaab se)
        alphacp_mailbox:
          driver = accept
          domains = +local_domains
          condition = \${if !eq{\${lookup{\$local_part@\$domain}lsearch{{$recipients}}}}{}{yes}{no}}
          transport = alphacp_maildir
          no_more

        # 3) server ke apne system users (root, ubuntu, ...) — /etc/aliases bhi
        system_aliases:
          driver = redirect
          allow_fail
          allow_defer
          data = \${lookup{\$local_part}lsearch{/etc/aliases}}
          file_transport = address_file
          pipe_transport = address_pipe

        local_user:
          driver = accept
          check_local_user
          transport = maildir_home
          cannot_route_message = Unknown user

        # 4) bahar ki duniya
        dnslookup:
          driver = dnslookup
          domains = ! +local_domains
          transport = remote_smtp
          ignore_target_hosts = 0.0.0.0 : 127.0.0.0/8
          no_more

        begin transports

        alphacp_maildir:
          driver = appendfile
          maildir_format
          create_directory = false
          directory = \${extract{1}{ }{\${lookup{\$local_part@\$domain}lsearch{{$recipients}}}}}
          user = \${extract{2}{ }{\${lookup{\$local_part@\$domain}lsearch{{$recipients}}}}}
          group = \${extract{3}{ }{\${lookup{\$local_part@\$domain}lsearch{{$recipients}}}}}
          delivery_date_add
          envelope_to_add
          return_path_add
          mode = 0600

        maildir_home:
          driver = appendfile
          maildir_format
          directory = \$home/Maildir
          delivery_date_add
          envelope_to_add
          return_path_add
          user = \$local_part
          group = mail
          mode = 0600

        address_file:
          driver = appendfile
          delivery_date_add
          envelope_to_add
          return_path_add

        address_pipe:
          driver = pipe
          return_output

        remote_smtp:
          driver = smtp

        begin retry
        *                      *           F,2h,15m; G,16h,1h,1.5; F,4d,6h

        begin rewrite

        begin authenticators

        # S7 slice 2 me: Dovecot SASL ke saath submission (587) + SMTP auth.
        # Tab tak sirf localhost se relay (webmail/Roundcube) — koi open relay nahi.
        EXIM;
    }

    // -------------------------------------------------------------- helpers ----

    /** @return list<string> */
    private function mailboxLines(): array
    {
        return $this->readLines($this->dovecotUsersFile());
    }

    /** @return list<string> */
    private function readLines(string $file): array
    {
        if (is_link($file) || !is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $lines = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** Atomic-ish write: temp file -> rename (kabhi aadhi file nahi banti). */
    private function writeManaged(string $file, string $body, int $mode, ?string $group = null): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $tmp = $dir . '/.acp-mail-' . bin2hex(random_bytes(4)) . '.tmp';
        $this->tempFiles[] = $tmp;
        if (@file_put_contents($tmp, $body) === false) {
            throw new TaskRejectedException("mail config temp file nahi likhi ja saki: {$tmp}");
        }
        @chmod($tmp, $mode);
        if ($group !== null) {
            @chgrp($tmp, $group);
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new TaskRejectedException("mail config file nahi likhi ja saki: {$file}");
        }
        @chmod($file, $mode);
        if ($group !== null) {
            @chgrp($file, $group);
        }
    }

    private function restoreEximTemplate(): void
    {
        if (is_file($this->eximTemplateBackup())) {
            @copy($this->eximTemplateBackup(), $this->eximTemplate());
            $this->cmd->run(
                [self::bin('ACP_MAIL_UPDATE_EXIM', self::UPDATE_EXIM, self::UPDATE_EXIM_PATHS)],
                self::CMD_TIMEOUT,
            );
        }
    }

    /** Updater isi file se janta hai ki mail configure ho chuka hai (services chalu rakhni hain). */
    private function markConfigured(): bool
    {
        $root = rtrim((string) (getenv('ACP_STATE_ROOT') ?: ACP_HOME), '/');
        $dir = $root . '/etc';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . '/mail-server-configured';

        return @file_put_contents($file, 'configured ' . gmdate('c') . "\n") !== false;
    }

    /** @param list<string> $paths */
    private static function bin(string $env, string $default, array $paths): string
    {
        $override = trim((string) (getenv($env) ?: ''));
        if ($override !== '') {
            if ($override[0] !== '/' || str_contains($override, "\0")) {
                throw new TaskRejectedException("invalid {$env} override");
            }

            return $override;
        }
        foreach ($paths as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return $default;
    }

    /** @param list<string> $paths */
    private static function have(string $env, array $paths): bool
    {
        if (trim((string) (getenv($env) ?: '')) !== '') {
            return true;
        }
        foreach ($paths as $path) {
            if (is_executable($path)) {
                return true;
            }
        }

        return false;
    }

    private static function firstLine(string $text): string
    {
        $line = strtok($text, "\n");

        return $line === false ? trim($text) : trim($line);
    }

    private static function cleanError(CommandResult $res): string
    {
        $msg = trim($res->stderr !== '' ? $res->stderr : $res->stdout);
        $msg = preg_replace('/[^\x20-\x7E]+/', ' ', $msg) ?? '';
        $msg = preg_replace('/\s+/', ' ', $msg) ?? '';

        return substr($msg, 0, 300) === '' ? "exit {$res->exitCode}" : substr($msg, 0, 300);
    }
}
