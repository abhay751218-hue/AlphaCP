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
    public const OPENSSL_PATHS = ['/usr/bin/openssl', '/usr/local/bin/openssl'];
    public const SPAMD_PATHS = ['/usr/sbin/spamd', '/usr/bin/spamd', '/usr/local/sbin/spamd'];

    // ---- config files (sab env-overridable: tests kabhi asli /etc ko nahi chhute) ----
    private const DEFAULT_EXIM_TEMPLATE = '/etc/exim4/exim4.conf.template';
    /** Is text se pehchante hain ki template hamari (AlphaCP) hai ya distro wali. */
    private const TEMPLATE_MARKER = 'AlphaCP managed exim4 configuration';
    private const DEFAULT_EXIM_DOMAINS = '/etc/exim4/alphacp-domains';
    private const DEFAULT_EXIM_RECIPIENTS = '/etc/exim4/alphacp-recipients';
    private const DEFAULT_EXIM_ALIASES = '/etc/exim4/alphacp-aliases';
    private const DEFAULT_DOVECOT_USERS = '/etc/dovecot/alphacp-users';
    private const DEFAULT_DOVECOT_CONF = '/etc/dovecot/conf.d/99-alphacp.conf';
    private const DEFAULT_EXIM_CATCHALL = '/etc/exim4/alphacp-catchall';
    private const DEFAULT_VACATION_DIR = '/etc/exim4/alphacp-vacation';
    private const DEFAULT_SPAM_DIR = '/etc/exim4/alphacp-spam';
    private const DEFAULT_DKIM_DIR = '/usr/local/alphacp/etc/mail/dkim';
    private const DEFAULT_EXIM_FILTERS = '/etc/exim4/alphacp-filters';
    private const DEFAULT_EXIM_OPTIONS = '/usr/local/alphacp/etc/mail/exim-options.json';
    private const DEFAULT_DOVECOT_OPTIONS = '/usr/local/alphacp/etc/mail/dovecot-options.json';
    private const DEFAULT_SPAMASSASSIN_CONF = '/etc/spamassassin/local.cf';
    private const DEFAULT_GREYLISTD_SOCKET = '/var/run/greylistd/socket';
    private const SPAMASSASSIN_BEGIN = '# >>> AlphaCP managed SpamAssassin';
    private const SPAMASSASSIN_END = '# <<< AlphaCP managed SpamAssassin';

    /** Exim ke log — distro ke hisaab se alag jagah milte hain (pehla jo mile) */
    private const MAINLOG_CANDIDATES = [
        '/var/log/exim4/mainlog',
        '/var/log/exim/mainlog',
        '/var/log/maillog',
        '/var/log/mail.log',
    ];

    /**
     * Exim Configuration Manager (cPanel #143).
     * key => [type, default] — type se hi value validate hoti hai (fail-closed).
     * Galat value likhne se exim chalu nahi hota, isliye har option ka apna regex hai.
     */
    private const EXIM_OPTION_SPEC = [
        'message_size_limit'         => ['size', '50M'],
        'smtp_banner'                => ['text', '$smtp_active_hostname ESMTP AlphaCP'],
        'smtp_accept_max'            => ['int', '100'],
        'smtp_accept_max_per_host'   => ['int', '10'],
        'queue_run_max'              => ['int', '5'],
        'remote_max_parallel'        => ['int', '2'],
        'timeout_frozen_after'       => ['duration', '7d'],
        'ignore_bounce_errors_after' => ['duration', '2d'],
        'deliver_queue_load_max'     => ['number', '8.0'],
        'queue_only_load'            => ['number', '12.0'],
        'spam_score_limit'           => ['int', '80'],
        'spam_enabled'              => ['bool', 'no'],
        'greylisting'              => ['bool', 'no'],
    ];

    /** Mailserver Configuration / Dovecot (cPanel #144) */
    private const DOVECOT_OPTION_SPEC = [
        'protocols'                   => ['text', 'imap pop3'],
        'mail_max_userip_connections' => ['int', '10'],
        'maildir_copy_with_hardlinks' => ['bool', 'yes'],
        'disable_plaintext_auth'      => ['bool', 'no'],
        'pop3_uidl_format'            => ['text', '%08Xu%08Xv'],
        'imap_idle_notify_interval'   => ['int', '24'],
        'mailbox_idle_check_interval' => ['int', '30'],
        'login_greeting'              => ['text', 'AlphaCP IMAP/POP3 ready.'],
    ];

    private const MANAGED_BEGIN = '# >>> AlphaCP managed (mail.server) — haath se edit mat karo';
    private const MANAGED_END = '# <<< AlphaCP managed (mail.server)';

    public const CMD_TIMEOUT = 60;

    /** DKIM selector (DNS me: <selector>._domainkey.<domain>) */
    public const DKIM_SELECTOR = 'default';

    /** `exim -bf` ko chhota sa test message chahiye (filter validate karne ke liye) */
    private const FILTER_TEST_MESSAGE = "From: alphacp@localhost\nTo: filter-test@localhost\nSubject: filter test\n\nbody\n";

    /** DNS blacklists — sirf header + log (reject nahi: DNS issue par mail nahi gire) */
    private const DNSBL = 'zen.spamhaus.org : bl.spamcop.net';
    /** SPF: is server se mail in records se jaati hai (a/mx zone me maujood hain) */
    private const SPF_RECORD = 'v=spf1 a mx -all';

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

    /** Aakhri KAAM KARNE wali AlphaCP template (config reject hone par yahi wapas). */
    public function eximTemplatePrev(): string
    {
        return $this->eximTemplate() . '.acp-prev';
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

    /** Catch-all (`*@domain: dest`) — mail.catchall task se. */
    public function catchallFile(): string
    {
        return self::firstNonEmpty('ACP_MAIL_CATCHALL', self::DEFAULT_EXIM_CATCHALL);
    }

    /** Vacation (autoresponder) messages: <dir>/<address>.eml + <address>.repeat */
    public function vacationDir(): string
    {
        return self::firstNonEmpty('ACP_MAIL_VACATION_DIR', self::DEFAULT_VACATION_DIR);
    }

    /** Per-mailbox spam lists: <dir>/<address>.deny , <address>.allow */
    public function spamDir(): string
    {
        return self::firstNonEmpty('ACP_MAIL_SPAM_DIR', self::DEFAULT_SPAM_DIR);
    }

    /** DKIM private keys: <dir>/<domain>.key (+ <domain>.pub) */
    public function dkimDir(): string
    {
        return self::firstNonEmpty('ACP_MAIL_DKIM_DIR', self::DEFAULT_DKIM_DIR);
    }

    public function dovecotConfFile(): string
    {
        return self::pathEnv('ACP_MAIL_DOVECOT_CONF', self::DEFAULT_DOVECOT_CONF);
    }

    /** Email filters lookup: `address: /path/to/exim.filter` */
    public function filtersFile(): string
    {
        return self::pathEnv('ACP_MAIL_FILTERS', self::DEFAULT_EXIM_FILTERS);
    }

    public function eximOptionsFile(): string
    {
        return self::pathEnv('ACP_MAIL_EXIM_OPTIONS', self::DEFAULT_EXIM_OPTIONS);
    }

    public function dovecotOptionsFile(): string
    {
        return self::pathEnv('ACP_MAIL_DOVECOT_OPTIONS', self::DEFAULT_DOVECOT_OPTIONS);
    }

    public function spamAssassinConfFile(): string
    {
        return self::pathEnv('ACP_MAIL_SPAMASSASSIN_CONF', self::DEFAULT_SPAMASSASSIN_CONF);
    }

    public function greylistdSocketFile(): string
    {
        $path = self::pathEnv('ACP_MAIL_GREYLISTD_SOCKET', self::DEFAULT_GREYLISTD_SOCKET);
        // This path is embedded inside an Exim expansion (not shell-escaped),
        // so accept only ordinary absolute Unix-socket path characters.
        if (preg_match('#^/[A-Za-z0-9_./-]{1,220}$#D', $path) !== 1) {
            return self::DEFAULT_GREYLISTD_SOCKET;
        }

        return $path;
    }

    /** Exim mainlog (mail delivery reports isi se bante hain). Na mile to null. */
    public function mainlogFile(): ?string
    {
        $env = self::pathEnv('ACP_MAIL_MAINLOG', '');
        $candidates = $env !== '' ? [$env] : self::MAINLOG_CANDIDATES;
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
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

        $out['spam'] = $this->spamStatus();
        $out['domains'] = count($this->readLines($this->domainsFile()));
        $out['mailboxes'] = count($this->mailboxLines());
        $out['aliases'] = count($this->readLines($this->aliasesFile()));

        return $out;
    }

    /**
     * Apache SpamAssassin + greylistd (cPanel #147).
     *
     * Payload: enabled?, required_score? (1.0-15.0), reject_score? (0=tag only),
     *          greylisting?. Service units are enabled only when their feature is
     * turned on; Exim uses /defer_ok so a later daemon outage cannot stop mail.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function spamAssassin(array $payload): array
    {
        if (!$this->installed()) {
            throw new TaskRejectedException('exim4/dovecot installed nahi hain — pehle mail.server setup chalao');
        }
        if (!self::isConfigured()) {
            throw new TaskRejectedException('mail.server setup pehle safal hona chahiye');
        }

        $current = $this->eximOptions();
        $enabled = array_key_exists('enabled', $payload) ? self::truthy($payload['enabled']) : null;
        $greylisting = array_key_exists('greylisting', $payload) ? self::truthy($payload['greylisting']) : null;
        $requiredScore = array_key_exists('required_score', $payload) ? (float) $payload['required_score'] : null;
        $rejectScore = array_key_exists('reject_score', $payload) ? (float) $payload['reject_score'] : null;

        if ($requiredScore !== null && (!is_finite($requiredScore) || $requiredScore < 1.0 || $requiredScore > 15.0)) {
            throw new TaskRejectedException('required_score 1.0 se 15.0 ke beech hona chahiye');
        }
        if ($rejectScore !== null && (!is_finite($rejectScore) || $rejectScore < 0.0 || $rejectScore > 30.0)) {
            throw new TaskRejectedException('reject_score 0.0 se 30.0 ke beech hona chahiye (0 = sirf header tag)');
        }

        // Read-only call: no package/service/file changes.
        if ($enabled === null && $greylisting === null && $requiredScore === null && $rejectScore === null) {
            return [
                'saved'       => [],
                'exim_config' => null,
                'spam'        => $this->spamStatus(),
                'status'      => 'ok',
            ];
        }

        $manageSpamService = $enabled === true
            || ($enabled === null && $current['spam_enabled'] === 'yes'
                && ($requiredScore !== null || $rejectScore !== null));
        $wantGreylisting = $greylisting ?? ($current['greylisting'] === 'yes');
        $set = [];
        if ($enabled !== null) {
            $set['spam_enabled'] = $enabled ? 'yes' : 'no';
        }
        if ($greylisting !== null) {
            $set['greylisting'] = $greylisting ? 'yes' : 'no';
        }
        if ($rejectScore !== null) {
            $set['spam_score_limit'] = (string) (int) round($rejectScore * 10);
        }

        $caps = $this->capabilities();
        if ($manageSpamService && !$caps['spamd']) {
            throw new TaskRejectedException('SpamAssassin/spamd installed nahi hai — `spamassassin` package chahiye');
        }
        if ($manageSpamService && !$caps['content_scanning']) {
            throw new TaskRejectedException('Exim me Content_Scanning nahi hai — `exim4-daemon-heavy` chahiye; mail config nahi badli');
        }
        $spamConf = $this->spamAssassinConfFile();
        if ($requiredScore !== null && !$caps['spamd']) {
            throw new TaskRejectedException('required_score ke liye SpamAssassin/spamd package chahiye');
        }
        if ($requiredScore !== null && !is_dir(dirname($spamConf))) {
            throw new TaskRejectedException('SpamAssassin installed nahi hai — /etc/spamassassin directory nahi mili');
        }

        $oldOptions = is_file($this->eximOptionsFile()) ? (string) @file_get_contents($this->eximOptionsFile()) : null;
        $oldSpamConf = $requiredScore !== null && is_file($spamConf) ? (string) @file_get_contents($spamConf) : null;
        $oldSpamMode = $requiredScore !== null && is_file($spamConf) ? (((int) @fileperms($spamConf)) & 0777) : 0644;
        $started = [];
        $restartedExisting = [];
        $warnings = [];

        try {
            // local.cf is read at daemon startup. Write it BEFORE first start;
            // if spamd was already running, restart it so the score takes effect.
            if ($requiredScore !== null) {
                $this->writeSpamAssassinConf($requiredScore);
            }
            if ($manageSpamService) {
                $started['spamassassin'] = $this->startManagedService('spamassassin');
                if ($requiredScore !== null && !$started['spamassassin']) {
                    $restartedExisting['spamassassin'] = true;
                    $this->restartManagedService('spamassassin');
                }
            }
            if ($wantGreylisting) {
                $started['greylistd'] = $this->startManagedService('greylistd');
                if (!file_exists($this->greylistdSocketFile())) {
                    throw new TaskRejectedException('greylistd active hai lekin Unix socket nahi mila — greylisting enable nahi ki');
                }
            }

            // eximConf() validates all options, writes the JSON, regenerates the
            // template, runs -bV + -bt smoke tests, then restarts Exim.
            $applied = ['exim_config' => null];
            if ($set !== []) {
                $res = $this->applyEximOptionSet($set);
                $applied = ['exim_config' => $res['exim_config'] ?? null];
            }
        } catch (Throwable $e) {
            $rollbackErrors = [];
            if ($requiredScore !== null) {
                try {
                    $this->restoreFileContents($spamConf, $oldSpamConf, $oldSpamMode);
                } catch (Throwable $rollback) {
                    $rollbackErrors[] = 'local.cf: ' . $rollback->getMessage();
                }
            }
            if ($set !== []) {
                try {
                    $this->restoreFileContents($this->eximOptionsFile(), $oldOptions, 0644);
                } catch (Throwable $rollback) {
                    $rollbackErrors[] = 'exim options: ' . $rollback->getMessage();
                }
            }
            foreach ($restartedExisting as $unit => $_) {
                try {
                    $this->restartManagedService($unit); // local.cf has its old bytes again
                } catch (Throwable $rollback) {
                    $rollbackErrors[] = $unit . ' old config reload: ' . $rollback->getMessage();
                }
            }
            foreach ($started as $unit => $newlyStarted) {
                if ($newlyStarted) {
                    $this->stopManagedService($unit);
                }
            }
            $suffix = $rollbackErrors === [] ? '' : ' (rollback warning: ' . implode('; ', $rollbackErrors) . ')';
            if ($e instanceof TaskRejectedException) {
                throw new TaskRejectedException($e->getMessage() . $suffix, 0, $e);
            }
            throw new TaskRejectedException('SpamAssassin/greylisting safe apply fail: ' . $e->getMessage() . $suffix, 0, $e);
        }

        // ACL is already removed/disabled and Exim validated before its daemon is
        // stopped; a greylist/spam service is never stopped while Exim still asks it.
        if ($enabled === false) {
            $warnings = array_merge($warnings, $this->stopManagedService('spamassassin'));
        }
        if ($greylisting === false) {
            $warnings = array_merge($warnings, $this->stopManagedService('greylistd'));
        }

        $spamStatus = $this->spamStatus();
        if ($spamStatus['enabled'] && !$spamStatus['active']) {
            $warnings[] = 'spamd/Exim content-scanning abhi active nahi; Exim mail ko fail-open deliver karega, SpamAssassin scan nahi hoga';
        }
        if ($spamStatus['greylisting'] && !$spamStatus['greylisting_active']) {
            $warnings[] = 'greylistd service/socket abhi active nahi; greylisting fail-open hai';
        }

        return [
            'saved'       => $set,
            'exim_config' => $applied['exim_config'] ?? null,
            'spam'        => $spamStatus,
            'warnings'    => $warnings,
            'status'      => 'ok',
        ];
    }

    /** @return array<string, mixed> */
    public function spamStatus(): array
    {
        $opts = $this->eximOptions();
        $caps = $this->capabilities();
        $spamdServiceActive = $this->serviceActive('spamassassin');
        $spamdRunning = $spamdServiceActive && $this->spamdListening();
        $greySocket = file_exists($this->greylistdSocketFile());
        $greylistdServiceActive = $this->serviceActive('greylistd');
        $greylistdRunning = $greylistdServiceActive && $greySocket;

        return [
            'spamd_installed'  => $caps['spamd'],
            'spamd_running'    => $spamdRunning,
            'content_scanning' => $caps['content_scanning'],
            'enabled'          => $opts['spam_enabled'] === 'yes',
            'active'           => $opts['spam_enabled'] === 'yes'
                && $caps['spamd']
                && $caps['content_scanning']
                && $spamdRunning,
            'required_score'   => (float) $this->localCfScore(),
            'reject_score'     => ((int) $opts['spam_score_limit']) / 10,
            'greylisting'      => $opts['greylisting'] === 'yes',
            'greylisting_active' => $opts['greylisting'] === 'yes' && $greylistdRunning,
            'greylistd_running' => $greylistdServiceActive,
            'greylistd_socket'  => $greySocket,
        ];
    }

    /** Write a managed SpamAssassin block while preserving all unrelated local.cf lines. */
    private function writeSpamAssassinConf(float $requiredScore): void
    {
        $file = $this->spamAssassinConfFile();
        if (!is_dir(dirname($file))) {
            throw new TaskRejectedException('SpamAssassin config directory nahi mili: ' . dirname($file));
        }
        $score = number_format($requiredScore, 1, '.', '');
        $old = is_file($file) ? (string) @file_get_contents($file) : '';
        $kept = $old;
        $begin = strpos($old, self::SPAMASSASSIN_BEGIN);
        $end = strpos($old, self::SPAMASSASSIN_END);
        if ($begin !== false && $end !== false && $end >= $begin) {
            $kept = substr($old, 0, $begin)
                . substr($old, $end + strlen(self::SPAMASSASSIN_END));
        }
        if ($kept !== '' && !str_ends_with($kept, "\n")) {
            $kept .= "\n";
        }
        $body = self::SPAMASSASSIN_BEGIN . "\n"
            . '# AlphaCP SpamAssassin settings — haath se edit mat karo' . "\n"
            . 'required_score ' . $score . "\n"
            . 'rewrite_header Subject [SPAM]' . "\n"
            . 'report_safe 0' . "\n"
            . self::SPAMASSASSIN_END . "\n";
        $this->writeManaged($file, $kept . $body, 0644);
    }

    /** Last matching required_score wins in SpamAssassin; mirror that for status. */
    private function localCfScore(): string
    {
        $file = $this->spamAssassinConfFile();
        if (!is_file($file)) {
            return '5.0';
        }
        $score = null;
        foreach ($this->readLines($file) as $line) {
            if (preg_match('/^\s*required_score\s+([0-9]+(?:\.[0-9]+)?)/', $line, $m) === 1) {
                $score = $m[1];
            }
        }

        return $score ?? '5.0';
    }

    /** PHP bool / "yes" / 1 / "true" -> bool. */
    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value > 0;
        }
        $text = strtolower(trim((string) $value));

        return in_array($text, ['1', 'yes', 'true', 'on'], true);
    }

    /** systemctl's explicit state, not a best-effort PHP socket guess. */
    private function serviceActive(string $unit): bool
    {
        $res = $this->cmd->run(['/bin/systemctl', 'is-active', $unit], 20);

        return $res->ok() && trim((string) $res->stdout) === 'active';
    }

    /** Enable and start a private local mail helper; return whether we started it. */
    private function startManagedService(string $unit): bool
    {
        $wasActive = $this->serviceActive($unit);
        try {
            $enable = $this->cmd->run(['/bin/systemctl', 'enable', $unit], self::CMD_TIMEOUT);
            if (!$enable->ok()) {
                throw new TaskRejectedException("{$unit} enable fail: " . self::cleanError($enable));
            }
            if (!$wasActive) {
                $start = $this->cmd->run(['/bin/systemctl', 'start', $unit], self::CMD_TIMEOUT);
                if (!$start->ok()) {
                    throw new TaskRejectedException("{$unit} start fail: " . self::cleanError($start));
                }
            }
            if (!$this->serviceActive($unit)) {
                throw new TaskRejectedException("{$unit} service active nahi hua");
            }
        } catch (Throwable $e) {
            if (!$wasActive) {
                $this->stopManagedService($unit);
            }
            throw $e;
        }

        return !$wasActive;
    }

    /** Restart an already-active local helper after its configuration changed. */
    private function restartManagedService(string $unit): void
    {
        $res = $this->cmd->run(['/bin/systemctl', 'restart', $unit], self::CMD_TIMEOUT);
        if (!$res->ok() || !$this->serviceActive($unit)) {
            throw new TaskRejectedException("{$unit} restart fail: " . self::cleanError($res));
        }
    }

    /** Stop+disable only after the Exim ACL no longer depends on the unit. @return list<string> */
    private function stopManagedService(string $unit): array
    {
        $warnings = [];
        foreach (['stop', 'disable'] as $verb) {
            try {
                $res = $this->cmd->run(['/bin/systemctl', $verb, $unit], self::CMD_TIMEOUT);
                if (!$res->ok()) {
                    $warnings[] = "{$unit} {$verb} failed: " . self::cleanError($res);
                }
            } catch (Throwable $e) {
                $warnings[] = "{$unit} {$verb} failed: " . $e->getMessage();
            }
        }

        return $warnings;
    }

    /** Restore bytes/mode exactly after a failed multi-file SpamAssassin apply. */
    private function restoreFileContents(string $file, ?string $contents, int $mode): void
    {
        if ($contents === null) {
            if (is_file($file) && !@unlink($file)) {
                throw new TaskRejectedException('rollback file remove nahi hui: ' . $file);
            }

            return;
        }
        $this->writeManaged($file, $contents, $mode > 0 ? $mode : 0644);
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
        $this->snapshotEximTemplate();
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

        // Sachchi routing tasdeeq: exim khud ek address route kare. Config syntax
        // theek hone ke baawajood router ki expansion kharaab ho sakti hai
        // (0.78.0 ka asli bug) — tab purani template wapas aur setup reject.
        $smoke = $this->eximSmokeTest();
        if ($smoke !== null) {
            $this->restoreEximTemplate();
            throw new TaskRejectedException('exim routing smoke test fail (purani config wapas): ' . $smoke);
        }

        // Exim daemon ko root chahiye (mailbox ki uid se delivery ke liye).
        // Unit me User=Debian-exim ho to drop-in se root kar do — cPanel/Hestia
        // bhi exim ko root chalate hain, warna `user=` transport kaam nahi karta.
        $this->ensureEximRunsAsRoot();

        foreach (['exim4', 'dovecot'] as $unit) {
            $this->cmd->run(['/bin/systemctl', 'enable', $unit], self::CMD_TIMEOUT);
            $restart = $this->cmd->run(['/bin/systemctl', 'restart', $unit], self::CMD_TIMEOUT);
            if (!$restart->ok()) {
                $this->cmd->run(['/bin/systemctl', 'start', $unit], self::CMD_TIMEOUT);
            }
        }

        // Sachchi tasdeeq: Dovecot khud bole ki mailbox mili ya nahi.
        // File sirf group-readable ho aur auth worker use na padh paye to yahi
        // pakad me aata hai — tab hum mode relax karke dobara check karte hain.
        $userdb = $this->probeDovecotUserdb();

        $status = $this->status();
        $configured = $this->markConfigured();

        return [
            'ok'          => true,
            'configured'  => $configured,
            'domains'     => $agg['domains'],
            'mailboxes'   => $agg['mailboxes'],
            'aliases'     => $agg['aliases'],
            'lists'       => $agg['lists'],
            'list_errors' => $agg['list_errors'],
            'filters'     => $agg['filters'],
            'filter_errors' => $agg['filter_errors'],
            'exim_config' => $status['exim_config'] ?? null,
            'exim_smoke'  => $smoke === null ? 'ok' : 'fail',
            'dovecot_config' => $status['dovecot_config'] ?? null,
            'dovecot_userdb' => $userdb,
            'services'    => $status['services'] ?? [],
        ];
    }

    /** Live service check for the status response only; Exim ACL remains fail-open. */
    public function spamdListening(): bool
    {
        $sock = @fsockopen('127.0.0.1', 783, $errno, $errstr, 1.0);
        if ($sock === false) {
            return false;
        }
        fclose($sock);

        return true;
    }

    /** Exim ka int score (80) -> insani number (8.0). */
    public static function scoreHuman(int $score): string
    {
        return number_format($score / 10, 1, '.', '');
    }

    /**
     * Exim kya-kya support karta hai (`exim4 -bV` khud batata hai).
     * DKIM signing aur SpamAssassin isi se gate hote hain — jo cheez binary
     * support na kare, wo config me likhi hi nahi jati (fail-closed).
     *
     * @return array{dkim: bool, content_scanning: bool, spamd: bool}
     */
    public function capabilities(): array
    {
        $bin = self::bin('ACP_MAIL_EXIM', self::EXIM, self::EXIM_PATHS);
        try {
            $res = $this->cmd->run([$bin, '-bV'], self::CMD_TIMEOUT);
        } catch (\RuntimeException) {
            // exim installed hi nahi (ya allowlist me nahi) — sab features band
            // (fail-closed): config me DKIM/spam likha hi nahi jayega, par poora
            // render/STATUS kaam karta rahega (pehle yahan fatal throw hota tha).
            return ['dkim' => false, 'content_scanning' => false, 'spamd' => false];
        }
        $text = trim($res->stdout . ' ' . $res->stderr);
        if ($text === '') {
            return ['dkim' => false, 'content_scanning' => false, 'spamd' => false];
        }

        return [
            'dkim'             => str_contains($text, 'DKIM'),
            'content_scanning' => str_contains($text, 'Content_Scanning'),
            'spamd'            => self::have('ACP_MAIL_SPAMD', self::SPAMD_PATHS),
        ];
    }

    /**
     * Mail task (mail.set / mail.forward / mail.catchall / mail.autorespond /
     * mail.spam) ke turant baad: aggregate files dobara banao, taki naya
     * mailbox/forwarder bina kisi alag command ke kaam kare.
     * Mail server configured nahi hai to chupchap skip (task fail nahi hota).
     */
    public static function syncIfConfigured(CommandExecutor $cmd, TaskLogger $log): string
    {
        if (!self::isConfigured()) {
            return 'skipped (mail server configured nahi hai — mail.server setup pehle)';
        }
        try {
            $out = (new self($cmd, $log))->syncFiles();

            return 'ok (' . (int) ($out['mailboxes'] ?? 0) . ' mailboxes, '
                . (int) ($out['aliases'] ?? 0) . ' aliases including '
                . (int) ($out['lists'] ?? 0) . ' active lists; '
                . (int) ($out['list_errors'] ?? 0) . ' list errors)';
        } catch (Throwable $e) {
            // primary task ka kaam ho chuka hai — sync ki wajah se use fail nahi karte
            $log->info('mail sync after task failed: ' . $e->getMessage());

            return 'failed: ' . $e->getMessage();
        }
    }

    /** Updater/task isi se puchhte hain: mail server configure ho chuka hai? */
    public static function isConfigured(): bool
    {
        $root = rtrim((string) (getenv('ACP_STATE_ROOT') ?: ACP_HOME), '/');

        return is_file($root . '/etc/mail-server-configured');
    }

    /**
     * mail.deliverability ko asli banana: SPF + DMARC (+ DKIM) records account
     * ki zone me likh kar BIND ko dobara likhna — S9 live hai to `dig` se
     * turant dikh jata hai (hamara daawa nahi, DNS ka jawab).
     *
     * @return array<string, mixed>
     */
    public function deliverability(?string $username = null): array
    {
        $root = AccountPaths::fromEnv()->accountsRoot;
        $homes = [];
        if ($username !== null) {
            $name = strtolower(trim($username));
            if (AccountIdentity::username($name) === null && is_dir($root . '/' . $name)) {
                $homes[] = $root . '/' . $name;
            }
        } else {
            foreach (glob($root . '/*') ?: [] as $dir) {
                if (!is_dir($dir) || is_link($dir)) {
                    continue;
                }
                if (AccountIdentity::username(basename($dir)) !== null) {
                    continue;
                }
                $homes[] = $dir;
            }
        }

        $done = [];
        $failed = [];
        foreach ($homes as $home) {
            $user = basename($home);
            $file = $home . '/etc/mail/deliverability.json';
            // deliverability.json na ho to bhi aage: mailboxes ke domains se kaam chalega
            if (!is_file($home . '/etc/mail/passwd') && (is_link($file) || !is_file($file))) {
                continue;
            }
            $wanted = [];
            $rows = (is_file($file) && !is_link($file)) ? json_decode((string) @file_get_contents($file), true) : null;
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $domain = strtolower(trim((string) (is_array($row) ? ($row['domain'] ?? '') : (is_string($row) ? $row : ''))));
                    if ($domain !== '' && Dns::validDomain($domain)) {
                        $wanted[$domain] = true;
                    }
                }
            }
            // panel ki "Email Deliverability" page khuli na ho to deliverability.json
            // banta hi nahi — par jin domains ke mailbox hain unhe SPF/DKIM/DMARC
            // chahiye. Isliye mailboxes ke domains bhi jodo (cPanel yahi karta hai).
            foreach ($this->readLines($home . '/etc/mail/passwd') as $line) {
                $fields = explode(':', $line);
                if (count($fields) < 6) {
                    continue;
                }
                $addr = strtolower(trim($fields[0]));
                $at = strpos($addr, '@');
                if ($at === false || preg_match('/^[a-z0-9._-]+@[a-z0-9.-]+$/', $addr) !== 1) {
                    continue;
                }
                $domain = substr($addr, $at + 1);
                if (Dns::validDomain($domain)) {
                    $wanted[$domain] = true;
                }
            }
            ksort($wanted);
            foreach (array_keys($wanted) as $domain) {
                try {
                    $done[] = $this->applyDeliverability($home, $user, (string) $domain);
                } catch (Throwable $e) {
                    $failed[] = ['domain' => (string) $domain, 'error' => $e->getMessage()];
                }
            }
        }

        return [
            'ok'      => $failed === [],
            'domains' => $done,
            'failed'  => $failed,
            'count'   => count($done),
        ];
    }

    /**
     * Ek domain ke liye: DKIM key (agar nahi to banao) + zone me SPF/DMARC/DKIM
     * records + BIND zone dobara likho.
     *
     * @return array<string, mixed>
     */
    private function applyDeliverability(string $home, string $user, string $domain): array
    {
        $dkim = $this->ensureDkimKey($domain);
        $selector = (string) ($dkim['selector'] ?? self::DKIM_SELECTOR);

        $records = [
            ['domain' => $domain, 'name' => '@', 'type' => 'TXT', 'value' => self::SPF_RECORD],
            ['domain' => $domain, 'name' => '_dmarc', 'type' => 'TXT', 'value' => self::dmarcRecord($domain)],
        ];
        if (is_string($dkim['public'] ?? null) && $dkim['public'] !== '') {
            $records[] = [
                'domain' => $domain,
                'name'   => $selector . '._domainkey',
                'type'   => 'TXT',
                'value'  => 'v=DKIM1; k=rsa; p=' . $dkim['public'],
            ];
        }

        // zone.json: purane SPF/DMARC/DKIM records hatakar naye daalo
        $zoneFile = $home . '/etc/dns/zone.json';
        $existing = [];
        if (is_file($zoneFile) && !is_link($zoneFile)) {
            $decoded = json_decode((string) @file_get_contents($zoneFile), true);
            if (is_array($decoded)) {
                $existing = array_values($decoded);
            }
        }
        $keep = [];
        foreach ($existing as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowDomain = strtolower(trim((string) ($row['domain'] ?? '')));
            $rowName = strtolower(trim((string) ($row['name'] ?? '')));
            $rowType = strtoupper(trim((string) ($row['type'] ?? '')));
            $rowValue = trim((string) ($row['value'] ?? ''));
            if ($rowDomain === $domain && $rowType === 'TXT') {
                $ours = $rowName === '_dmarc'
                    || $rowName === $selector . '._domainkey'
                    || ($rowName === '@' && str_starts_with($rowValue, 'v=spf1'));
                if ($ours) {
                    continue;   // hum inhe abhi dobara likhte hain
                }
            }
            $keep[] = $row;
        }

        $merged = Dns::sanitize(array_merge($keep, $records));
        $dir = dirname($zoneFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $tmp = $dir . '/.zone-' . bin2hex(random_bytes(4)) . '.tmp';
        $body = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (@file_put_contents($tmp, $body) === false || !@rename($tmp, $zoneFile)) {
            @unlink($tmp);
            throw new TaskRejectedException("zone.json nahi likh paye ({$domain})");
        }
        @chmod($zoneFile, 0640);
        @chown($zoneFile, $user);
        @chgrp($zoneFile, $user);

        // BIND live hai to zone turant dobara likhi jayegi (`dig` se verify)
        $dns = ['applied' => false];
        $bind = new BindServer($this->cmd, $this->log);
        if ($bind->installed()) {
            try {
                $out = $bind->writeZone($domain, $merged);
                $dns = [
                    'applied'  => true,
                    'records'  => (int) ($out['records'] ?? 0),
                    'verified' => (bool) ($out['verified'] ?? false),
                ];
            } catch (Throwable $e) {
                $dns = ['applied' => false, 'error' => $e->getMessage()];
            }
        }

        return [
            'domain'    => $domain,
            'dkim'      => is_string($dkim['public'] ?? null) && $dkim['public'] !== '',
            'selector'  => $selector,
            'dns'       => $dns,
            'records'   => count($merged),
        ];
    }

    /**
     * DKIM key: hai to wahi, nahi to `openssl genrsa` se banao (2048-bit).
     * openssl na mile to key nahi — tab sirf SPF/DMARC likhe jate hain
     * (kabhi jhootha "DKIM on" nahi bataya jata).
     *
     * @return array{selector: string, public: string|null, key: string|null}
     */
    private function ensureDkimKey(string $domain): array
    {
        $dir = $this->dkimDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $keyFile = $dir . '/' . $domain . '.key';
        $pubFile = $dir . '/' . $domain . '.pub';

        if (!is_file($keyFile)) {
            $openssl = self::bin('ACP_MAIL_OPENSSL', '/usr/bin/openssl', self::OPENSSL_PATHS);
            if (!is_executable($openssl)) {
                return ['selector' => self::DKIM_SELECTOR, 'public' => null, 'key' => null];
            }
            $tmp = $dir . '/.dkim-' . bin2hex(random_bytes(4)) . '.tmp';
            $made = $this->cmd->run([$openssl, 'genrsa', '-out', $tmp, '2048'], 120);
            if ($made->exitCode !== 0 || !is_file($tmp)) {
                @unlink($tmp);

                return ['selector' => self::DKIM_SELECTOR, 'public' => null, 'key' => null];
            }
            @rename($tmp, $keyFile);
            @chmod($keyFile, 0640);
            @chgrp($keyFile, 'Debian-exim');
            $pub = $this->cmd->run([$openssl, 'rsa', '-in', $keyFile, '-pubout', '-out', $pubFile], 60);
            if ($pub->exitCode !== 0 || !is_file($pubFile)) {
                return ['selector' => self::DKIM_SELECTOR, 'public' => null, 'key' => $keyFile];
            }
            @chmod($pubFile, 0644);
        } elseif (!is_file($pubFile)) {
            $openssl = self::bin('ACP_MAIL_OPENSSL', '/usr/bin/openssl', self::OPENSSL_PATHS);
            $pub = $this->cmd->run([$openssl, 'rsa', '-in', $keyFile, '-pubout', '-out', $pubFile], 60);
            if ($pub->exitCode !== 0 || !is_file($pubFile)) {
                return ['selector' => self::DKIM_SELECTOR, 'public' => null, 'key' => $keyFile];
            }
        }

        $pem = (string) @file_get_contents($pubFile);
        $b64 = (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);
        if ($b64 === '' || preg_match('/^[A-Za-z0-9+\/=]+$/', $b64) !== 1) {
            return ['selector' => self::DKIM_SELECTOR, 'public' => null, 'key' => $keyFile];
        }

        return ['selector' => self::DKIM_SELECTOR, 'public' => $b64, 'key' => $keyFile];
    }

    private static function dmarcRecord(string $domain): string
    {
        return 'v=DMARC1; p=quarantine; adkim=r; aspf=r; rua=mailto:postmaster@' . $domain;
    }

    /**
     * Har account ke ~/etc/mail/* se daemon files banao. Idempotent — kabhi bhi
     * chala sakte ho; exim/dovecot ko restart karne ki zaroorat nahi (dono file
     * har message/auth par padhte hain).
     *
     * @return array<string, mixed>
     */
    public function syncFiles(): array
    {
        $root = AccountPaths::fromEnv()->accountsRoot;
        $users = [];
        $recipients = [];
        $aliases = [];
        $listAliases = [];
        $listConflicts = [];
        $listErrors = 0;
        $catchalls = [];
        $domains = [];
        $vacation = [];
        $spam = [];
        $filters = [];
        $filterErrors = [];

        foreach (glob($root . '/*') ?: [] as $dir) {
            if (!is_dir($dir) || is_link($dir)) {
                continue;
            }
            $username = basename($dir);
            if (AccountIdentity::username($username) !== null) {
                continue;
            }
            $home = $dir;
            $ownBoxes = [];
            $boxInfo = [];

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
                $ownBoxes[$addr] = true;
                $boxInfo[$addr] = ['maildir' => $maildir, 'uid' => $uid, 'gid' => $gid];
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
                if (preg_match('/^[a-z0-9._@-]+$/', $dest) !== 1) {
                    continue;   // pipe/command nahi — sirf email address
                }
                $aliases[$key] = $key . ': ' . $dest;
            }

            // ---- static mailing-list members -> Exim redirect expansion ----
            $listsFile = $home . '/etc/mail/lists.json';
            if (is_link($listsFile)) {
                $listErrors++;
            } else {
                foreach ($this->readJsonList($listsFile) as $rawList) {
                    if (!is_array($rawList)) {
                        $listErrors++;
                        continue;
                    }
                    try {
                        $cleanList = Mail::sanitizeLists([$rawList])[0] ?? null;
                    } catch (TaskRejectedException) {
                        $listErrors++;
                        continue;
                    }
                    if (!is_array($cleanList)) {
                        $listErrors++;
                        continue;
                    }
                    $address = $cleanList['local'] . '@' . $cleanList['domain'];
                    if (isset($listConflicts[$address])) {
                        $listErrors++;
                        continue;
                    }
                    if (isset($listAliases[$address])) {
                        unset($listAliases[$address]);
                        $listConflicts[$address] = true;
                        $listErrors++;
                        continue;
                    }
                    $listAliases[$address] = $cleanList;
                }
            }

            // ---- catch-all (`*@domain: dest`) ----
            foreach ($this->readLines($home . '/etc/mail/catchall') as $line) {
                $line = trim($line);
                if ($line === '' || !str_contains($line, ':')) {
                    continue;
                }
                [$key, $dest] = explode(':', $line, 2);
                $key = strtolower(trim($key));
                $dest = trim($dest);
                if (preg_match('/^\*@[a-z0-9.-]+$/', $key) !== 1 || $dest === '') {
                    continue;
                }
                if (preg_match('/^[a-z0-9._@-]+$/', $dest) !== 1) {
                    continue;   // pipe nahi
                }
                $catchalls[$key] = $key . ': ' . $dest;
                $domains[substr($key, 2)] = true;
            }

            // ---- autoresponders (vacation) ----
            foreach ($this->readJsonList($home . '/etc/mail/autorespond') as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $local = strtolower(trim((string) ($row['local'] ?? '')));
                $domain = strtolower(trim((string) ($row['domain'] ?? '')));
                $subject = trim((string) ($row['subject'] ?? ''));
                $body = trim((string) ($row['body'] ?? ''));
                $hours = (int) ($row['interval_h'] ?? 168);
                if ($local === '' || $domain === '' || $subject === '' || $body === '') {
                    continue;
                }
                $addr = $local . '@' . $domain;
                if (preg_match('/^[a-z0-9._-]+@[a-z0-9.-]+$/', $addr) !== 1) {
                    continue;
                }
                if (!isset($users[$addr])) {
                    continue;   // bina mailbox ke autoresponder nahi
                }
                $hours = $hours < 1 ? 1 : ($hours > 720 ? 720 : $hours);
                $subject = self::oneLine($subject);
                $vacation[$addr] = [
                    'subject' => $subject,
                    'body'    => $body,
                    'repeat'  => $hours . 'h',
                ];
            }

            // ---- spam blacklist/whitelist: account ke har mailbox par lagu ----
            $spamCfg = $this->readJsonMap($home . '/etc/mail/spam.json');
            if ($spamCfg !== null) {
                foreach (array_keys($ownBoxes) as $addr) {
                    $spam[$addr] = $spamCfg;
                }
            }

            // ---- email filters (cPanel #20 account-wide + #21 per-mailbox) ----
            // Panel ke JSON se ASLI Exim filter file; `exim -bf` se validate.
            $globalRows = $this->readJsonList($home . '/etc/mail/global-filters.json');
            $userRows = $this->readJsonList($home . '/etc/mail/filters');
            if ($globalRows !== [] || $userRows !== []) {
                $perBox = [];
                foreach ($userRows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $addr = strtolower(trim((string) ($row['local'] ?? '') . '@' . (string) ($row['domain'] ?? '')));
                    if (isset($boxInfo[$addr])) {
                        $perBox[$addr][] = $row;
                    }
                }
                foreach ($boxInfo as $addr => $info) {
                    $rows = array_merge($globalRows, $perBox[$addr] ?? []);
                    $maildir = (string) $info['maildir'];
                    $uid = (int) $info['uid'];
                    $gid = (int) $info['gid'];
                    $body = $this->renderEximFilter((string) $addr, $maildir, $globalRows, $perBox[$addr] ?? []);
                    if ($body === '') {
                        continue;
                    }
                    // folder action wale rules ke liye Maildir subfolder pehle se
                    // bana do — IMAP me turant dikhe, aur `exim -bf` bhi mile.
                    foreach ($rows as $row) {
                        if (!is_array($row) || (string) ($row['action'] ?? '') !== 'folder') {
                            continue;
                        }
                        $folder = strtolower(trim((string) ($row['folder'] ?? '')));
                        if ($folder !== '' && preg_match('/^[a-z0-9._-]+$/', $folder) === 1) {
                            $this->ensureMaildirFolder($maildir, $folder, $uid, $gid);
                        }
                    }
                    $path = $home . '/etc/mail/filter.d/' . $addr . '.filter';
                    $why = $this->writeFilterFile($path, $body, (string) $addr, $uid, $gid, $home . '/etc');
                    if ($why !== null) {
                        // galat filter = delivery chalti rahegi, filter nahi lagega —
                        // par wajah report me zaroor batao (andha fail nahi).
                        $filterErrors[(string) $addr] = $why;
                        continue;
                    }
                    $filters[(string) $addr] = $addr . ': ' . $path;
                }
            }
        }

        // Add a list only when its address is not already a mailbox/forwarder.
        // Nested list targets are rejected to avoid redirect loops and bounce storms.
        $listCount = 0;
        foreach ($listAliases as $address => $row) {
            if (isset($listConflicts[$address]) || isset($users[$address]) || isset($aliases[$address])) {
                $listErrors++;
                continue;
            }
            $nested = false;
            foreach ($row['members'] as $member) {
                if (isset($listAliases[$member]) || isset($listConflicts[$member])) {
                    $nested = true;
                    break;
                }
            }
            if ($nested) {
                $listErrors++;
                continue;
            }
            $aliases[$address] = $address . ': ' . implode(', ', $row['members']);
            $domains[$row['domain']] = true;
            $listCount++;
        }

        ksort($users);
        ksort($recipients);
        ksort($aliases);
        ksort($catchalls);
        ksort($vacation);
        ksort($spam);
        ksort($filters);
        ksort($filterErrors);
        $domainList = array_keys($domains);
        sort($domainList);

        $this->writeManaged($this->dovecotUsersFile(), $users === [] ? '' : implode("\n", $users) . "\n", 0640, 'dovecot');
        $this->writeManaged($this->recipientsFile(), $recipients === [] ? '' : implode("\n", $recipients) . "\n", 0644);
        $this->writeManaged($this->aliasesFile(), $aliases === [] ? '' : implode("\n", $aliases) . "\n", 0644);
        $this->writeManaged($this->catchallFile(), $catchalls === [] ? '' : implode("\n", $catchalls) . "\n", 0644);
        $this->writeManaged($this->domainsFile(), $domainList === [] ? '' : implode("\n", $domainList) . "\n", 0644);
        $this->writeVacation($vacation);
        $this->writeSpamLists($spam);
        $this->writeManaged($this->filtersFile(), $filters === [] ? '' : implode("\n", $filters) . "\n", 0644);
        $fixed = $this->repairMaildirs($recipients);

        return [
            'domains'        => count($domainList),
            'mailboxes'      => count($users),
            'aliases'        => count($aliases),
            'lists'           => $listCount,
            'list_errors'     => $listErrors,
            'catchalls'      => count($catchalls),
            'responders'     => count($vacation),
            'spam_lists'     => count($spam),
            'filters'        => count($filters),
            'filter_errors'  => $filterErrors,
            'maildirs_fixed' => $fixed,
        ];
    }

    /**
     * Exim ka daemon root ho to hi transport `user =` (mailbox ki uid) chalega.
     * systemd unit me `User=Debian-exim` ho to drop-in likh kar root kar do.
     */
    private function ensureEximRunsAsRoot(): string
    {
        $show = $this->cmd->run(['/bin/systemctl', 'show', '-p', 'User', '--value', 'exim4'], self::CMD_TIMEOUT);
        $user = trim($show->stdout);
        if ($user === '' || $user === 'root' || $user === '0') {
            return 'already-root' . ($user === '' ? ' (unit me User= hi nahi)' : '');
        }
        $dir = '/etc/systemd/system/exim4.service.d';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . '/10-alphacp-root.conf';
        $body = "# AlphaCP (mail.server): mailbox ki uid se delivery ke liye\\n"
            . "# Exim ko root chalana padta hai (cPanel/Vesta/Hestia bhi yahi karte hain).\\n"
            . "[Service]\\nUser=root\\nGroup=root\\n";
        if (@file_put_contents($file, $body) === false) {
            return "drop-in nahi likh paye ({$file}) - user={$user}";
        }
        @chmod($file, 0644);
        $this->cmd->run(['/bin/systemctl', 'daemon-reload'], self::CMD_TIMEOUT);

        return "drop-in likha ({$file}) - pehle User={$user} tha, ab root";
    }

    /**
     * Dovecot se poochho: kya wo mailbox dhoondh leta hai? (`doveadm user <addr>`)
     * Pehli koshish file ke current mode par; fail ho to mode 0644 karke dobara.
     *
     * @return array<string, mixed>
     */
    public function probeDovecotUserdb(): array
    {
        $boxes = $this->mailboxes();
        if ($boxes === []) {
            return ['checked' => false, 'reason' => 'koi mailbox nahi (abhi)'];
        }
        $addr = (string) $boxes[0];
        $run = fn (): CommandResult => $this->cmd->run(
            [self::bin('ACP_MAIL_DOVEADM', self::DOVEADM, self::DOVEADM_PATHS), 'user', $addr],
            self::CMD_TIMEOUT,
        );
        $first = $run();
        if ($first->ok()) {
            return ['checked' => true, 'address' => $addr, 'ok' => true, 'mode' => '0640'];
        }
        // auth worker file nahi padh pa raha -> readable bana kar dobara poochho
        $file = $this->dovecotUsersFile();
        @chmod($file, 0644);
        $second = $run();
        if ($second->ok()) {
            $this->log->info('dovecot users file 0644 par relax kiya (auth worker ko read chahiye tha)');

            return ['checked' => true, 'address' => $addr, 'ok' => true, 'mode' => '0644-relaxed'];
        }

        return [
            'checked' => true,
            'address' => $addr,
            'ok'      => false,
            'error'   => self::cleanError($first),
            'error2'  => self::cleanError($second),
        ];
    }

    /**
     * Maildir ki ownership theek karo (self-healing).
     *
     * Asli bug jo live server par mila: `AccountOs::setMail()` sirf cur/new/tmp
     * banata hai, unke PARENT (`~/mail`, `~/mail/<domain>`, `~/mail/<domain>/<local>`)
     * `mkdir` se root:root 0700 ban kar reh jate hain — to exim (Debian-exim) aur
     * mailbox ka apna uid dono hi uske andar traverse nahi kar sakte:
     *   defer (13): Permission denied: stat() error for /home/u/mail/d/l
     * Har sync par ye check chalta hai, to naye aur purane dono mailboxes theek
     * ho jate hain (mailbox bana kar exim/dovecot ko restart karne ki zarurat nahi).
     *
     * @param  array<string, string> $recipients  `addr: /maildir uid gid`
     * @return int  kitne directory theek kiye
     */
    private function repairMaildirs(array $recipients): int
    {
        $fixed = 0;
        foreach ($recipients as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            if (count($parts) < 4) {
                continue;
            }
            $maildir = $parts[1];
            $uid = (int) $parts[2];
            $gid = (int) $parts[3];
            if ($maildir === '' || $uid <= 0 || $gid <= 0 || str_contains($maildir, '..')) {
                continue;
            }
            // mailbox + uske parents (niche se upar) + Maildir ke leaves
            $dirs = [
                $maildir . '/cur',
                $maildir . '/new',
                $maildir . '/tmp',
                $maildir,
                dirname($maildir),                 // ~/mail/<domain>
                dirname(dirname($maildir)),        // ~/mail
            ];
            foreach ($dirs as $dir) {
                if (!is_dir($dir) || is_link($dir)) {
                    continue;
                }
                $owner = @fileowner($dir);
                $group = @filegroup($dir);
                if ($owner !== $uid || $group !== $gid) {
                    if (@chown($dir, $uid)) {
                        @chgrp($dir, $gid);
                        $fixed++;
                    }
                }
                // mailbox sirf usi user ki: 0700 (exim `user=` se deliver karta hai)
                $mode = @fileperms($dir) & 0777;
                if ($mode !== 0700 && @chmod($dir, 0700)) {
                    $fixed++;
                }
            }
        }

        return $fixed;
    }

    /** @param array<string, array{subject: string, body: string, repeat: string}> $vacation */
    private function writeVacation(array $vacation): void
    {
        $dir = $this->vacationDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $keep = [];
        foreach ($vacation as $addr => $row) {
            $eml = 'Subject: ' . $row['subject'] . "\n"
                . 'Auto-Submitted: auto-replied' . "\n"
                . 'Precedence: bulk' . "\n"
                . "\n" . $row['body'] . "\n";
            $this->writeManaged($dir . '/' . $addr . '.eml', $eml, 0644);
            $this->writeManaged($dir . '/' . $addr . '.repeat', $row['repeat'], 0644);
            $keep[$addr . '.eml'] = true;
            $keep[$addr . '.repeat'] = true;
        }
        // purane autoresponder ke files hatana zaroori hai — warna deleted
        // responder ke jawab jaate rahenge
        foreach ((array) glob($dir . '/*') as $file) {
            $name = basename((string) $file);
            if (!isset($keep[$name]) && is_file($file)) {
                @unlink($file);
            }
        }
    }

    /** @param array<string, array{deny: list<string>, allow: list<string>}> $spam */
    private function writeSpamLists(array $spam): void
    {
        $dir = $this->spamDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $keep = [];
        foreach ($spam as $addr => $cfg) {
            foreach (['deny', 'allow'] as $kind) {
                $lines = [];
                foreach ($cfg[$kind] as $entry) {
                    $entry = strtolower(trim((string) $entry));
                    if (preg_match('/^[a-z0-9._%@*-]+$/', $entry) === 1) {
                        $lines[] = $entry;
                    }
                }
                $name = $addr . '.' . $kind;
                $keep[$name] = true;
                if ($lines === []) {
                    if (is_file($dir . '/' . $name)) {
                        @unlink($dir . '/' . $name);
                    }
                    continue;
                }
                $this->writeManaged($dir . '/' . $name, implode("\n", $lines) . "\n", 0644);
            }
        }
        foreach ((array) glob($dir . '/*') as $file) {
            $name = basename((string) $file);
            if (!isset($keep[$name]) && is_file($file)) {
                @unlink($file);
            }
        }
    }

    /** @return list<mixed> */
    private function readJsonList(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /** @return array{deny: list<string>, allow: list<string>}|null */
    private function readJsonMap(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $deny = is_array($decoded['blacklist'] ?? null) ? array_values($decoded['blacklist']) : [];
        $allow = is_array($decoded['whitelist'] ?? null) ? array_values($decoded['whitelist']) : [];
        if ($deny === [] && $allow === []) {
            return null;
        }

        return ['deny' => $deny, 'allow' => $allow];
    }

    private static function oneLine(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text) ?? '';

        return trim(substr($text, 0, 200));
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

    // ------------------------------------------------------- email filters ----

    /**
     * Email Filters — cPanel #21 (per-mailbox) + #20 (account-wide).
     *
     * Panel JSON se **ASLI Exim filter file** banti hai (Exim filter language),
     * jise `exim -bf` se validate karne ke baad hi install kiya jata hai.
     * Delivery se pehle router `alphacp_userfilter` ise chalata hai, to:
     *   save    -> mail sidhi Maildir folder me (IMAP me dikhta hai)
     *   deliver -> doosre address par forward
     *   seen finish -> discard (kabhi inbox me nahi aati)
     *
     * Fail-closed: `exim -bf` galat bole to filter install hota hi nahi
     * (mail delivery chalti rahegi, sirf filter nahi lagega).
     *
     * @param  list<array<string, mixed>> $globalRows  account-wide rules (pehle lagu)
     * @param  list<array<string, mixed>> $userRows    is mailbox ke apne rules
     * @return string  khali = koi chalne layak rule nahi (file hi nahi banani)
     */
    public function renderEximFilter(string $addr, string $maildir, array $globalRows, array $userRows): string
    {
        $out = [
            // Exim spec: filter file ki PEHLI line '# Exim filter' honi hi chahiye.
            // Nahi to exim ise aam .forward file samajhta hai (aur -bf reject kar deta hai).
            '# Exim filter  <<== YE LINE HATAANA NAHI (Exim filter file ki pehchaan)',
            '# AlphaCP managed — haath se edit mat karo',
            '# mailbox: ' . $addr,
            '# banaya gaya: panel ke Email Filters (#20 account-wide + #21 per-mailbox) se',
            '# har mail sync par dobara likha jata hai.',
            '',
            'if error_message then finish endif',
            '',
        ];
        $rules = 0;
        foreach ([$globalRows, $userRows] as $group) {
            foreach ($group as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $lines = $this->filterRule($addr, $maildir, $row);
                if ($lines === []) {
                    continue;
                }
                foreach ($lines as $line) {
                    $out[] = $line;
                }
                $out[] = '';
                $rules++;
            }
        }
        if ($rules === 0) {
            return '';
        }

        return implode("\n", $out) . "\n";
    }

    /**
     * Ek filter rule se Exim filter language ka `if … then … endif` block.
     *
     * @param  array<string, mixed> $row
     * @return list<string>  khali = rule chalne layak nahi (chhod do)
     */
    private function filterRule(string $addr, string $maildir, array $row): array
    {
        $field = strtolower(trim((string) ($row['field'] ?? '')));
        $needle = trim((string) ($row['needle'] ?? ''));
        $action = strtolower(trim((string) ($row['action'] ?? '')));
        if (!in_array($field, ['from', 'subject', 'to'], true)) {
            return [];
        }
        // needle me quote/khaas character nahi — Exim filter string me surakshit
        if ($needle === '' || preg_match('/^[a-zA-Z0-9 .,_@+-]+$/', $needle) !== 1) {
            return [];
        }
        $cond = 'if $header_' . $field . ': contains "' . $needle . '" then';

        if ($action === 'discard') {
            return [$cond, '  seen finish', 'endif'];
        }
        if ($action === 'folder') {
            $folder = strtolower(trim((string) ($row['folder'] ?? '')));
            if ($folder === '' || preg_match('/^[a-z0-9._-]+$/', $folder) !== 1) {
                return [];
            }
            // Maildir++ layout: .Folder/ — Dovecot IMAP me "Folder" dikhta hai
            return [$cond, '  save "' . rtrim($maildir, '/') . '/.' . $folder . '/"', '  finish', 'endif'];
        }
        if ($action === 'forward') {
            $dest = strtolower(trim((string) ($row['dest'] ?? '')));
            if ($dest === '' || $dest === $addr || preg_match('/^[a-z0-9._-]+@[a-z0-9.-]+$/', $dest) !== 1) {
                return [];   // khud ko forward = loop
            }

            return [$cond, '  deliver "' . $dest . '"', '  finish', 'endif'];
        }

        return [];
    }

    /**
     * Exim's redirect router drops to the mailbox uid/gid before opening this
     * file. Permit that identity to search the account's ~/etc directory, but
     * do not grant it directory listing when it is neither owner nor group.
     *
     * @return null|string null when the path is searchable; otherwise a safe error
     */
    private function ensureFilterEtcSearchable(string $etcDir, int $uid, int $gid): ?string
    {
        if (is_link($etcDir)) {
            return 'filter parent ~/etc is a symlink';
        }
        $stat = @lstat($etcDir);
        if (!is_array($stat) || (($stat['mode'] & 0170000) !== 0040000)) {
            return 'filter parent ~/etc is missing or not a directory';
        }

        $mode = (int) ($stat['mode'] & 0777);
        $owner = (int) ($stat['uid'] ?? -1);
        $group = (int) ($stat['gid'] ?? -1);
        $rewriteGroup = false;
        if ($owner === $uid) {
            $searchBit = 0100;
        } elseif ($group === $gid) {
            $searchBit = 0010;
        } else {
            // Per-account primary group is preferred over making ~/etc
            // world-searchable. Fall back to search-only for other users if
            // chgrp is unavailable; this adds no read/list permission.
            if (@chgrp($etcDir, $gid)) {
                $searchBit = 0010;
                $rewriteGroup = true;
            } else {
                $searchBit = 0001;
            }
        }

        if (!$rewriteGroup && ($mode & $searchBit) !== 0) {
            return null;
        }
        $targetMode = $rewriteGroup
            ? (($mode & ~0070) | 0010)
            : ($mode | $searchBit);
        if (!@chmod($etcDir, $targetMode)) {
            return 'filter parent ~/etc search permission could not be set';
        }

        $after = @lstat($etcDir);
        if (!is_array($after) || (($after['mode'] & 0170000) !== 0040000)) {
            return 'filter parent ~/etc changed while setting permissions';
        }
        $afterMode = (int) ($after['mode'] & 0777);
        $afterOwner = (int) ($after['uid'] ?? -1);
        $afterGroup = (int) ($after['gid'] ?? -1);
        $effectiveBit = $afterOwner === $uid ? 0100 : ($afterGroup === $gid ? 0010 : 0001);
        $searchable = ($afterMode & $effectiveBit) !== 0;

        return $searchable ? null : 'filter parent ~/etc remains inaccessible to the mailbox uid/gid';
    }

    /**
     * Filter file likho — par sirf tab jab `exim -bf` use VALID bole.
     * Galat filter se poora mail server nahi rukna chahiye: reject ho to
     * purani file wapas / naye filter ke bina delivery chalti rahe.
     */
    private function writeFilterFile(string $path, string $body, string $addr, int $uid, int $gid, string $etcDir): ?string
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $tmp = $dir . '/.acp-filter-' . bin2hex(random_bytes(4)) . '.tmp';
        $this->tempFiles[] = $tmp;
        if (@file_put_contents($tmp, $body) === false) {
            return 'filter file nahi likhi ja saki';
        }
        // 0644 + root-owned: Exim ka router (Debian-exim) bhi filter file padh sake.
        // Filter me koi secret nahi hota (sanitized rules), uid/gid delivery ke waqt
        // transport set karta hai (address_directory).
        @chmod($tmp, 0644);
        // Exim khud bole: `exim -bf <filter>` (galat syntax = non-zero exit)
        $probe = $this->cmd->run([$this->eximBin(), '-bf', $tmp, '-f', $addr], self::CMD_TIMEOUT, self::FILTER_TEST_MESSAGE);
        if (!$probe->ok()) {
            @unlink($tmp);
            $why = self::oneLine($probe->stderr . ' ' . $probe->stdout);
            $this->log->warning('email filter reject (exim -bf): ' . $addr . ' -> ' . $why);

            return $why === '' ? 'exim -bf ne filter reject kar diya' : $why;
        }
        $permissionError = $this->ensureFilterEtcSearchable($etcDir, $uid, $gid);
        if ($permissionError !== null) {
            @unlink($tmp);
            $this->log->warning('email filter path permission: ' . $addr . ' -> ' . $permissionError);

            return $permissionError;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            return 'filter file install nahi ho saki';
        }
        @chmod($path, 0644);

        return null;
    }

    /** Maildir subfolder pehle se bana do — IMAP me turant dikhe. */
    private function ensureMaildirFolder(string $maildir, string $folder, int $uid, int $gid): void
    {
        $target = rtrim($maildir, '/') . '/.' . $folder;
        foreach (['', '/cur', '/new', '/tmp'] as $leaf) {
            $dir = $target . $leaf;
            if (is_dir($dir)) {
                continue;
            }
            @mkdir($dir, 0700, true);
        }
        if ($uid > 0) {
            @chown($target, $uid);
            if ($gid > 0) {
                @chgrp($target, $gid);
            }
            foreach (['/cur', '/new', '/tmp'] as $leaf) {
                @chown($target . $leaf, $uid);
                if ($gid > 0) {
                    @chgrp($target . $leaf, $gid);
                }
            }
        }
    }

    /**
     * Track Delivery (cPanel #19) — asli exim mainlog me is address ka safar.
     * Pehle ye sirf JSON me dhoondhta tha; ab log khud jawab deta hai.
     *
     * @return array<string, mixed>
     */
    public function track(string $address, int $limit = 100): array
    {
        $addr = strtolower(trim($address));
        if (preg_match('/^[a-z0-9._%+-]+@[a-z0-9.-]+$/', $addr) !== 1) {
            throw new TaskRejectedException("track: '{$address}' sahi email address nahi hai");
        }
        $limit = max(1, min(500, $limit));
        $report = $this->reports($limit * 10, $addr);
        if (($report['ok'] ?? false) !== true) {
            return [
                'ok'      => false,
                'address' => $addr,
                'source'  => 'exim mainlog',
                'error'   => (string) ($report['error'] ?? 'exim mainlog nahi mila'),
                'hits'    => [],
                'summary' => [],
            ];
        }
        $hits = [];
        $summary = [];
        foreach ((array) ($report['entries'] ?? []) as $entry) {
            $kind = (string) ($entry['kind'] ?? '');
            $hits[] = [
                'id'      => (string) ($entry['id'] ?? ''),
                'time'    => (string) ($entry['time'] ?? ''),
                'kind'    => $kind,
                'address' => $entry['address'] ?? null,
                'text'    => (string) ($entry['text'] ?? ''),
            ];
            $summary[$kind] = ($summary[$kind] ?? 0) + 1;
        }

        return [
            'ok'         => true,
            'address'    => $addr,
            'source'     => 'exim mainlog',
            'file'       => $report['file'] ?? null,
            'hits'       => array_slice($hits, -$limit),
            'hits_total' => count($hits),
            'summary'    => $summary,
        ];
    }

    private function eximBin(): string
    {
        return self::bin('ACP_MAIL_EXIM', self::EXIM, self::EXIM_PATHS);
    }

    // ---------------------------------------- queue / reports / configuration ----

    /**
     * Mail Queue Manager (cPanel #141) — `exim -bp` (mailq) ka asli jawab, aur
     * zaroorat par queue par action: deliver / remove / freeze / thaw / flush.
     *
     * @return array<string, mixed>
     */
    public function queue(string $op = 'list', string $id = ''): array
    {
        if (!$this->installed()) {
            throw new TaskRejectedException('exim4 nahi mila — mail queue dekhne ke liye exim chahiye');
        }
        $op = strtolower(trim($op));
        if ($op === '') {
            $op = 'list';
        }
        $exim = self::bin('ACP_MAIL_EXIM', self::EXIM, self::EXIM_PATHS);
        $mutations = ['deliver' => ['-M'], 'remove' => ['-Mrm'], 'freeze' => ['-Mf'], 'thaw' => ['-Mt']];

        if (isset($mutations[$op])) {
            $id = trim($id);
            if (!self::validQueueId($id)) {
                throw new TaskRejectedException(
                    "queue {$op} ke liye asli message id chahiye (jaise 1oABCD-0000xy-1a) — '{$id}' nahi chalega"
                );
            }
            $res = $this->cmd->run(array_merge([$exim], $mutations[$op], [$id]), self::CMD_TIMEOUT);

            return [
                'op'     => $op,
                'id'     => $id,
                'ok'     => $res->ok(),
                'output' => self::oneLine(trim((string) $res->stdout)),
                'error'  => $res->ok() ? null : self::cleanError($res),
                'queue'  => $this->listQueue(),
            ];
        }

        if ($op === 'flush') {
            $res = $this->cmd->run([$exim, '-qf'], self::CMD_TIMEOUT);

            return [
                'op'    => 'flush',
                'ok'    => $res->ok(),
                'error' => $res->ok() ? null : self::cleanError($res),
                'queue' => $this->listQueue(),
            ];
        }

        if ($op !== 'list' && $op !== 'count') {
            throw new TaskRejectedException("queue op '{$op}' nahi chalega (list/count/deliver/remove/freeze/thaw/flush)");
        }

        $queue = $this->listQueue();
        if ($op === 'count') {
            return ['op' => 'count', 'count' => $queue['count'], 'ok' => $queue['ok'], 'error' => $queue['error']];
        }

        return ['op' => 'list'] + $queue;
    }

    /**
     * `exim -bp` ko padh kar queue ka asli haal (kitne mail pending, kiske liye).
     *
     * @return array<string, mixed>
     */
    public function listQueue(): array
    {
        $exim = self::bin('ACP_MAIL_EXIM', self::EXIM, self::EXIM_PATHS);
        $res = $this->cmd->run([$exim, '-bp'], self::CMD_TIMEOUT);
        $items = [];
        $current = null;

        foreach (preg_split('/\R/', trim((string) $res->stdout)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $m = [];
            $isHead = (bool) preg_match(
                '/^\s*(?:(?P<age>\d+[smhdw])\s+)?(?P<size>\d+(?:\.\d+)?[KMGkmg]?B?)\s+'
                . '(?P<id>[0-9A-Za-z]{6}-[0-9A-Za-z]{6}-[0-9A-Za-z]{2})(?P<flags>[A-Za-z-]*)\s*(?P<rest>.*)$/',
                $line,
                $m,
            );
            if ($isHead) {
                if ($current !== null) {
                    $items[] = $current;
                }
                $rest = (string) ($m['rest'] ?? '');
                $sender = '';
                $sm = [];
                if (preg_match('/<([^>]*)>/', $rest, $sm)) {
                    $sender = trim((string) $sm[1]);
                } elseif (trim($rest) !== '') {
                    $sender = trim((string) (explode(' ', trim($rest))[0] ?? ''));
                }
                $current = [
                    'id'         => (string) ($m['id'] ?? ''),
                    'age'        => ($m['age'] ?? '') !== '' ? (string) $m['age'] : null,
                    'size'       => (string) ($m['size'] ?? ''),
                    'sender'     => strtolower($sender),
                    'recipients' => [],
                    'frozen'     => str_contains($line, 'frozen'),
                ];
                continue;
            }
            if ($current === null) {
                continue;
            }
            $rm = [];
            if (preg_match('/^\s+<?([^\s<>]+)>?/', $line, $rm)) {
                $to = strtolower(trim(trim((string) $rm[1]), '<>,;'));
                if ($to !== '' && !in_array($to, $current['recipients'], true)) {
                    $current['recipients'][] = $to;
                }
            }
        }
        if ($current !== null) {
            $items[] = $current;
        }

        return [
            'ok'    => $res->ok(),
            'error' => $res->ok() ? null : self::cleanError($res),
            'count' => count($items),
            'items' => $items,
            'note'  => $items === [] ? 'queue khali hai (koi mail pending nahi)' : null,
        ];
    }

    /**
     * Mail Delivery Reports (cPanel #142) — asli exim mainlog se ginati:
     * kitni mail aayi, pahunchi, deferred/failed hui. Hamara daawa nahi, log ka jawab.
     *
     * @return array<string, mixed>
     */
    public function reports(int $limit = 50, string $search = ''): array
    {
        $file = $this->mainlogFile();
        if ($file === null) {
            return [
                'ok'             => false,
                'error'          => 'exim mainlog nahi mila (dhoondha: ' . implode(', ', self::MAINLOG_CANDIDATES) . ')',
                'counts'         => [],
                'entries'        => [],
                'top_senders'    => [],
                'top_recipients' => [],
            ];
        }

        $limit = max(1, min(500, $limit));
        $search = strtolower(trim($search));
        $lines = preg_split('/\R/', $this->tailText($file)) ?: [];
        $counts = [
            'arrived' => 0, 'delivered' => 0, 'redirected' => 0, 'deferred' => 0,
            'failed'  => 0, 'completed' => 0, 'rejected'   => 0, 'frozen'   => 0,
        ];
        $senders = [];
        $recipients = [];
        $entries = [];

        foreach ($lines as $line) {
            $m = [];
            if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.\d+)?\s+(\S+)\s+(.*)$/', $line, $m)) {
                continue;
            }
            $rest = (string) ($m[3] ?? '');
            $kind = null;
            $addr = null;
            $mm = [];
            if (preg_match('/^<=\s+(\S+)/', $rest, $mm)) {
                $kind = 'arrived';
                $addr = self::cleanAddr((string) $mm[1]);
                $senders[$addr] = ($senders[$addr] ?? 0) + 1;
            } elseif (preg_match('/^=>\s+(\S+)/', $rest, $mm)) {
                $kind = 'delivered';
                $addr = self::cleanAddr((string) $mm[1]);
                $recipients[$addr] = ($recipients[$addr] ?? 0) + 1;
            } elseif (preg_match('/^->\s+(\S+)/', $rest, $mm)) {
                $kind = 'redirected';
                $addr = self::cleanAddr((string) $mm[1]);
            } elseif (preg_match('/^==\s+(\S+)/', $rest, $mm)) {
                $kind = 'deferred';
                $addr = self::cleanAddr((string) $mm[1]);
            } elseif (preg_match('/^\*\*\s+(\S+)/', $rest, $mm)) {
                $kind = 'failed';
                $addr = self::cleanAddr((string) $mm[1]);
            } elseif (str_starts_with($rest, 'Completed')) {
                $kind = 'completed';
            } elseif (str_contains($rest, 'frozen')) {
                $kind = 'frozen';
            } elseif (preg_match('/\brejected\b/i', $rest)) {
                $kind = 'rejected';
            }
            if ($kind === null) {
                continue;
            }
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
            if ($search !== '' && !str_contains(strtolower($line), $search)) {
                continue;
            }
            $entries[] = [
                'time'    => (string) ($m[1] ?? ''),
                'id'      => (string) ($m[2] ?? ''),
                'kind'    => $kind,
                'address' => $addr,
                'text'    => self::oneLine($rest),
            ];
        }

        arsort($senders);
        arsort($recipients);

        return [
            'ok'             => true,
            'file'           => $file,
            'lines_scanned'  => count($lines),
            'counts'         => $counts,
            'top_senders'    => self::topCounts($senders, 10),
            'top_recipients' => self::topCounts($recipients, 10),
            'entries'        => array_slice($entries, -$limit),
            'entries_total'  => count($entries),
        ];
    }

    /**
     * Email Disk Usage — server view (cPanel #146): har account aur har mailbox
     * ki mail jagah, asli bytes me (symlinks follow nahi hote).
     *
     * @return array<string, mixed>
     */
    public function diskUsage(?string $username = null): array
    {
        $root = AccountPaths::fromEnv()->accountsRoot;
        $accounts = [];
        $total = 0;
        $name = $username === null ? null : strtolower(trim($username));

        foreach (glob($root . '/*') ?: [] as $dir) {
            if (!is_dir($dir) || is_link($dir)) {
                continue;
            }
            $user = basename($dir);
            if ($name !== null && $user !== $name) {
                continue;
            }
            $mail = $dir . '/mail';
            if (!is_dir($mail)) {
                continue;
            }
            $mailboxes = [];
            foreach (glob($mail . '/*/*') ?: [] as $box) {
                if (!is_dir($box) || is_link($box)) {
                    continue;
                }
                $mailboxes[] = [
                    'address' => basename($box) . '@' . basename((string) dirname($box)),
                    'bytes'   => $this->dirBytes($box),
                ];
            }
            usort($mailboxes, static fn (array $a, array $b): int => ($b['bytes'] <=> $a['bytes']));
            $accountBytes = $this->dirBytes($mail);
            $total += $accountBytes;
            $accounts[] = [
                'username'      => $user,
                'bytes'         => $accountBytes,
                'mailboxes'     => $mailboxes,
                'mailbox_count' => count($mailboxes),
            ];
        }
        usort($accounts, static fn (array $a, array $b): int => ($b['bytes'] <=> $a['bytes']));

        return [
            'ok'            => true,
            'accounts_root' => $root,
            'accounts'      => $accounts,
            'account_count' => count($accounts),
            'total_bytes'   => $total,
            'total_human'   => self::humanBytes($total),
        ];
    }

    /**
     * Exim Configuration Manager (cPanel #143).
     * $set = null → sirf dikhao; array → validate → likho → template dobara banao
     * (update-exim4.conf + `exim4 -bV`; fail ho to purani config wapas).
     *
     * @param  array<string, mixed>|null $set
     * @return array<string, mixed>
     */
    public function eximConf(?array $set = null): array
    {
        $file = $this->eximOptionsFile();
        // SpamAssassin and greylistd services have to be managed alongside
        // their ACL toggles, so expose them only through spamAssassin(), not
        // the generic cPanel Exim Configuration Manager endpoint.
        $managed = ['spam_enabled', 'greylisting'];
        $allowed = array_values(array_diff(array_keys(self::EXIM_OPTION_SPEC), $managed));
        $out = [
            'file'     => $file,
            'options'  => $this->eximOptions(),
            'defaults' => self::specDefaults(self::EXIM_OPTION_SPEC),
            'allowed'  => $allowed,
            'applied'  => false,
        ];
        if ($set === null) {
            return $out;
        }
        if ($set === []) {
            throw new TaskRejectedException('eximconf: koi option nahi diya (allowed: ' . implode(', ', $allowed) . ')');
        }
        $restricted = array_values(array_intersect(array_keys($set), $managed));
        if ($restricted !== []) {
            throw new TaskRejectedException(
                implode(', ', $restricted) . ' mail.server spamassassin action se manage hote hain (service safety ke liye)'
            );
        }

        return $this->applyEximOptionSet($set);
    }

    /** @param array<string, mixed> $set @return array<string, mixed> */
    private function applyEximOptionSet(array $set): array
    {
        $file = $this->eximOptionsFile();
        $allowed = array_keys(self::EXIM_OPTION_SPEC);
        $out = [
            'file'     => $file,
            'options'  => $this->eximOptions(),
            'defaults' => self::specDefaults(self::EXIM_OPTION_SPEC),
            'allowed'  => $allowed,
            'applied'  => false,
        ];
        if ($set === []) {
            throw new TaskRejectedException('eximconf: koi option nahi diya (allowed: ' . implode(', ', $allowed) . ')');
        }
        if (!$this->installed() || !self::isConfigured()) {
            throw new TaskRejectedException(
                'Exim config tabhi badalte hain jab mail server configure ho — pehle mail.server setup chalao'
            );
        }
        [$merged, $errors] = self::validateOptions($set, self::EXIM_OPTION_SPEC, $out['options']);
        if ($errors !== []) {
            throw new TaskRejectedException('exim option reject: ' . implode(' | ', $errors));
        }
        $this->writeJson($file, $merged);

        return array_merge($out, $this->applyEximTemplate(), ['options' => $merged, 'applied' => true]);
    }

    /**
     * Mailserver Configuration / Dovecot (cPanel #144).
     * $set = null → sirf dikhao; array → validate → likho → conf dobara banao
     * (`doveconf -n`; fail ho to purani conf wapas).
     *
     * @param  array<string, mixed>|null $set
     * @return array<string, mixed>
     */
    public function dovecotConf(?array $set = null): array
    {
        $file = $this->dovecotOptionsFile();
        $allowed = array_keys(self::DOVECOT_OPTION_SPEC);
        $out = [
            'file'     => $file,
            'options'  => $this->dovecotOptions(),
            'defaults' => self::specDefaults(self::DOVECOT_OPTION_SPEC),
            'allowed'  => $allowed,
            'applied'  => false,
        ];
        if ($set === null) {
            return $out;
        }
        if ($set === []) {
            throw new TaskRejectedException('dovecotconf: koi option nahi diya (allowed: ' . implode(', ', $allowed) . ')');
        }
        if (!$this->installed()) {
            throw new TaskRejectedException('dovecot nahi mila — pehle mail.server setup chalao');
        }
        [$merged, $errors] = self::validateOptions($set, self::DOVECOT_OPTION_SPEC, $out['options']);
        if ($errors !== []) {
            throw new TaskRejectedException('dovecot option reject: ' . implode(' | ', $errors));
        }
        $this->writeJson($file, $merged);

        return array_merge($out, $this->applyDovecotConfig(), ['options' => $merged, 'applied' => true]);
    }

    /** @return array<string, string> */
    public function eximOptions(): array
    {
        return $this->loadOptions($this->eximOptionsFile(), self::EXIM_OPTION_SPEC);
    }

    /** @return array<string, string> */
    public function dovecotOptions(): array
    {
        return $this->loadOptions($this->dovecotOptionsFile(), self::DOVECOT_OPTION_SPEC);
    }

    /**
     * `exim4 -bV` sirf SYNTAX pakadta hai — runtime expansion error (jaise
     * "Failed to find user" / "PANIC") tabhi dikhta hai jab exim sach-much kisi
     * address ko route kare. 0.78.0 me yahi hua tha: config `-bV` pass kar gaya
     * par poora mail delivery defer ho gaya. Ab setup ke baad ek asli `-bt`
     * smoke test chalta hai — fail ho to purani template wapas.
     *
     * @return string|null  null = theek; string = wajah (reject kar do)
     */
    private function eximSmokeTest(): ?string
    {
        if (!$this->installed()) {
            return null;
        }
        $boxes = $this->mailboxes();
        if ($boxes === []) {
            return null;   // koi mailbox nahi to smoke test ka matlab nahi
        }
        $addr = (string) $boxes[0];
        $res = $this->cmd->run([$this->eximBin(), '-bt', $addr], self::CMD_TIMEOUT);
        $text = trim($res->stdout . ' ' . $res->stderr);
        if ($text === '') {
            return null;   // chup binary (fake/test) — jhoothi reject nahi
        }
        if (preg_match('/PANIC|Failed to find user|cannot be resolved|configuration error|Failed to (open|find)/i', $text) === 1) {
            return 'exim -bt ' . $addr . ' -> ' . self::oneLine($text);
        }

        return null;
    }

    /**
     * Managed Exim template dobara likho: backup (pehli baar) → likho → validate
     * (`update-exim4.conf` + `exim4 -bV`) → fail ho to purani wapas → restart.
     *
     * @return array<string, mixed>
     */
    private function applyEximTemplate(): array
    {
        $this->snapshotEximTemplate();
        $this->writeManaged($this->eximTemplate(), $this->renderEximTemplate(), 0644);
        $generate = $this->cmd->run(
            [self::bin('ACP_MAIL_UPDATE_EXIM', self::UPDATE_EXIM, self::UPDATE_EXIM_PATHS)],
            self::CMD_TIMEOUT,
        );
        $check = $this->cmd->run([self::bin('ACP_MAIL_EXIM', self::EXIM, self::EXIM_PATHS), '-bV'], self::CMD_TIMEOUT);
        if (!$generate->ok() || !$check->ok()) {
            $this->restoreEximTemplate();
            throw new TaskRejectedException(
                'exim config reject (purani config wapas): ' . self::cleanError($generate) . ' / ' . self::cleanError($check)
            );
        }
        $smoke = $this->eximSmokeTest();
        if ($smoke !== null) {
            $this->restoreEximTemplate();
            throw new TaskRejectedException('exim routing smoke test fail (purani config wapas): ' . $smoke);
        }
        $this->cmd->run(['/bin/systemctl', 'restart', 'exim4'], self::CMD_TIMEOUT);

        return ['generated' => true, 'exim_config' => self::firstLine((string) $check->stdout)];
    }

    /** @return array<string, mixed> */
    private function applyDovecotConfig(): array
    {
        $file = $this->dovecotConfFile();
        $previous = is_file($file) ? (string) @file_get_contents($file) : null;
        $this->writeManaged($file, $this->renderDovecotConf(), 0644);
        $check = $this->cmd->run(
            [self::bin('ACP_MAIL_DOVECONF', self::DOVECONF, self::DOVECONF_PATHS), '-n'],
            self::CMD_TIMEOUT,
        );
        if (!$check->ok()) {
            if ($previous !== null) {
                @file_put_contents($file, $previous);
            } else {
                @unlink($file);
            }
            throw new TaskRejectedException('dovecot config reject (purani conf wapas): ' . self::cleanError($check));
        }
        $this->cmd->run(['/bin/systemctl', 'restart', 'dovecot'], self::CMD_TIMEOUT);

        return ['dovecot_config' => 'ok', 'dovecot_userdb' => $this->probeDovecotUserdb()];
    }

    /**
     * @param  array<string, array{0: string, 1: string}> $spec
     * @return array<string, string>
     */
    private function loadOptions(string $file, array $spec): array
    {
        $out = self::specDefaults($spec);
        if (!is_file($file)) {
            return $out;
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || trim($raw) === '') {
            return $out;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $out;
        }
        foreach (array_keys($spec) as $key) {
            if (!array_key_exists($key, $decoded)) {
                continue;
            }
            [$value, $error] = self::validateOption((string) $key, $decoded[$key], $spec);
            if ($error === null && $value !== null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>                       $in
     * @param  array<string, array{0: string, 1: string}> $spec
     * @param  array<string, string>                      $base
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private static function validateOptions(array $in, array $spec, array $base): array
    {
        $merged = $base + self::specDefaults($spec);
        $errors = [];
        foreach ($in as $key => $value) {
            $key = (string) $key;
            [$clean, $error] = self::validateOption($key, $value, $spec);
            if ($error !== null) {
                $errors[] = $error;
                continue;
            }
            $merged[$key] = (string) $clean;
        }

        return [$merged, $errors];
    }

    /**
     * Har option ki value uske type ke regex se validate hoti hai (fail-closed):
     * galat value exim/dovecot ko chalu hone se rok deti hai, isliye seedha reject.
     *
     * @param  array<string, array{0: string, 1: string}> $spec
     * @return array{0: ?string, 1: ?string}
     */
    private static function validateOption(string $key, mixed $value, array $spec): array
    {
        if (!isset($spec[$key])) {
            return [null, "'{$key}' koi mail option nahi hai (allowed: " . implode(', ', array_keys($spec)) . ')'];
        }
        $type = (string) $spec[$key][0];
        if (is_bool($value)) {
            $value = $value ? 'yes' : 'no';
        }
        $value = is_scalar($value) ? trim((string) $value) : '';
        if ($value === '') {
            return [null, "'{$key}' khali nahi ho sakta"];
        }
        if ($type === 'bool') {
            $lower = strtolower($value);
            if (in_array($lower, ['1', 'true', 'yes', 'on'], true)) {
                $value = 'yes';
            } elseif (in_array($lower, ['0', 'false', 'no', 'off'], true)) {
                $value = 'no';
            }
        }
        $patterns = [
            'size'     => '/^\d{1,8}[KMGkmg]?$/',
            'int'      => '/^\d{1,9}$/',
            'number'   => '/^\d{1,5}(\.\d{1,2})?$/',
            'duration' => '/^\d{1,5}[smhdw]$/',
            'bool'     => '/^(yes|no)$/',
            'text'     => '/^[ -\x7e]{1,200}$/',
        ];
        if (!isset($patterns[$type]) || !preg_match($patterns[$type], $value)) {
            return [null, "'{$key}' = '{$value}' galat hai ({$type} chahiye)"];
        }
        // '#' config file me comment shuru kar deta hai — option value me mana hai.
        if (str_contains($value, '#')) {
            return [null, "'{$key}' me '#' nahi ho sakta (config comment ban jayega)"];
        }

        return [$value, null];
    }

    /**
     * @param  array<string, array{0: string, 1: string}> $spec
     * @return array<string, string>
     */
    private static function specDefaults(array $spec): array
    {
        $out = [];
        foreach ($spec as $key => $def) {
            $out[(string) $key] = (string) ($def[1] ?? '');
        }

        return $out;
    }

    /** @param array<string, string> $data */
    private function writeJson(string $file, array $data): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        ksort($data);
        $body = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new TaskRejectedException("mail options file nahi ban saki: {$file}");
        }
        $tmp = $dir . '/.acp-mailopts-' . bin2hex(random_bytes(4)) . '.tmp';
        $this->tempFiles[] = $tmp;
        if (@file_put_contents($tmp, $body . "\n") === false) {
            throw new TaskRejectedException("mail options temp file nahi likhi ja saki: {$tmp}");
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new TaskRejectedException("mail options file nahi likhi ja saki: {$file}");
        }
        @chmod($file, 0644);
    }

    /** Log ka aakhiri hissa (bada log poori tarah memory me nahi aata). */
    private function tailText(string $file, int $bytes = 524288): string
    {
        $size = @filesize($file);
        if (!is_int($size) || $size <= 0) {
            return '';
        }
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return '';
        }
        if ($size > $bytes) {
            fseek($fh, $size - $bytes);
            fgets($fh); // adhoori pehli line chhod do
        }
        $text = (string) stream_get_contents($fh);
        fclose($fh);

        return $text;
    }

    /** Symlink follow NAHI hota (safety) — mail tree me symlink ho to skip. */
    private function dirBytes(string $dir, int $depth = 0): int
    {
        if ($depth > 8 || !is_dir($dir) || is_link($dir)) {
            return 0;
        }
        $bytes = 0;
        $fh = @opendir($dir);
        if ($fh === false) {
            return 0;
        }
        while (($entry = readdir($fh)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                $bytes += $this->dirBytes($path, $depth + 1);
                continue;
            }
            $size = @filesize($path);
            if (is_int($size) && $size > 0) {
                $bytes += $size;
            }
        }
        closedir($fh);

        return $bytes;
    }

    private static function validQueueId(string $id): bool
    {
        return (bool) preg_match('/^[0-9A-Za-z]{6}-[0-9A-Za-z]{6}-[0-9A-Za-z]{2}$/', $id);
    }

    private static function cleanAddr(string $addr): string
    {
        return strtolower(trim(trim($addr), "<>,;"));
    }

    /** @param array<string, int> $map @return list<array{address: string, count: int}> */
    private static function topCounts(array $map, int $limit): array
    {
        $out = [];
        foreach (array_slice($map, 0, $limit, true) as $addr => $count) {
            $address = (string) $addr;
            if ($address === '') {
                continue;
            }
            $out[] = ['address' => $address, 'count' => (int) $count];
        }

        return $out;
    }

    private static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $i = 0;
        while ($value >= 1024.0 && $i < count($units) - 1) {
            $value /= 1024.0;
            $i++;
        }

        return $i === 0 ? $bytes . ' B' : sprintf('%.1f %s', $value, $units[$i]);
    }

    // ------------------------------------------------------------ rendering ----

    private function renderDovecotConf(): string
    {
        $users = $this->dovecotUsersFile();
        $opts = $this->dovecotOptions();
        $lines = [
            self::MANAGED_BEGIN,
            '# AlphaCP S7 — virtual mailboxes jo panel banata hai',
            '# har account ka ~/etc/mail/passwd yahan aggregate hota hai',
            'passdb {',
            '  driver = passwd-file',
            '  args = scheme=BLF-CRYPT ' . $users,
            '}',
            '# NOTE: "scheme=" sirf passdb ke liye hai (Dovecot 2.3 docs).',
            '# userdb args me scheme= likhne se Dovecot poore string ko FILENAME',
            '# samajh leta hai -> open("scheme=... /etc/...") No such file or directory.',
            'userdb {',
            '  driver = passwd-file',
            '  args = ' . $users,
            '}',
            '# mailbox khud Maildir hai (home field usi ko point karta hai)',
            'mail_location = maildir:~/',
            'mail_home = %h',
            'protocols = ' . $opts['protocols'],
            'listen = 127.0.0.1, ::1',
            'disable_plaintext_auth = ' . $opts['disable_plaintext_auth'],
            'auth_mechanisms = plain login',
            'mail_plugins = $mail_plugins quota',
            '# Mailserver Configuration (cPanel #144) — panel se set kiye gaye options',
            'mail_max_userip_connections = ' . $opts['mail_max_userip_connections'],
            'maildir_copy_with_hardlinks = ' . $opts['maildir_copy_with_hardlinks'],
            'pop3_uidl_format = ' . $opts['pop3_uidl_format'],
            'imap_idle_notify_interval = ' . $opts['imap_idle_notify_interval'] . ' mins',
            'mailbox_idle_check_interval = ' . $opts['mailbox_idle_check_interval'] . ' secs',
            'login_greeting = ' . $opts['login_greeting'],
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
        $catchall = $this->catchallFile();
        $vacation = $this->vacationDir();
        $spamDir = $this->spamDir();
        $dkimDir = $this->dkimDir();
        $caps = $this->capabilities();
        $dnsbl = self::DNSBL;
        $dkimKey = $dkimDir . '/\$sender_address_domain.key';
        $dkim = $caps['dkim']
            ? "  dkim_domain = \$sender_address_domain\n"
              . '  dkim_selector = ' . self::DKIM_SELECTOR . "\n"
              . "  dkim_canon = relaxed\n"
              . "  dkim_private_key = \${if exists{{$dkimKey}}{{$dkimKey}}{0}}\n"
              . "  dkim_sign_headers = Date:From:To:Subject:Message-ID:MIME-Version:Content-Type\n"
            : '';
        $opts = $this->eximOptions();
        $spamLimit = (string) $opts['spam_score_limit'];
        $spamLimitHuman = self::scoreHuman((int) $opts['spam_score_limit']);
        $filtersFile = $this->filtersFile();
        // SpamAssassin (cPanel #147): content-scan support + installed spamd +
        // explicit opt-in. `:true` populates score/header variables on every message;
        // `/defer_ok` makes a later spamd outage fail open (never hold mail in queue).
        // 0 score limit is deliberately tag-only; it must not reject score > 0.
        $spamOn = $opts['spam_enabled'] === 'yes' && $caps['spamd'] && $caps['content_scanning'];
        $spamRejectAcl = (int) $spamLimit > 0
            ? "  deny condition = \${if >{\${if def:spam_score_int {\$spam_score_int}{0}}}{{$spamLimit}}{yes}{no}}\n"
              . "       message = Message scored \$spam_score spam points (limit {$spamLimitHuman}) - rejected as spam\n\n"
            : '';
        $spamAcl = $spamOn
            ? "  warn spam = nobody:true/defer_ok\n"
              . "       add_header = X-Spam-Score: \$spam_score (\$spam_bar)\n"
              . "       add_header = X-Spam-Checker-Version: SpamAssassin \$spam_score_int/{$spamLimit} AlphaCP\n\n"
              . $spamRejectAcl
              . "          "
            : '';
        // Debian greylistd protocol: --grey <IP> <MAIL FROM> <RCPT TO> returns
        // true only while a triplet is greylisted. A missing/unavailable socket
        // returns false, so greylisting degrades open rather than blocking all mail.
        $greylistSocket = $this->greylistdSocketFile();
        $greylistAcl = $opts['greylisting'] === 'yes'
            ? "  defer\n"
              . "       message = temporarily rejected (greylisted): \$sender_host_address is not yet authorized to deliver mail from <\$sender_address> to <\$local_part@\$domain>. Please try again later.\n"
              . "       log_message = greylisted (\$sender_host_address)\n"
              . "       domains = +local_domains\n"
              . "       !senders = :\n"
              . "       !hosts = : +relay_from_hosts\n"
              . "       !authenticated = *\n"
              . "       verify = recipient\n"
              . "       condition = \${readsocket{{$greylistSocket}}{--grey \$sender_host_address \$sender_address \$local_part@\$domain}{5s}{}{false}}\n\n"
              . "          "
            : '';
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

        # mailbox ki uid se deliver karne ke liye (transport `user =`) Exim ko apna
        # root privilege rakhna padta hai — warna har delivery Debian-exim ke roop
        # me hoti hai aur Maildir (0700) me likh hi nahi sakti.
        deliver_drop_privilege = false
        never_users = root
        host_lookup = *
        rfc1413_hosts =
        rfc1413_query_timeout = 0s
        ignore_bounce_errors_after = {$opts['ignore_bounce_errors_after']}
        timeout_frozen_after = {$opts['timeout_frozen_after']}
        smtp_banner = {$opts['smtp_banner']}
        message_size_limit = {$opts['message_size_limit']}

        # Exim Configuration Manager (cPanel #143) — panel se set kiye gaye options
        smtp_accept_max = {$opts['smtp_accept_max']}
        smtp_accept_max_per_host = {$opts['smtp_accept_max_per_host']}
        queue_run_max = {$opts['queue_run_max']}
        remote_max_parallel = {$opts['remote_max_parallel']}
        deliver_queue_load_max = {$opts['deliver_queue_load_max']}
        queue_only_load = {$opts['queue_only_load']}

        begin acl

        acl_check_rcpt:
          accept hosts = : +relay_from_hosts

          # account ki blacklist (mail.spam task) — sender blocked
          deny senders = \${if exists{{$spamDir}/\$local_part@\$domain.deny}{wildlsearch;{$spamDir}/\$local_part@\$domain.deny}{}}
               message = sender is blocked for this mailbox

          # account ki whitelist — aage ke checks chhodo
          accept senders = \${if exists{{$spamDir}/\$local_part@\$domain.allow}{wildlsearch;{$spamDir}/\$local_part@\$domain.allow}{}}

          deny domains = +local_domains
               local_parts = ^[./|] : ^.*[@%!/|`#&?] : ^.*/\\.\\./
               message = restricted characters in address

          {$greylistAcl}accept domains = +local_domains
                 endpass
                 verify = recipient

          deny message = relay not permitted

        acl_check_data:
          # DNS blacklist: reject nahi, header + log (DNS slow ho to mail nahi girti)
          warn dnslists = {$dnsbl}
               add_header = X-AlphaCP-DNSBL: \$dnslist_domain (\$dnslist_value)
               log_message = DNSBL hit \$dnslist_domain for \$sender_address -> \$local_part@\$domain

          {$spamAcl}accept

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

        # 2) email filters (cPanel #20 account-wide + #21 per-mailbox)
        # Panel ke JSON se ASLI Exim filter file banta hai (`exim -bf` se validate);
        # delivery se pehle yahi chalta hai — save (folder) / deliver (forward) /
        # seen finish (discard). Kharaab filter kabhi install hi nahi hota.
        alphacp_userfilter:
          driver = redirect
          domains = +local_domains
          allow_filter
          allow_defer
          allow_fail
          # (1) lookup file maujood NA ho to router seedha skip (defer nahi!) —
          # (1) lookup file maujood NA ho to router seedha skip (defer nahi!).
          #     (pehle `condition` me lookup thi — file missing par exim har mail
          #     mail par PANIC log likhta tha (real exim 4.97 se verify kiya).
          # (1) DONO zaroori hain (asli exim 4.97 se verify):
          #     require_files  -> lookup file maujood NA ho to seedha skip
          #                       (warna lookup har mail par PANIC/defer karta hai)
          #     condition      -> file hai par is address ka koi filter nahi to skip
          #                       (warna file = "" ho jata hai -> defer "" is not an
          #                        absolute path -> MAIL QUEUE ME ATK JATI HAI)
          require_files = {$filtersFile}
          condition = \${if !eq{\${lookup{\$local_part@\$domain}lsearch{{$filtersFile}}}}{}{yes}{no}}
          file = \${lookup{\$local_part@\$domain}lsearch{{$filtersFile}}}
          # (2) `allow_filter` ke saath `user` HONA ZAROORI hai — Exim config-time
          #     check: '"user" or "check_local_user" must be set with allow_filter'.
          #     0.79.0 me ye hata diya to `exim -bV` hi reject ho gaya. uid yahan
          #     MAILBOX KE HISAB SE — wahi extract idiom jo alphacp_maildir me chal
          #     raha hai (0.78.0 ki nesting galat thi: "Failed to find
          #     user"). condition false hone par ye line expand hoti hi nahi.
          user = \${extract{2}{ }{\${lookup{\$local_part@\$domain}lsearch{{$recipients}}}}}
          group = \${extract{3}{ }{\${lookup{\$local_part@\$domain}lsearch{{$recipients}}}}}
          directory_transport = address_directory
          file_transport = address_file
          pipe_transport = address_pipe
          no_verify
          no_expn
          check_ancestor

        # 3) autoresponder (vacation) — unseen: mail delivery aage bhi hoti hai
        alphacp_autoreply:
          driver = accept
          domains = +local_domains
          condition = \${if exists{{$vacation}/\$local_part@\$domain.eml}{yes}{no}}
          senders = ! ^.*-request@.* : ! ^bounce-.*@.* : ! ^.*-bounce@.* : \
                    ! ^owner-.*@.* : ! ^postmaster@.* : ! ^webmaster@.* : \
                    ! ^listmaster@.* : ! ^mailer-daemon@.* : ! ^root@.* : \
                    ! ^nobody@.*
          transport = alphacp_vacation
          unseen
          no_expn
          no_verify

        # 4) asli mailbox -> Maildir (uid/gid mailbox ke hisaab se)
        alphacp_mailbox:
          driver = accept
          domains = +local_domains
          condition = \${if !eq{\${lookup{\$local_part@\$domain}lsearch{{$recipients}}}}{}{yes}{no}}
          transport = alphacp_maildir
          no_more

        # 5) catch-all (*@domain) — mailbox na mile to yahi aakhri rasta
        alphacp_catchall:
          driver = redirect
          domains = +local_domains
          allow_fail
          allow_defer
          qualify_preserve_domain
          data = \${lookup{*@\$domain}lsearch{{$catchall}}}

        # 6) server ke apne system users (root, ubuntu, ...) — /etc/aliases bhi
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
          transport = mail_spool
          cannot_route_message = Unknown user

        # 7) bahar ki duniya
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

        alphacp_vacation:
          driver = autoreply
          file = {$vacation}/\$local_part@\$domain.eml
          file_expand
          from = \$local_part@\$domain
          to = \$sender_address
          subject = \${if def:h_subject: {Re: \$h_subject:}{Automatic reply}}
          once = /var/spool/exim4/db/alphacp-vacation-\$local_part@\$domain
          once_repeat = \${if exists{{$vacation}/\$local_part@\$domain.repeat}{\${readfile{{$vacation}/\$local_part@\$domain.repeat}{}}}{7d}}
          log = /var/log/exim4/vacation.log
          return_message

        # system users (root/cron ki mail) ke liye Debian wala /var/mail/<user> —
        # maildir_home se ~/Maildir na hone par queue me atak jati thi
        mail_spool:
          driver = appendfile
          file = /var/mail/\$local_part
          delivery_date_add
          envelope_to_add
          return_path_add
          group = mail
          mode = 0660
          mode_fail_narrower = false

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

        # Filter ke `save` command ke liye (redirect router ka directory_transport).
        # maildir_format + create_directory: .Folder/ khud ban jata hai, aur Dovecot
        # use IMAP me "Folder" ke naam se dikhata hai (Maildir++ layout).
        # YAHAN `directory` / `user` / `group` KABHI NA LIKHEIN — filter ka `save`
        # apna path khud laata hai (address hi path hota hai). Transport par ye set
        # karne se wo filter ke folder ko override kar deta hai (0.79.0 me yahi hua:
        # mail .filtered/ ki jagah inbox me chali gayi). uid/gid router se inherit
        # hote hain (router par hi recipient ka local part aur domain milta hai;
        # yahan address ek PATH hota hai, email address nahi).
        address_directory:
          driver = appendfile
          maildir_format
          create_directory
          delivery_date_add
          envelope_to_add
          return_path_add
          mode = 0700
          mode_fail_narrower = false

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
          {$dkim}
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
        // PEHLE pichli kaam karne wali AlphaCP template, warna distro wali asli.
        // (0.79.0 me seedha .acp-orig wapas aayi to exim me alphacp routers hi nahi
        //  rahe — har address "nonlocal" ho gaya aur poora mail band.)
        foreach ([$this->eximTemplatePrev(), $this->eximTemplateBackup()] as $from) {
            if (!is_file($from)) {
                continue;
            }
            @copy($from, $this->eximTemplate());
            $this->cmd->run(
                [self::bin('ACP_MAIL_UPDATE_EXIM', self::UPDATE_EXIM, self::UPDATE_EXIM_PATHS)],
                self::CMD_TIMEOUT,
            );

            return;
        }
    }

    /**
     * Distro wali asli template ki copy pehli baar (.acp-orig), aur har baar
     * maujuda AlphaCP template ki copy (.acp-prev) — fail-safe rollback ke liye.
     */
    private function snapshotEximTemplate(): void
    {
        $tpl = $this->eximTemplate();
        if (!is_file($tpl)) {
            return;
        }
        if (!is_file($this->eximTemplateBackup())) {
            @copy($tpl, $this->eximTemplateBackup());
        }
        $current = @file_get_contents($tpl);
        if (is_string($current) && str_contains($current, self::TEMPLATE_MARKER)) {
            @copy($tpl, $this->eximTemplatePrev());
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

    private static function firstNonEmpty(string $env, string $default): string
    {
        $override = trim((string) (getenv($env) ?: ''));
        if ($override !== '' && $override[0] === '/' && !str_contains($override, "\0")) {
            return rtrim($override, '/');
        }

        return $default;
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
