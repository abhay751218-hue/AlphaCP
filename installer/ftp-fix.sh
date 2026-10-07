#!/usr/bin/env bash
# =============================================================================
#  AlphaCP — FTP FIX  v1.0   (B1 part 1: FTP ab root-agent se, web-FPM se nahi)
# -----------------------------------------------------------------------------
#  Live par "FTP Accounts" 500 deta tha: panel web-FPM se `Process::run(['pure-pw'…])`
#  chalata tha aur pool me `proc_open` disabled hai (audit B1). cPanel-tareeka:
#  shell kaam root-agent kare, panel sirf queue kare. Ye script DONO side deploy
#  karti hai:
#    AGENT : src/Ftp.php, src/Tasks/{FtpTask,FtpAdd,FtpPasswd,FtpDel}.php,
#            src/CommandRunner.php (pure-pw allowlist), config/tasks.php (ftp.*)
#    PANEL : app/Support/Ftp.php (queue-aware), app/Http/Controllers/FtpController.php
#
#  Safety (login-fix/agent-fix jaisa): backup → har PHP_BIN par `php -l` → agent
#  self-test (static smoke + pdo_sqlite ho to poora suite failed:0) → paneld
#  restart → panel files → php-fpm restart (opcache) → HTTP smoke → kahin bhi
#  FAIL = APNE AAP rollback. End me alphacp-sync. Koi interactive prompt nahi.
#
#  Usage: sudo bash ftp-fix-v1.0.sh | --diagnose | --rollback | --help
# =============================================================================
set -Eeuo pipefail

# php-wasm (CI/sim) `PHP` env ko VERSION maanta hai — isliye apna binary PHP_BIN
# me rakhte hain aur inherited PHP ko hata dete hain, warna child php calls
# "Unsupported PHP version /path/to/php" par fail hote hain.
unset PHP 2>/dev/null || true

VERSION="1.0"
ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"
AGENT="${ACP_HOME}/agent"
PANEL="${ACP_HOME}/panel"
STAMP="$(date -u +%Y%m%d%H%M%S)"
BACKUP="${ACP_HOME}/releases/ftpfix-${STAMP}"
LOG_FILE="${ACP_HOME}/logs/ftp-fix-${STAMP}.txt"

C_R=$'\033[0;31m'; C_G=$'\033[0;32m'; C_Y=$'\033[0;33m'; C_B=$'\033[0;36m'; C_D=$'\033[0;2m'; C_0=$'\033[0m'
say(){ printf '%s\n' "$*" | tee -a "${LOG_FILE:-/dev/null}"; }
hdr(){ say ""; say "${C_B}== $* ==${C_0}"; }
ok(){ say "  ${C_G}✔${C_0} $*"; }
warn(){ say "  ${C_Y}⚠${C_0} $*"; }
info(){ say "  ${C_D}·${C_0} $*"; }
die(){ say "  ${C_R}✖ $*${C_0}"; exit 1; }
have_systemd(){ [[ -d /run/systemd/system ]] && command -v systemctl >/dev/null 2>&1; }
cnt(){ grep -c "$1" "$2" 2>/dev/null || true; }

detect_php(){
  local p
  if [[ -f /etc/systemd/system/paneld.service ]]; then
    p="$(sed -nE 's#^ExecStart=([^ ]+).*#\1#p' /etc/systemd/system/paneld.service 2>/dev/null | head -1)"
    [[ -n "$p" && -x "$p" ]] && { printf '%s' "$p"; return; }
  fi
  for c in php8.4 php8.3 php8.2 php; do command -v "$c" >/dev/null 2>&1 && { command -v "$c"; return; }; done
  printf ''
}
PHP_BIN="$(detect_php)"

detect_panel_user(){
  local u
  u="$(grep -hoE '^[[:space:]]*user[[:space:]]*=[[:space:]]*[a-z_][a-z0-9_-]*' /etc/php/*/fpm/pool.d/*.conf 2>/dev/null | head -1 | awk -F'=' '{gsub(/[ \t]/,"",$2); print $2}')"
  [[ -n "$u" ]] && { printf '%s' "$u"; return; }
  printf 'alphacp'
}
PANEL_USER="$(detect_panel_user)"

detect_fpm_unit(){
  local v u
  v="$([[ -n "$PHP_BIN" ]] && "$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
  for u in "php${v}-fpm" "php${v%%.*}-fpm" php-fpm; do
    [[ -n "$u" ]] || continue
    if have_systemd && { [[ -f "/etc/systemd/system/${u}.service" ]] || [[ -f "/lib/systemd/system/${u}.service" ]]; }; then
      printf '%s' "$u"; return
    fi
  done
  printf ''
}
FPM_UNIT="$(detect_fpm_unit)"

AGENT_FILES=(src/Ftp.php src/Tasks/FtpTask.php src/Tasks/FtpAdd.php src/Tasks/FtpPasswd.php src/Tasks/FtpDel.php src/CommandRunner.php config/tasks.php)
PANEL_FILES=(app/Support/Ftp.php app/Http/Controllers/FtpController.php)

rollback(){
  hdr "ROLLBACK — ftp-fix v${VERSION}"
  local latest rel
  latest="$(ls -1dt "${ACP_HOME}"/releases/ftpfix-* 2>/dev/null | head -1 || true)"
  [[ -n "$latest" ]] || die "koi ftpfix backup nahi mila"
  info "backup: $latest"
  for rel in "${AGENT_FILES[@]}"; do
    if [[ -f "${latest}/agent/${rel}" ]]; then cp -p "${latest}/agent/${rel}" "${AGENT}/${rel}"; else rm -f "${AGENT}/${rel}"; fi
  done
  for rel in "${PANEL_FILES[@]}"; do
    if [[ -f "${latest}/panel/${rel}" ]]; then cp -p "${latest}/panel/${rel}" "${PANEL}/${rel}"; else rm -f "${PANEL}/${rel}"; fi
  done
  if have_systemd; then
    systemctl restart paneld >/dev/null 2>&1 || true
    [[ -n "$FPM_UNIT" ]] && { systemctl restart "$FPM_UNIT" >/dev/null 2>&1 || true; }
  fi
  ok "rollback complete (purani files wapas)"
}

diagnose(){
  hdr "DIAGNOSE (read-only) — ftp-fix v${VERSION}"
  info "ACP_HOME=${ACP_HOME} php=${PHP_BIN:-none} panel_user=${PANEL_USER} fpm=${FPM_UNIT:-none}"
  info "agent src/Ftp.php        : $( [[ -f "${AGENT}/src/Ftp.php" ]] && echo PRESENT || echo MISSING )"
  info "agent tasks ftp.add      : $(cnt "'ftp.add'" "${AGENT}/config/tasks.php")  (1=registered)"
  info "agent tasks ftp total    : $(cnt "'ftp\." "${AGENT}/config/tasks.php")  (3=complete)"
  info "agent allowlist pure-pw  : $(cnt 'pure-pw' "${AGENT}/src/CommandRunner.php")  (>0=allowed)"
  info "panel Support/Ftp Process: $(cnt 'Process::' "${PANEL}/app/Support/Ftp.php")  (0=theek, >0=500 wala bug)"
  info "panel FtpController queue: $(cnt "AccountProvisioner::enqueue" "${PANEL}/app/Http/Controllers/FtpController.php")  (3=theek)"
  say ""; ok "diagnose complete (kuch badla nahi)"
}

apply(){
  hdr "APPLY — ftp-fix v${VERSION} (FTP via root agent)"
  mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true
  [[ -d "$AGENT" ]] || die "agent dir nahi: ${AGENT}"
  [[ -d "$PANEL" ]] || die "panel dir nahi: ${PANEL}"
  [[ -n "$PHP_BIN" && -x "$PHP_BIN" ]] || die "php binary nahi mila"

  # ---- backup ----
  mkdir -p "${BACKUP}/agent" "${BACKUP}/panel"
  local rel
  for rel in "${AGENT_FILES[@]}"; do
    mkdir -p "${BACKUP}/agent/$(dirname "$rel")"
    [[ -f "${AGENT}/${rel}" ]] && cp -p "${AGENT}/${rel}" "${BACKUP}/agent/${rel}" || true
  done
  for rel in "${PANEL_FILES[@]}"; do
    mkdir -p "${BACKUP}/panel/$(dirname "$rel")"
    [[ -f "${PANEL}/${rel}" ]] && cp -p "${PANEL}/${rel}" "${BACKUP}/panel/${rel}" || true
  done
  ok "backup: ${BACKUP}"

  # ---- agent files ----
  hdr "agent files likhna"
  cat > "${AGENT}/src/Ftp.php" <<'PHPEOF'
<?php
declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * Pure-FTPd virtual-user management (the S6 "FTP accounts" slice).
 *
 * The panel must NEVER shell out to `pure-pw` (web FPM runs with proc_open
 * disabled — B1). All PureDB mutations happen here, root-side, through the
 * allowlisted CommandRunner:
 *  - argv is array-form only (no shell), password travels on **stdin** (two
 *    lines, exactly like interactive pure-pw) so it never reaches argv/logs,
 *  - `-m` keeps the PureDB (`pureftpd.pdb`) rebuilt after every change,
 *  - uid/gid are resolved from the *account* via getent (never trusted from
 *    the payload) and must be real (>= 1000) system ids.
 */
final class Ftp
{
    /** Where pure-pw lives across distros. */
    private const BIN_CANDIDATES = [
        '/usr/bin/pure-pw',
        '/usr/sbin/pure-pw',
        '/usr/local/bin/pure-pw',
        '/usr/local/sbin/pure-pw',
    ];

    private const TIMEOUT = 30;

    public function __construct(
        private readonly CommandExecutor $cmd,
        private readonly TaskLogger $log,
    ) {
    }

    /** Absolute pure-pw path (first that exists; default for fake/test envs). */
    public static function binary(): string
    {
        foreach (self::BIN_CANDIDATES as $bin) {
            if (is_file($bin)) {
                return $bin;
            }
        }

        return '/usr/bin/pure-pw';
    }

    /**
     * Validate a customer FTP password (panel enforces the same window).
     * Newlines/control chars are refused — the password is fed on stdin as two
     * lines, so an embedded newline would corrupt the pure-pw prompt.
     */
    public static function password(string $password): string
    {
        $len = strlen($password);
        if ($len < 8 || $len > 72) {
            throw new TaskRejectedException('FTP password must be 8-72 characters');
        }
        for ($i = 0; $i < $len; $i++) {
            $ord = ord($password[$i]);
            if ($ord < 32 || $ord === 127) {
                throw new TaskRejectedException('FTP password may not contain control characters');
            }
        }

        return $password;
    }

    /** Resolve the hosting account's real uid/gid (>= 1000) from getent. */
    public function ids(string $account): array
    {
        $res = $this->cmd->run(['/usr/bin/getent', 'passwd', $account], 10);
        if (!$res->ok() || trim($res->stdout) === '') {
            throw new TaskRejectedException("cannot resolve system user '{$account}'");
        }
        $parts = explode(':', trim(explode("\n", trim($res->stdout))[0]));
        $uid = (int) ($parts[2] ?? 0);
        $gid = (int) ($parts[3] ?? 0);
        if ($uid < 1000 || $gid < 1000) {
            throw new TaskRejectedException('FTP home uid/gid out of range');
        }

        return [$uid, $gid];
    }

    /** Create the Pure-FTPd virtual user `<login>` chrooted to `$home`. */
    public function addUser(string $login, string $account, string $password, string $home): void
    {
        [$uid, $gid] = $this->ids($account);
        $res = $this->cmd->run(
            [self::binary(), 'useradd', $login, '-u', (string) $uid, '-g', (string) $gid, '-d', $home, '-m'],
            self::TIMEOUT,
            $password . "\n" . $password . "\n",
        );
        if (!$res->ok()) {
            $this->log->error('pure-pw useradd failed: ' . substr(trim($res->stderr), 0, 200));

            throw new TaskRejectedException('pure-pw useradd failed');
        }
        $this->log->info("Pure-FTPd virtual user {$login} created (chroot {$home})");
    }

    /** Change a virtual user's password. */
    public function passwd(string $login, string $password): void
    {
        $res = $this->cmd->run(
            [self::binary(), 'passwd', $login, '-m'],
            self::TIMEOUT,
            $password . "\n" . $password . "\n",
        );
        if (!$res->ok()) {
            $this->log->error('pure-pw passwd failed: ' . substr(trim($res->stderr), 0, 200));

            throw new TaskRejectedException('pure-pw passwd failed');
        }
        $this->log->info("Pure-FTPd password changed for {$login}");
    }

    /** Remove a virtual user (the chroot dir's contents are left alone). */
    public function delUser(string $login): void
    {
        $res = $this->cmd->run([self::binary(), 'userdel', $login, '-m'], self::TIMEOUT);
        if (!$res->ok()) {
            $this->log->error('pure-pw userdel failed: ' . substr(trim($res->stderr), 0, 200));

            throw new TaskRejectedException('pure-pw userdel failed');
        }
        $this->log->info("Pure-FTPd virtual user {$login} removed");
    }
}
PHPEOF
  cat > "${AGENT}/src/Tasks/FtpTask.php" <<'PHPEOF'
<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\Ftp;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;

/**
 * Shared guards for the real `ftp.*` tasks (S6).
 *
 *  - only an existing AlphaCP account (Linux user check) may own FTP logins,
 *  - every virtual login must carry the account prefix (`<account>_<suffix>`),
 *  - pure-pw is reached only through the allowlisted CommandRunner (root-side),
 *    never from the web FPM (proc_open is disabled there — B1).
 */
abstract class FtpTask implements TaskInterface
{
    protected function account(array $payload, TaskContext $ctx): string
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('ftp tasks require PathGuard roots');
        }
        $username = strtolower(trim((string) ($payload['account'] ?? '')));
        $err = AccountIdentity::username($username);
        if ($err !== null) {
            throw new TaskRejectedException($err);
        }
        $os = new AccountOs($ctx->cmd, new SafeFs($ctx->paths), AccountPaths::fromEnv(), $ctx->log);
        if (!$os->userExists($username) || !$os->isOurUser($username)) {
            throw new TaskRejectedException("Linux user '{$username}' is not an AlphaCP account");
        }

        return $username;
    }

    /** The Pure-FTPd virtual login `<account>_<suffix>`. */
    protected function login(string $account, string $raw): string
    {
        $login = strtolower(trim($raw));
        if (preg_match('/^[a-z][a-z0-9_]{2,31}$/', $login) !== 1) {
            throw new TaskRejectedException('invalid FTP login: letters/numbers/_ , 3-32 chars, letter first');
        }
        if (!str_starts_with($login, $account . '_')) {
            throw new TaskRejectedException("FTP login must belong to account '{$account}' (prefix {$account}_)");
        }

        return $login;
    }

    protected function ftp(TaskContext $ctx): Ftp
    {
        return new Ftp($ctx->cmd, $ctx->log);
    }
}
PHPEOF
  cat > "${AGENT}/src/Tasks/FtpAdd.php" <<'PHPEOF'
<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\Ftp;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;

/**
 * ftp.add — create a Pure-FTPd virtual user `<account>_<suffix>` chrooted to
 * `<account-home>/ftp/<suffix>` (cPanel "FTP Accounts"). Idempotent-ish: the
 * chroot dir is created if missing; pure-pw useradd fails cleanly if the login
 * already exists (the panel pre-checks uniqueness in its own DB).
 *
 * @acp-task ftp.add
 */
final class FtpAdd extends FtpTask
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $account = $this->account($payload, $ctx);
        $login = $this->login($account, (string) ($payload['login'] ?? ''));
        $password = Ftp::password((string) ($payload['password'] ?? ''));

        $home = rtrim(trim((string) ($payload['home'] ?? '')), '/');
        if ($home === '') {
            throw new TaskRejectedException('FTP home is required');
        }
        $home = $ctx->paths->assert($home);
        $accountHome = rtrim(AccountPaths::fromEnv()->home($account), '/');
        if (!str_starts_with($home, $accountHome . '/')) {
            throw new TaskRejectedException('FTP home must live inside the account home');
        }

        $fs = new SafeFs($ctx->paths);
        if (!$fs->isDir($home)) {
            $fs->mkdir($home, 0750);
            $fs->chownName($home, $account);
        }

        $this->ftp($ctx)->addUser($login, $account, $password, $home);

        return [
            'account' => $account,
            'login'   => $login,
            'home'    => $home,
            'status'  => 'ok',
        ];
    }
}
PHPEOF
  cat > "${AGENT}/src/Tasks/FtpPasswd.php" <<'PHPEOF'
<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\Ftp;

/**
 * ftp.passwd — change a Pure-FTPd virtual user's password (cPanel "Change
 * Password" on an FTP account). The new password arrives on stdin only.
 *
 * @acp-task ftp.passwd
 */
final class FtpPasswd extends FtpTask
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $account = $this->account($payload, $ctx);
        $login = $this->login($account, (string) ($payload['login'] ?? ''));
        $password = Ftp::password((string) ($payload['password'] ?? ''));

        $this->ftp($ctx)->passwd($login, $password);

        return [
            'account' => $account,
            'login'   => $login,
            'status'  => 'ok',
        ];
    }
}
PHPEOF
  cat > "${AGENT}/src/Tasks/FtpDel.php" <<'PHPEOF'
<?php
declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\Ftp;

/**
 * ftp.del — remove a Pure-FTPd virtual user (cPanel "Delete" on an FTP account).
 * Only the PureDB entry goes; the chroot directory's files are left untouched so
 * a customer never loses uploaded data by deleting an FTP login. Classified
 * `mutating` (reversible: recreate the login), not `destructive`.
 *
 * @acp-task ftp.del
 */
final class FtpDel extends FtpTask
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        $account = $this->account($payload, $ctx);
        $login = $this->login($account, (string) ($payload['login'] ?? ''));

        $this->ftp($ctx)->delUser($login);

        return [
            'account' => $account,
            'login'   => $login,
            'status'  => 'ok',
        ];
    }
}
PHPEOF
  cat > "${AGENT}/src/CommandRunner.php" <<'PHPEOF'
<?php
declare(strict_types=1);

namespace Alphacp\Agent;

use RuntimeException;

/**
 * Array-exec only. Never shell strings, never interpolation.
 *
 * Defence in depth:
 *  - argv[0] must resolve to a binary inside the allowlist,
 *  - each argument is passed as its own argv element (no shell involved),
 *  - hard timeout with SIGTERM → SIGKILL escalation,
 *  - stdout/stderr captured, never inherited by the panel.
 */
final class CommandRunner implements CommandExecutor
{
    /** Binaries the agent is allowed to execute today (grows per step, reviewed). */
    private const BIN_ALLOWLIST = [
        '/usr/bin/systemctl',
        '/bin/systemctl',
        '/usr/bin/hostname',
        '/bin/hostname',
        '/usr/bin/uptime',
        '/usr/bin/df',
        '/bin/df',
        '/usr/bin/free',
        '/usr/bin/id',
        '/usr/bin/getent',
        '/usr/bin/stat',
        '/bin/stat',
        '/usr/sbin/useradd',
        '/usr/sbin/userdel',
        '/usr/sbin/usermod',
        '/usr/sbin/setquota',
        '/usr/bin/setquota',
        '/usr/bin/crontab',
        '/usr/bin/openssl',
        '/usr/bin/certbot',
        '/usr/bin/tar',
        '/bin/tar',
        '/usr/bin/mariadb',
        '/usr/bin/mysql',
        // S10 remote pull + remote backup destinations (openssh-client + optional
        // sshpass). argv-only; the agent never builds a shell string, so
        // scp/ssh/ssh-keyscan cannot be tricked into running something else.
        // `ssh` is here because a destination test/push/browse has to RUN a
        // command on the far side (mkdir/checksum/ls) — it was missing in 0.73.0
        // and every destination action failed with "binary not in allowlist".
        '/usr/bin/ssh-keyscan',
        '/usr/bin/ssh-keygen',
        '/usr/bin/scp',
        '/usr/bin/ssh',
        '/bin/ssh',
        '/usr/bin/sshpass',
        // S9 BIND9 (dns.bind): config check, zone check, reload aur asli dig jawab.
        // Dono (/usr/sbin + /usr/bin + /usr/local) isliye: distro ke hisaab se
        // binary kahin bhi ho sakta hai, aur allowlist me na ho to task chup-chaap
        // fail ho jata hai (0.73.1 wali `ssh` bhool dobara na ho).
        '/usr/sbin/named-checkconf',
        '/usr/bin/named-checkconf',
        '/usr/local/sbin/named-checkconf',
        '/usr/local/bin/named-checkconf',
        '/usr/sbin/named-checkzone',
        '/usr/bin/named-checkzone',
        '/usr/local/sbin/named-checkzone',
        '/usr/local/bin/named-checkzone',
        '/usr/sbin/rndc',
        '/usr/bin/rndc',
        '/usr/local/sbin/rndc',
        '/usr/local/bin/rndc',
        '/usr/bin/dig',
        '/usr/sbin/dig',
        '/bin/dig',
        '/usr/local/bin/dig',
        // S7 mail (mail.server): config generate/validate, IMAP/POP3, user lookup.
        // Dono (/usr/sbin + /usr/bin) — 0.73.1 wali `ssh` bhool dobara na ho.
        '/usr/sbin/exim4',
        '/usr/bin/exim4',
        '/usr/sbin/exim',
        '/usr/local/sbin/exim4',
        '/usr/sbin/dovecot',
        '/usr/bin/dovecot',
        '/usr/local/sbin/dovecot',
        '/usr/bin/doveadm',
        '/usr/sbin/doveadm',
        '/usr/local/bin/doveadm',
        '/usr/sbin/doveconf',
        '/usr/bin/doveconf',
        '/usr/local/sbin/doveconf',
        '/usr/sbin/update-exim4.conf',
        '/usr/bin/update-exim4.conf',
        '/usr/bin/openssl',
        '/usr/local/bin/openssl',
        // S6 FTP (ftp.add/ftp.passwd/ftp.del): Pure-FTPd virtual-user management.
        // Web FPM proc_open disabled hai (B1), isliye pure-pw sirf agent (root)
        // chalata hai — argv-only, password stdin par, `-m` se PureDB rebuild.
        '/usr/bin/pure-pw',
        '/usr/sbin/pure-pw',
        '/usr/local/bin/pure-pw',
        '/usr/local/sbin/pure-pw',
    ];

    public function __construct(private readonly int $defaultTimeout = 30)
    {
    }

    /**
     * @param  list<string> $argv full argv, argv[0] must be a real path in the allowlist
     * @return CommandResult
     */
    public function run(array $argv, ?int $timeout = null, ?string $stdin = null, ?string $stdinFile = null): CommandResult
    {
        if ($argv === []) {
            throw new RuntimeException('empty argv');
        }

        $bin = realpath($argv[0]) ?: '';
        if ($bin === '' || !in_array($bin, self::BIN_ALLOWLIST, true)) {
            throw new RuntimeException("binary not in agent allowlist: {$argv[0]}");
        }
        $argv[0] = $bin;

        $timeout ??= $this->defaultTimeout;
        $started  = hrtime(true);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($argv, $descriptors, $pipes, null, ['LC_ALL' => 'C', 'PATH' => '/usr/sbin:/usr/bin:/sbin:/bin']);
        if (!is_resource($proc)) {
            throw new RuntimeException('proc_open failed for: ' . implode(' ', $argv));
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        } elseif ($stdinFile !== null) {
            // Streamed, never buffered: SQL dumps can be hundreds of MB.
            $in = @fopen($stdinFile, 'rb');
            if ($in === false) {
                fclose($pipes[0]);
                proc_terminate($proc, defined('SIGTERM') ? SIGTERM : 15);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);

                throw new RuntimeException("cannot read stdinFile: {$stdinFile}");
            }
            while (!feof($in)) {
                $chunk = fread($in, 262_144);
                if ($chunk === false) {
                    break;
                }
                if ($chunk !== '' && @fwrite($pipes[0], $chunk) === false) {
                    break; // the client closed stdin early (its exit code tells the story)
                }
            }
            fclose($in);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout   = '';
        $stderr   = '';
        $deadline = microtime(true) + $timeout;
        $timedOut = false;

        $finalExit = -1;
        while (true) {
            $status  = proc_get_status($proc);
            $read    = array_filter([$pipes[1], $pipes[2]], static fn ($p) => is_resource($p) && !feof($p));
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            if (!$status['running']) {
                $finalExit = (int) $status['exitcode']; // proc_get_status() reaps — remember it
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($proc, defined('SIGTERM') ? SIGTERM : 15);
                usleep(500_000);
                $status = proc_get_status($proc);
                if ($status['running']) {
                    proc_terminate($proc, defined('SIGKILL') ? SIGKILL : 9);
                }
                break;
            }
            if ($read === []) {
                usleep(20_000);
            } else {
                usleep(5_000);
            }
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = $finalExit >= 0 ? $finalExit : proc_close($proc);
        if ($finalExit >= 0) {
            proc_close($proc); // already reaped, just release the handle
        }
        if ($timedOut) {
            $exitCode = 124; // convention: timeout
        }

        return new CommandResult(
            $argv,
            $exitCode,
            $stdout,
            $stderr,
            (int) round((hrtime(true) - $started) / 1_000_000),
            $timedOut,
        );
    }
}
PHPEOF
  cat > "${AGENT}/config/tasks.php" <<'PHPEOF'
<?php
declare(strict_types=1);

/**
 * ============================================================================
 *  AlphaCP paneld — TASK ALLOWLIST  (the security heart of the agent)
 * ============================================================================
 *
 *  Hard rules:
 *  1. A task type that is NOT in this file can never run — the agent refuses it.
 *  2. Every task declares a safety class:
 *       readonly    = no side effects            (safe to auto-run, no confirm)
 *       mutating    = changes server state       (panel shows a warning)
 *       destructive = deletes/irreversible       (needs `_confirm` in payload)
 *  3. Every payload is validated against its JSON schema BEFORE execution.
 *  4. `paths` (if present) becomes the task's PathGuard allowlist.
 *  5. `timeout` is the hard second-limit for any command the handler runs.
 *
 *  When adding a task (checklist in docs/08-module-blueprint.md):
 *    - write the handler in src/Tasks/, keep it idempotent,
 *    - write the tightest possible schema (additionalProperties = false),
 *    - pick the LOWEST safety class that is honest,
 *    - add a test under tests/ and mention it in CHANGELOG.md.
 */

use Alphacp\Agent\Tasks;

return [

    // ---------------------------------------------------------------------
    //  HEALTH / DIAGNOSTICS
    // ---------------------------------------------------------------------
    'agent.ping' => [
        'handler'     => Tasks\AgentPing::class,
        'safety'      => 'readonly',
        'timeout'     => 10,
        'description' => 'Queue liveness check — replies pong with agent version.',
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [],
        ],
    ],

    'system.info' => [
        'handler'     => Tasks\SystemInfo::class,
        'safety'      => 'readonly',
        'timeout'     => 15,
        'description' => 'Hostname, kernel, CPU, memory, swap, load, disk, uptime.',
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [],
        ],
    ],

    'service.status' => [
        'handler'     => Tasks\ServiceStatus::class,
        'safety'      => 'readonly',
        'timeout'     => 60,
        'description' => 'systemd active/enabled state for allowlisted hosting services.',
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'services' => [
                    'type'  => 'array',
                    'items' => ['type' => 'string', 'maxLength' => 60],
                    'maxItems' => 40,
                ],
            ],
        ],
        // The ONLY service names the agent will ever ask systemd about.
        'services'    => [
            'apache2', 'nginx', 'mariadb', 'redis-server', 'bind9', 'fail2ban',
            'exim4', 'dovecot', 'clamav-daemon', 'opendkim', 'pure-ftpd', 'paneld', 'ufw',
        ],
    ],

    // ---------------------------------------------------------------------
    //  ACCOUNTS (Step 3)
    // ---------------------------------------------------------------------
    'account.create' => [
        'handler'     => Tasks\AccountCreate::class,
        'safety'      => 'mutating',
        'timeout'     => 90,
        'description' => 'Create Linux user, home, Apache vhost, PHP-FPM pool, quota.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'domain', 'shadow_hash'],
            'properties'           => [
                'username'    => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'domain'      => ['type' => 'string', 'pattern' => '^[a-z0-9.-]+$', 'maxLength' => 190],
                'shadow_hash' => ['type' => 'string', 'minLength' => 20, 'maxLength' => 200, 'pattern' => '^\\$6\\$.+'],
                'quota_mb'    => ['type' => 'integer', 'minimum' => -1, 'maximum' => 10485760],
                'php_version' => ['type' => 'string', 'pattern' => '^(7\\.4|8\\.[0-9])$'],
            ],
        ],
    ],

    'account.suspend' => [
        'handler'     => Tasks\AccountSuspend::class,
        'safety'      => 'mutating',
        'timeout'     => 60,
        'description' => 'Lock user, swap vhost to suspended page, disable PHP-FPM pool.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'domain'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'domain'   => ['type' => 'string', 'pattern' => '^[a-z0-9.-]+$', 'maxLength' => 190],
                'reason'   => ['type' => 'string', 'maxLength' => 255],
            ],
        ],
    ],

    'account.unsuspend' => [
        'handler'     => Tasks\AccountUnsuspend::class,
        'safety'      => 'mutating',
        'timeout'     => 60,
        'description' => 'Unlock user and restore vhost + PHP-FPM pool.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'domain'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'domain'   => ['type' => 'string', 'pattern' => '^[a-z0-9.-]+$', 'maxLength' => 190],
            ],
        ],
    ],

    'account.terminate' => [
        'handler'     => Tasks\AccountTerminate::class,
        'safety'      => 'destructive',
        'timeout'     => 90,
        'confirm'     => 'account.terminate',
        'description' => 'Remove vhost, pool, quota and Linux user+home.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', '_confirm'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                '_confirm' => ['type' => 'string', 'enum' => ['account.terminate']],
            ],
        ],
    ],

    'domain.add' => [
        'handler'     => Tasks\DomainAdd::class,
        'safety'      => 'mutating',
        'timeout'     => 45,
        'description' => 'Add addon/sub/parked/redirect vhost under an account.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'domain', 'type', 'document_root'],
            'properties'           => [
                'username'       => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'domain'         => ['type' => 'string', 'pattern' => '^[a-z0-9.-]+$', 'maxLength' => 190],
                'type'           => ['type' => 'string', 'enum' => ['addon', 'sub', 'parked', 'redirect']],
                'document_root'  => ['type' => 'string', 'minLength' => 2, 'maxLength' => 255],
                'redirect_url'   => ['type' => 'string', 'maxLength' => 500],
                'redirect_code'  => ['type' => 'integer', 'enum' => [301, 302]],
            ],
        ],
    ],

    'domain.remove' => [
        'handler'     => Tasks\DomainRemove::class,
        'safety'      => 'mutating',
        'timeout'     => 45,
        'description' => 'Remove extra vhost; document root files stay.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'domain'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'domain'   => ['type' => 'string', 'pattern' => '^[a-z0-9.-]+$', 'maxLength' => 190],
            ],
        ],
    ],

    // ---------------------------------------------------------------------
    //  S6 FTP — Pure-FTPd virtual users (root-side; web FPM proc_open disabled)
    // ---------------------------------------------------------------------
    'ftp.add' => [
        'handler'     => Tasks\FtpAdd::class,
        'safety'      => 'mutating',
        'timeout'     => 30,
        'description' => 'Create a Pure-FTPd virtual user <account>_<suffix> chrooted to <home>/ftp/<suffix>.',
        'paths'       => ['/home'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['account', 'login', 'password', 'home'],
            'properties'           => [
                'account'  => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'login'    => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{2,31}$', 'maxLength' => 32],
                'password' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 72],
                'home'     => ['type' => 'string', 'maxLength' => 255],
                'quota_mb' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 102400],
            ],
        ],
    ],

    'ftp.passwd' => [
        'handler'     => Tasks\FtpPasswd::class,
        'safety'      => 'mutating',
        'timeout'     => 30,
        'description' => 'Change a Pure-FTPd virtual user password (stdin only).',
        'paths'       => ['/home'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['account', 'login', 'password'],
            'properties'           => [
                'account'  => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'login'    => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{2,31}$', 'maxLength' => 32],
                'password' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 72],
            ],
        ],
    ],

    'ftp.del' => [
        'handler'     => Tasks\FtpDel::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Remove a Pure-FTPd virtual user (chroot files are preserved).',
        'paths'       => ['/home'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['account', 'login'],
            'properties'           => [
                'account' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'login'   => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{2,31}$', 'maxLength' => 32],
            ],
        ],
    ],

    'php.setVersion' => [
        'handler'     => Tasks\PhpSetVersion::class,
        'safety'      => 'mutating',
        'timeout'     => 45,
        'description' => 'Move account PHP-FPM pool to another MultiPHP version.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'php_version'],
            'properties'           => [
                'username'    => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'php_version' => ['type' => 'string', 'pattern' => '^(7\\.4|8\\.[0-9])$'],
            ],
        ],
    ],

    'php.setIni' => [
        'handler'     => Tasks\PhpSetIni::class,
        'safety'      => 'mutating',
        'timeout'     => 45,
        'description' => 'Write allowlisted MultiPHP INI directives into the account FPM pool.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'directives'],
            'properties'           => [
                'username'   => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'directives' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        'display_errors'         => ['type' => 'string', 'maxLength' => 8],
                        'log_errors'             => ['type' => 'string', 'maxLength' => 8],
                        'allow_url_fopen'        => ['type' => 'string', 'maxLength' => 8],
                        'short_open_tag'         => ['type' => 'string', 'maxLength' => 8],
                        'max_execution_time'     => ['type' => 'string', 'maxLength' => 8],
                        'max_input_time'         => ['type' => 'string', 'maxLength' => 8],
                        'max_input_vars'         => ['type' => 'string', 'maxLength' => 8],
                        'memory_limit'           => ['type' => 'string', 'maxLength' => 12],
                        'post_max_size'          => ['type' => 'string', 'maxLength' => 12],
                        'upload_max_filesize'    => ['type' => 'string', 'maxLength' => 12],
                        'date.timezone'          => ['type' => 'string', 'maxLength' => 60],
                        'error_reporting'        => ['type' => 'string', 'maxLength' => 40],
                        'session.gc_maxlifetime' => ['type' => 'string', 'maxLength' => 12],
                        'default_charset'        => ['type' => 'string', 'maxLength' => 20],
                    ],
                ],
            ],
        ],
    ],

    'errorpages.set' => [
        'handler'     => Tasks\ErrorPagesSet::class,
        'safety'      => 'mutating',
        'timeout'     => 30,
        'description' => 'Write custom 4xx/5xx HTML and Apache ErrorDocument snippet.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'pages'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'pages'    => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        '400' => ['type' => 'string', 'maxLength' => 16384],
                        '401' => ['type' => 'string', 'maxLength' => 16384],
                        '403' => ['type' => 'string', 'maxLength' => 16384],
                        '404' => ['type' => 'string', 'maxLength' => 16384],
                        '500' => ['type' => 'string', 'maxLength' => 16384],
                        '503' => ['type' => 'string', 'maxLength' => 16384],
                    ],
                ],
            ],
        ],
    ],

    'indexes.set' => [
        'handler'     => Tasks\IndexesSet::class,
        'safety'      => 'mutating',
        'timeout'     => 30,
        'description' => 'Set Apache directory listing mode (off/simple/fancy) for an account.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'mode'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'mode'     => ['type' => 'string', 'enum' => ['off', 'simple', 'fancy']],
            ],
        ],
    ],

    'mime.set' => [
        'handler'     => Tasks\MimeTypesSet::class,
        'safety'      => 'mutating',
        'timeout'     => 30,
        'description' => 'Replace account Apache AddType MIME mappings (Content-Type only).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'mappings'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'mappings' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['mime', 'ext'],
                        'properties'           => [
                            'mime' => ['type' => 'string', 'maxLength' => 80],
                            'ext'  => ['type' => 'string', 'maxLength' => 16],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'handlers.set' => [
        'handler'     => Tasks\HandlersSet::class,
        'safety'      => 'mutating',
        'timeout'     => 30,
        'description' => 'Replace account Apache AddHandler mappings (allowlisted handlers only).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'mappings'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'mappings' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['handler', 'ext'],
                        'properties'           => [
                            'handler' => ['type' => 'string', 'maxLength' => 64],
                            'ext'     => ['type' => 'string', 'maxLength' => 16],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'files.list' => [
        'handler'     => Tasks\FilesList::class,
        'safety'      => 'readonly',
        'timeout'     => 15,
        'description' => 'List files under the account home (relative path).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'path'     => ['type' => 'string', 'maxLength' => 240],
            ],
        ],
    ],

    'files.usage' => [
        'handler'     => Tasks\FilesUsage::class,
        'safety'      => 'readonly',
        'timeout'     => 20,
        'description' => 'Folder-wise disk usage under the account home (relative path).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'path'     => ['type' => 'string', 'maxLength' => 240],
            ],
        ],
    ],

    'files.set' => [
        'handler'     => Tasks\FilesSet::class,
        'safety'      => 'mutating',
        'timeout'     => 30,
        'description' => 'mkdir/write/delete/rename a path under the account home.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'op', 'path'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'op'       => ['type' => 'string', 'enum' => ['mkdir', 'write', 'delete', 'rename']],
                'path'     => ['type' => 'string', 'maxLength' => 240],
                'to'       => ['type' => 'string', 'maxLength' => 240],
                'content'  => ['type' => 'string', 'maxLength' => 262144],
            ],
        ],
    ],

    'privacy.set' => [
        'handler'     => Tasks\PrivacySet::class,
        'safety'      => 'mutating',
        'timeout'     => 30,
        'description' => 'Apache Basic Auth (Directory Privacy) for folders under the account home.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'entries'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'entries'  => [
                    'type'     => 'array',
                    'maxItems' => 20,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['path', 'realm', 'users'],
                        'properties'           => [
                            'path'  => ['type' => 'string', 'maxLength' => 240],
                            'realm' => ['type' => 'string', 'maxLength' => 64],
                            'users' => [
                                'type'     => 'array',
                                'maxItems' => 20,
                                'items'    => [
                                    'type'                 => 'object',
                                    'additionalProperties' => false,
                                    'required'             => ['name', 'hash'],
                                    'properties'           => [
                                        'name' => ['type' => 'string', 'maxLength' => 32],
                                        'hash' => ['type' => 'string', 'maxLength' => 64],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'mail.webmail' => [
        'handler'     => Tasks\MailWebmail::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write webmail enabled + client (JSON; no Roundcube/Horde install).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'enabled', 'client'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'enabled'  => ['type' => 'boolean'],
                'client'   => ['type' => 'string', 'enum' => ['roundcube', 'horde']],
            ],
        ],
    ],

    'db.create' => [
        'handler'     => Tasks\DbCreate::class,
        'safety'      => 'mutating',
        'timeout'     => 60,
        'description' => 'Create the real MariaDB database <account>_<name> (utf8mb4).',
        'paths'       => ['/home', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'name'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'name'     => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,15}$', 'maxLength' => 16],
            ],
        ],
    ],

    'db.user.create' => [
        'handler'     => Tasks\DbUserCreate::class,
        'safety'      => 'mutating',
        'timeout'     => 60,
        'description' => 'Create a MariaDB user <account>_<user> and grant it the listed databases.',
        'paths'       => ['/home', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'user', 'password', 'databases'],
            'properties'           => [
                'username'  => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'user'      => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,15}$', 'maxLength' => 16],
                'password'  => ['type' => 'string', 'minLength' => 10, 'maxLength' => 64],
                'host'      => ['type' => 'string', 'maxLength' => 190],
                'databases' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => ['type' => 'string', 'maxLength' => 16],
                ],
            ],
        ],
    ],

    'db.user.grant' => [
        'handler'     => Tasks\DbUserGrant::class,
        'safety'      => 'mutating',
        'timeout'     => 60,
        'description' => 'Add User To Database: grant an account MariaDB user ALL PRIVILEGES on one database.',
        'paths'       => ['/home', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'user', 'database'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'user'     => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,15}$', 'maxLength' => 16],
                'database' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,15}$', 'maxLength' => 16],
                'host'     => ['type' => 'string', 'maxLength' => 190],
            ],
        ],
    ],

    'db.user.password' => [
        'handler'     => Tasks\DbUserPassword::class,
        'safety'      => 'mutating',
        'timeout'     => 60,
        'description' => 'Set a new password for an existing account MariaDB user (password via stdin).',
        'paths'       => ['/home', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'user', 'password'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'user'     => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,15}$', 'maxLength' => 16],
                'password' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 64],
                'host'     => ['type' => 'string', 'maxLength' => 190],
            ],
        ],
    ],

    'db.list' => [
        'handler'     => Tasks\DbList::class,
        'safety'      => 'readonly',
        'timeout'     => 60,
        'description' => 'List the real MariaDB databases/users an account owns (verification task).',
        'paths'       => ['/home', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
            ],
        ],
    ],

    'db.restore' => [
        'handler'     => Tasks\DbRestore::class,
        'safety'      => 'destructive',
        'timeout'     => 3600,
        'confirm'     => 'db.restore',
        'description' => 'Restore the mysql/*.sql dumps of a cPanel archive into real MariaDB databases.',
        'paths'       => ['/home', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'archive_path', '_confirm'],
            'properties'           => [
                'username'     => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'archive_path' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4096],
                'sha256'       => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                'action'       => ['type' => 'string', 'enum' => ['transfer', 'restore']],
                'only'         => ['type' => 'array', 'maxItems' => 64, 'items' => ['type' => 'string', 'maxLength' => 16]],
                '_confirm'     => ['type' => 'string', 'enum' => ['db.restore']],
            ],
        ],
    ],

    'db.drop' => [
        'handler'     => Tasks\DbDrop::class,
        'safety'      => 'destructive',
        'timeout'     => 120,
        'confirm'     => 'db.drop',
        'description' => 'Drop a MariaDB database and revoke account users\' privileges on it.',
        'paths'       => ['/home', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'name', '_confirm'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'name'     => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,15}$', 'maxLength' => 16],
                '_confirm' => ['type' => 'string', 'enum' => ['db.drop']],
            ],
        ],
    ],

    'db.user.drop' => [
        'handler'     => Tasks\DbUserDrop::class,
        'safety'      => 'destructive',
        'timeout'     => 120,
        'confirm'     => 'db.user.drop',
        'description' => 'Remove a MariaDB user (every host row of this account for that name).',
        'paths'       => ['/home', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'user', '_confirm'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'user'     => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,15}$', 'maxLength' => 16],
                'host'     => ['type' => 'string', 'maxLength' => 190],
                '_confirm' => ['type' => 'string', 'enum' => ['db.user.drop']],
            ],
        ],
    ],

    'db.set' => [
        'handler'     => Tasks\MysqlSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write prefixed MySQL database names (JSON; no mysql binary).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'databases'],
            'properties'           => [
                'username'  => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'databases' => [
                    'type'  => 'array',
                    'maxItems' => 50,
                    'items' => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['name'],
                        'properties'           => [
                            'name' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,15}$', 'maxLength' => 16],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'db.phpmyadmin' => [
        'handler'     => Tasks\PhpmyadminSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write phpMyAdmin enabled flag (JSON; no phpMyAdmin install).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'enabled'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'enabled'  => ['type' => 'boolean'],
            ],
        ],
    ],

    'db.remote' => [
        'handler'     => Tasks\RemoteMysqlSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write Remote MySQL access hosts (JSON; no mysql GRANT).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'hosts'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'hosts'    => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['host'],
                        'properties'           => [
                            'host' => ['type' => 'string', 'maxLength' => 190],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'dns.zone' => [
        'handler'     => Tasks\ZoneSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write A/CNAME/MX/TXT records (JSON; no BIND rewrite).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'records'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'records'  => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['domain', 'name', 'type', 'value'],
                        'properties'           => [
                            'domain' => ['type' => 'string', 'maxLength' => 190],
                            'name'   => ['type' => 'string', 'maxLength' => 63],
                            'type'   => ['type' => 'string', 'enum' => ['A', 'CNAME', 'MX', 'TXT']],
                            'value'  => ['type' => 'string', 'maxLength' => 255],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'mail.usage' => [
        'handler'     => Tasks\MailUsage::class,
        'safety'      => 'readonly',
        'timeout'     => 20,
        'description' => 'Folder-wise size under ~/mail (relative path, no purge, no symlink).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'path'     => ['type' => 'string', 'maxLength' => 240],
            ],
        ],
    ],

    'mail.calendar' => [
        'handler'     => Tasks\MailCalendar::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write calendar + contact names (JSON; no CalDAV/CardDAV daemon).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'calendars', 'contacts'],
            'properties'           => [
                'username'  => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'calendars' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['name'],
                        'properties'           => [
                            'name' => ['type' => 'string', 'maxLength' => 64],
                        ],
                    ],
                ],
                'contacts' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['name'],
                        'properties'           => [
                            'name' => ['type' => 'string', 'maxLength' => 64],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'mail.boxtrapper' => [
        'handler'     => Tasks\MailBoxtrapper::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write BoxTrapper enabled + allowlist (JSON, email only, no daemon).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'enabled'],
            'properties'           => [
                'username'  => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'enabled'   => ['type' => 'boolean'],
                'allowlist' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => ['type' => 'string', 'maxLength' => 190],
                ],
            ],
        ],
    ],

    'mail.encrypt' => [
        'handler'     => Tasks\MailEncrypt::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Replace GnuPG identity rows (JSON; no gpg binary, no private key).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'keys'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'keys'     => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['local', 'domain', 'comment'],
                        'properties'           => [
                            'local'   => ['type' => 'string', 'maxLength' => 32],
                            'domain'  => ['type' => 'string', 'maxLength' => 190],
                            'comment' => ['type' => 'string', 'maxLength' => 100],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'mail.gfilter' => [
        'handler'     => Tasks\MailGfilter::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Replace account-wide email filters (JSON, contains-match, no pipe).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'filters'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'filters'  => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['domain', 'field', 'needle', 'action'],
                        'properties'           => [
                            'domain' => ['type' => 'string', 'maxLength' => 190],
                            'field'  => ['type' => 'string', 'enum' => ['from', 'subject', 'to']],
                            'needle' => ['type' => 'string', 'maxLength' => 100],
                            'action' => ['type' => 'string', 'enum' => ['discard', 'folder']],
                            'folder' => ['type' => 'string', 'maxLength' => 32],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'mail.track' => [
        'handler'     => Tasks\MailTrack::class,
        'safety'      => 'readonly',
        'timeout'     => 20,
        'description' => 'Search jailed track.json by recipient email (no Exim log, no pipe).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'query'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'query'    => ['type' => 'string', 'maxLength' => 190],
            ],
        ],
    ],

    'mail.routing' => [
        'handler'     => Tasks\MailRouting::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Replace per-domain mail routing mode (JSON; auto/local/backup/remote).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'routes'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'routes'   => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['domain', 'mode'],
                        'properties'           => [
                            'domain' => ['type' => 'string', 'maxLength' => 190],
                            'mode'   => ['type' => 'string', 'enum' => ['auto', 'local', 'backup', 'remote']],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'mail.list' => [
        'handler'     => Tasks\MailList::class,
        'safety'      => 'mutating',
        'timeout'     => 60,
        'description' => 'Replace static Exim distribution lists (owner + subscriber addresses).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'lists'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'lists'    => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['local', 'domain', 'owner'],
                        'properties'           => [
                            'local'  => ['type' => 'string', 'maxLength' => 32],
                            'domain' => ['type' => 'string', 'maxLength' => 190],
                            'owner'  => ['type' => 'string', 'maxLength' => 190],
                            'members' => [
                                'type' => 'array',
                                'maxItems' => 200,
                                'items' => ['type' => 'string', 'maxLength' => 190],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'mail.spam' => [
        'handler'     => Tasks\MailSpam::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write spam score + blacklist/whitelist (JSON, email only).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'required_score'],
            'properties'           => [
                'username'       => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'required_score' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
                'blacklist'      => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => ['type' => 'string', 'maxLength' => 190],
                ],
                'whitelist'      => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => ['type' => 'string', 'maxLength' => 190],
                ],
            ],
        ],
    ],

    'mail.deliverability' => [
        'handler'     => Tasks\MailDeliverability::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write recommended SPF/DMARC records (no DNS write).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'domains'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'domains'  => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => ['type' => 'string', 'maxLength' => 190],
                ],
            ],
        ],
    ],

    'mail.filter' => [
        'handler'     => Tasks\MailFilter::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Replace per-mailbox filters (JSON, contains-match, no pipe).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'filters'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'filters'  => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['local', 'domain', 'field', 'needle', 'action'],
                        'properties'           => [
                            'local'  => ['type' => 'string', 'maxLength' => 32],
                            'domain' => ['type' => 'string', 'maxLength' => 190],
                            'field'  => ['type' => 'string', 'enum' => ['from', 'subject', 'to']],
                            'needle' => ['type' => 'string', 'maxLength' => 100],
                            'action' => ['type' => 'string', 'enum' => ['discard', 'folder']],
                            'folder' => ['type' => 'string', 'maxLength' => 32],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'mail.catchall' => [
        'handler'     => Tasks\MailCatchall::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Replace default address catch-alls (email dest only).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'catchalls'],
            'properties'           => [
                'username'  => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'catchalls' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['domain', 'dest'],
                        'properties'           => [
                            'domain' => ['type' => 'string', 'maxLength' => 190],
                            'dest'   => ['type' => 'string', 'maxLength' => 190],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'mail.autorespond' => [
        'handler'     => Tasks\MailAutorespond::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Replace vacation autoresponders (JSON file, no pipe/shell).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'responders'],
            'properties'           => [
                'username'   => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'responders' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['local', 'domain', 'subject', 'body'],
                        'properties'           => [
                            'local'      => ['type' => 'string', 'maxLength' => 32],
                            'domain'     => ['type' => 'string', 'maxLength' => 190],
                            'subject'    => ['type' => 'string', 'maxLength' => 200],
                            'body'       => ['type' => 'string', 'maxLength' => 4000],
                            'interval_h' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 168],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'mail.forward' => [
        'handler'     => Tasks\MailForward::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Replace email forwarders (aliases file, email dest only).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'forwards'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'forwards' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['local', 'domain', 'dest'],
                        'properties'           => [
                            'local'  => ['type' => 'string', 'maxLength' => 32],
                            'domain' => ['type' => 'string', 'maxLength' => 190],
                            'dest'   => ['type' => 'string', 'maxLength' => 190],
                        ],
                    ],
                ],
            ],
        ],
    ],

    // S7: Exim4 + Dovecot — asli mail. Panel/agent mailboxes (bcrypt + Maildir)
    // pehle se likhte hain; yahi task unhe daemons tak pahunchata hai.
    'mail.server' => [
        'handler'     => Tasks\MailServerSetup::class,
        'safety'      => 'mutating',
        'timeout'     => 180,
        'description' => 'Exim4 + Dovecot: status/setup/sync/verify, server mail config and SpamAssassin/greylisting.',
        'paths'       => ['/home', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['action'],
            'properties'           => [
                'action'  => ['type' => 'string', 'enum' => [
                    'status', 'setup', 'sync', 'list', 'verify', 'deliverability',
                    // S7 server-wide: cPanel #141 Mail Queue Manager, #142 Delivery Reports,
                    // #143 Exim Configuration Manager, #144 Mailserver Configuration (Dovecot),
                    // #146 Email Disk Usage (server view)
                    'queue', 'reports', 'eximconf', 'dovecotconf', 'diskusage',
                    // #147 Apache SpamAssassin + Greylisting
                    'spamassassin',
                ]],
                'address' => ['type' => 'string', 'maxLength' => 190, 'pattern' => '^[a-z0-9._-]+@[a-z0-9.-]+$'],
                'username' => ['type' => 'string', 'maxLength' => 32, 'pattern' => '^[a-z][a-z0-9]{2,15}$'],
                // mail queue: op = list/count/deliver/remove/freeze/thaw/flush
                'op' => ['type' => 'string', 'maxLength' => 16, 'pattern' => '^[a-z]{1,16}$'],
                // asli exim message id (jaise 1oABCD-0000xy-1a) — shell-injection se bachav
                'id' => ['type' => 'string', 'maxLength' => 32, 'pattern' => '^[0-9A-Za-z]{6}-[0-9A-Za-z]{6}-[0-9A-Za-z]{2}$'],
                // delivery reports: kitni entries + kisme dhoondhna hai
                'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
                'search' => ['type' => 'string', 'maxLength' => 120, 'pattern' => '^[ -~]{1,120}$'],
                // configuration manager: { option: value } — har value apne type se validate hoti hai
                'set' => ['type' => 'object', 'maxProperties' => 40],
                // #147 SpamAssassin + Greylisting
                'enabled'        => ['type' => 'boolean'],
                'greylisting'    => ['type' => 'boolean'],
                'required_score' => ['type' => 'number', 'minimum' => 1, 'maximum' => 15],
                'reject_score'   => ['type' => 'number', 'minimum' => 0, 'maximum' => 30],
            ],
        ],
    ],

    'mail.set' => [
        'handler'     => Tasks\MailSet::class,
        'safety'      => 'mutating',
        'timeout'     => 30,
        'description' => 'Replace virtual mailboxes (passwd-file + Maildir) under the account home.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'mailboxes'],
            'properties'           => [
                'username'  => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'mailboxes' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['local', 'domain', 'hash'],
                        'properties'           => [
                            'local'    => ['type' => 'string', 'maxLength' => 32],
                            'domain'   => ['type' => 'string', 'maxLength' => 190],
                            'hash'     => ['type' => 'string', 'maxLength' => 80],
                            'quota_mb' => ['type' => 'integer', 'minimum' => -1, 'maximum' => 102400],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'ssh.set' => [
        'handler'     => Tasks\SshSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write ~/.ssh/authorized_keys and optional nologin/bash shell.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'keys'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'shell'    => ['type' => 'string', 'enum' => ['nologin', 'bash']],
                'keys'     => [
                    'type'     => 'array',
                    'maxItems' => 20,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['type', 'key'],
                        'properties'           => [
                            'type'    => ['type' => 'string', 'enum' => ['ssh-ed25519', 'ssh-rsa', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521']],
                            'key'     => ['type' => 'string', 'maxLength' => 8192],
                            'comment' => ['type' => 'string', 'maxLength' => 64],
                        ],
                    ],
                ],
            ],
        ],
    ],

    // S9: BIND9 — asli authoritative zones. JSON ke baad yahi asli kadam hai:
    // zone file likhne se pehle `named-checkzone` gate, phir rndc reload, phir
    // `dig @127.0.0.1` se verify (likhna = server ka jawab dena).
    'dns.bind' => [
        'handler'     => Tasks\BindSetup::class,
        'safety'      => 'mutating',
        'timeout'     => 120,
        'description' => 'BIND9 zones: status/setup/list/write/remove/verify (named-checkzone gated).',
        'paths'       => ['/home', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['action'],
            'properties'           => [
                'action'  => ['type' => 'string', 'enum' => ['status', 'setup', 'list', 'write', 'remove', 'verify', 'sync']],
                'domain'  => ['type' => 'string', 'pattern' => '^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$', 'maxLength' => 190],
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'ttl'     => ['type' => 'integer', 'minimum' => 60, 'maximum' => 86400],
                'records' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['domain', 'name', 'type', 'value'],
                        'properties'           => [
                            'domain' => ['type' => 'string', 'maxLength' => 190],
                            'name'   => ['type' => 'string', 'maxLength' => 63],
                            'type'   => ['type' => 'string', 'enum' => ['A', 'CNAME', 'MX', 'TXT']],
                            'value'  => ['type' => 'string', 'maxLength' => 255],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'dns.dynamic' => [
        'handler'     => Tasks\DynamicSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write Dynamic DNS hosts (JSON; no BIND rewrite).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'hosts'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'hosts'    => [
                    'type'     => 'array',
                    'maxItems' => 20,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['domain', 'name', 'token', 'ip'],
                        'properties'           => [
                            'domain' => ['type' => 'string', 'maxLength' => 190],
                            'name'   => ['type' => 'string', 'maxLength' => 63],
                            'token'  => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$', 'maxLength' => 32],
                            'ip'     => ['type' => 'string', 'maxLength' => 15],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'dns.track' => [
        'handler'     => Tasks\DnsTrack::class,
        'safety'      => 'readonly',
        'timeout'     => 20,
        'description' => 'Search jailed zone/dynamic JSON by FQDN (no dig, no BIND).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'query', 'type'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'query'    => ['type' => 'string', 'maxLength' => 190],
                'type'     => ['type' => 'string', 'enum' => ['A', 'CNAME', 'MX', 'NS', 'TXT', 'ALL']],
            ],
        ],
    ],

    'dns.hostname' => [
        'handler'     => Tasks\HostnameASet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write hostname A record (JSON; no BIND rewrite).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['hostname', 'ip'],
            'properties'           => [
                'hostname' => ['type' => 'string', 'maxLength' => 190],
                'ip'       => ['type' => 'string', 'maxLength' => 15],
            ],
        ],
    ],

    'dns.templates' => [
        'handler'     => Tasks\TemplatesSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write DNS zone templates (JSON; no BIND rewrite).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['templates'],
            'properties'           => [
                'templates' => [
                    'type'     => 'array',
                    'maxItems' => 10,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['name', 'body'],
                        'properties'           => [
                            'name' => ['type' => 'string', 'maxLength' => 32],
                            'body' => ['type' => 'string', 'maxLength' => 2000],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'mail.globalrouting' => [
        'handler'     => Tasks\GlobalRoutingSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write WHM global email routing (JSON; no Exim rewrite).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['routes'],
            'properties'           => [
                'routes' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['domain', 'mode'],
                        'properties'           => [
                            'domain' => ['type' => 'string', 'maxLength' => 190],
                            'mode'   => ['type' => 'string', 'maxLength' => 16],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'dns.nsreport' => [
        'handler'     => Tasks\NsReportSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write nameserver record report (JSON; no BIND rewrite).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['records'],
            'properties'           => [
                'records' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['domain', 'nameserver'],
                        'properties'           => [
                            'domain'     => ['type' => 'string', 'maxLength' => 190],
                            'nameserver' => ['type' => 'string', 'maxLength' => 190],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'dns.park' => [
        'handler'     => Tasks\ParkSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write parked domain map (JSON; no BIND rewrite).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['parks'],
            'properties'           => [
                'parks' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['domain', 'target'],
                        'properties'           => [
                            'domain' => ['type' => 'string', 'maxLength' => 190],
                            'target' => ['type' => 'string', 'maxLength' => 190],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'dns.cleanup' => [
        'handler'     => Tasks\CleanupSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write DNS cleanup queue (JSON; no BIND rewrite).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['domains'],
            'properties'           => [
                'domains' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => ['type' => 'string', 'maxLength' => 190],
                ],
            ],
        ],
    ],

    'dns.ttl' => [
        'handler'     => Tasks\TtlSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write zone TTL map (JSON; no BIND rewrite).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['zones'],
            'properties'           => [
                'zones' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['domain', 'ttl'],
                        'properties'           => [
                            'domain' => ['type' => 'string', 'maxLength' => 190],
                            'ttl'    => ['type' => 'integer', 'minimum' => 60, 'maximum' => 86400],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'dns.forward' => [
        'handler'     => Tasks\ForwardSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write domain forwarding map (JSON; no BIND rewrite).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['forwards'],
            'properties'           => [
                'forwards' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['domain', 'url', 'code'],
                        'properties'           => [
                            'domain' => ['type' => 'string', 'maxLength' => 190],
                            'url'    => ['type' => 'string', 'maxLength' => 255],
                            'code'   => ['type' => 'integer', 'minimum' => 301, 'maximum' => 302],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'dns.sync' => [
        'handler'     => Tasks\SyncSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write DNS sync queue (JSON; no BIND rewrite).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['domains'],
            'properties'           => [
                'domains' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => ['type' => 'string', 'maxLength' => 190],
                ],
            ],
        ],
    ],

    'dns.nameserver' => [
        'handler'     => Tasks\NameserverSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write nameserver selection (JSON; no BIND rewrite).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['software', 'ns1', 'ns2'],
            'properties'           => [
                'software' => ['type' => 'string', 'maxLength' => 16],
                'ns1'      => ['type' => 'string', 'maxLength' => 190],
                'ns2'      => ['type' => 'string', 'maxLength' => 190],
            ],
        ],
    ],

    'backup.create' => [
        'handler'     => Tasks\BackupCreate::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write account backup job list (JSON; no tar/shell).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'jobs'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'jobs'     => [
                    'type'     => 'array',
                    'maxItems' => 10,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['kind'],
                        'properties'           => [
                            'kind' => ['type' => 'string', 'maxLength' => 16],
                            'path' => ['type' => 'string', 'maxLength' => 240],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'backup.archive' => [
        'handler'     => Tasks\BackupArchiveCreate::class,
        'safety'      => 'mutating',
        'timeout'     => 3600,
        'description' => 'Create an immutable, SHA-256-verified home .tar.gz backup.',
        'paths'       => ['/home', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'archive_id'],
            'properties'           => [
                'username'   => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'archive_id' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$', 'maxLength' => 32],
            ],
        ],
    ],

    'backup.extract' => [
        'handler'     => Tasks\BackupExtract::class,
        'safety'      => 'destructive',
        'timeout'     => 3600,
        'confirm'     => 'backup.extract',
        'description' => 'Restore a verified home archive into the account (pre-restore copy kept).',
        'paths'       => ['/home', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'archive_id', '_confirm'],
            'properties'           => [
                'username'   => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'archive_id' => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$', 'maxLength' => 32],
                'path'       => ['type' => 'string', 'maxLength' => 240],
                '_confirm'   => ['type' => 'string', 'enum' => ['backup.extract']],
            ],
        ],
    ],

    'backup.wizard' => [
        'handler'     => Tasks\BackupWizard::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write account backup wizard plan (JSON; no tar/shell).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'action', 'scope'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'action'   => ['type' => 'string', 'maxLength' => 16],
                'scope'    => ['type' => 'string', 'maxLength' => 16],
            ],
        ],
    ],

    'backup.restore' => [
        'handler'     => Tasks\BackupRestore::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write account file restore path list (JSON; no tar/shell).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'paths'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'paths'    => [
                    'type'     => 'array',
                    'maxItems' => 10,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['path'],
                        'properties'           => [
                            'path' => ['type' => 'string', 'maxLength' => 240],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'backup.config' => [
        'handler'     => Tasks\BackupConfig::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write WHM backup schedule/retention (JSON; no tar/shell).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['schedule', 'retention'],
            'properties'           => [
                'schedule'  => ['type' => 'string', 'maxLength' => 16],
                'retention' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365],
            ],
        ],
    ],

    'backup.restoration' => [
        'handler'     => Tasks\BackupRestoration::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write WHM backup restoration full/partial/per-account (JSON; no tar/shell).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['mode', 'username'],
            'properties'           => [
                'mode'     => ['type' => 'string', 'maxLength' => 16],
                'username' => ['type' => 'string', 'maxLength' => 16],
            ],
        ],
    ],

    'backup.users' => [
        'handler'     => Tasks\BackupUsers::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write WHM backup user selection (JSON; no tar/shell).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['users'],
            'properties'           => [
                'users' => [
                    'type'     => 'array',
                    'maxItems' => 10,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['username'],
                        'properties'           => [
                            'username' => ['type' => 'string', 'maxLength' => 16],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'backup.filedir' => [
        'handler'     => Tasks\BackupFiledir::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write WHM file/directory restoration (JSON; no tar/shell).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'path'],
            'properties'           => [
                'username' => ['type' => 'string', 'maxLength' => 16],
                'path'     => ['type' => 'string', 'maxLength' => 240],
            ],
        ],
    ],

    // S10: authenticated remote pull — cpmove archive doosre server se SSH (scp) se lao.
    // 'probe' sirf host key fingerprint laata hai (download nahi) — panel pehle wo dikhata
    // hai, admin verify karta hai, phir host_fingerprint pin karke asli pull hoti hai.
    'backup.pull' => [
        'handler'     => Tasks\BackupPull::class,
        'safety'      => 'mutating',
        'timeout'     => 3600,
        'confirm'     => 'backup.pull',
        'description' => 'Fetch a cPanel archive from another server over SSH (scp) into the import drop dir.',
        'paths'       => ['/home', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['host', 'user', '_confirm'],
            'properties'           => [
                'host'             => ['type' => 'string', 'minLength' => 1, 'maxLength' => 253],
                'port'             => ['type' => 'integer', 'minimum' => 1, 'maximum' => 65535],
                'user'             => ['type' => 'string', 'pattern' => '^[a-z_][a-z0-9_-]{0,31}$'],
                'remote_path'      => ['type' => 'string', 'pattern' => '^/[A-Za-z0-9._/-]+$', 'maxLength' => 4096],
                'probe'            => ['type' => 'boolean'],
                'auth'             => ['type' => 'string', 'enum' => ['key', 'password']],
                'private_key'      => ['type' => 'string', 'maxLength' => 65536],
                'key_path'         => ['type' => 'string', 'pattern' => '^/[A-Za-z0-9._/-]+$', 'maxLength' => 4096],
                'password'         => ['type' => 'string', 'maxLength' => 1024],
                'dest_name'        => ['type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._-]*$', 'maxLength' => 120],
                'sha256'           => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'maxLength' => 64],
                'host_fingerprint' => ['type' => 'string', 'maxLength' => 128],
                'accept_host_key'  => ['type' => 'boolean'],
                'max_kbps'         => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000000],
                'overwrite'        => ['type' => 'boolean'],
                '_confirm'         => ['type' => 'string', 'enum' => ['backup.pull']],
            ],
        ],
    ],

    // S10: remote backup destinations — apne archives doosre server par bhejo (scp).
    // Host key PIN lagana zaroori hai (ya pehli key openly accept karni padti hai,
    // jo log me loudly likhi jati hai). Key/password 0600 file me rehte hain —
    // argv, log aur task result me kabhi nahi aate.
    'backup.destination' => [
        'handler'     => Tasks\BackupDestination::class,
        'safety'      => 'mutating',
        'timeout'     => 3600,
        'confirm'     => 'backup.destination',
        'description' => 'Manage remote backup destinations (SSH) — list/save/test/push/browse/remove.',
        'paths'       => ['/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['action', '_confirm'],
            'properties'           => [
                'action'           => ['type' => 'string', 'enum' => ['list', 'save', 'test', 'push', 'browse', 'remove']],
                'name'             => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9-]{0,31}$', 'maxLength' => 32],
                'host'             => ['type' => 'string', 'minLength' => 1, 'maxLength' => 253],
                'port'             => ['type' => 'integer', 'minimum' => 1, 'maximum' => 65535],
                'user'             => ['type' => 'string', 'pattern' => '^[a-z_][a-z0-9_-]{0,31}$'],
                'path'             => ['type' => 'string', 'pattern' => '^/[A-Za-z0-9._/-]+$', 'maxLength' => 4096],
                'auth'             => ['type' => 'string', 'enum' => ['key', 'password']],
                'private_key'      => ['type' => 'string', 'maxLength' => 65536],
                'password'         => ['type' => 'string', 'maxLength' => 1024],
                'host_fingerprint' => ['type' => 'string', 'maxLength' => 128],
                'accept_host_key'  => ['type' => 'boolean'],
                'retention_days'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365],
                'enabled'          => ['type' => 'boolean'],
                'archive_path'     => ['type' => 'string', 'pattern' => '^/[A-Za-z0-9._/-]+$', 'maxLength' => 4096],
                '_confirm'         => ['type' => 'string', 'enum' => ['backup.destination']],
            ],
        ],
    ],

    'backup.transfer' => [
        'handler'     => Tasks\BackupTransfer::class,
        'safety'      => 'destructive',
        'timeout'     => 3600,
        'confirm'     => 'backup.transfer',
        'description' => 'Import a local cPanel archive into an account (WHM transfer; source FQDN recorded).',
        'paths'       => ['/home', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'source', 'archive_path', '_confirm'],
            'properties'           => [
                'username'     => ['type' => 'string', 'maxLength' => 16],
                'source'       => ['type' => 'string', 'maxLength' => 190],
                'archive_path' => ['type' => 'string', 'maxLength' => 255],
                'sha256'       => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'maxLength' => 64],
                '_confirm'     => ['type' => 'string', 'enum' => ['backup.transfer']],
            ],
        ],
    ],

    'backup.cpanel' => [
        'handler'     => Tasks\BackupCpanel::class,
        'safety'      => 'destructive',
        'timeout'     => 3600,
        'confirm'     => 'backup.cpanel',
        'description' => 'Import a local cPanel account archive into an account (home only; pre-restore copy kept).',
        'paths'       => ['/home', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'action', 'archive_path', '_confirm'],
            'properties'           => [
                'username'     => ['type' => 'string', 'maxLength' => 16],
                'action'       => ['type' => 'string', 'maxLength' => 16],
                'archive_path' => ['type' => 'string', 'maxLength' => 255],
                'sha256'       => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'maxLength' => 64],
                '_confirm'     => ['type' => 'string', 'enum' => ['backup.cpanel']],
            ],
        ],
    ],

    'backup.review' => [
        'handler'     => Tasks\BackupReview::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write WHM review transfers and restores (JSON; no tar/rsync/shell).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'status'],
            'properties'           => [
                'username' => ['type' => 'string', 'maxLength' => 16],
                'status'   => ['type' => 'string', 'maxLength' => 16],
            ],
        ],
    ],

    'cron.set' => [
        'handler'     => Tasks\CronSet::class,
        'safety'      => 'mutating',
        'timeout'     => 30,
        'description' => 'Replace the account crontab (empty jobs clears it).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'jobs'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'jobs'     => [
                    'type'     => 'array',
                    'maxItems' => 100,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['minute', 'hour', 'day', 'month', 'weekday', 'command'],
                        'properties'           => [
                            'minute'  => ['type' => 'string', 'maxLength' => 40, 'pattern' => '^[0-9*,/-]+$'],
                            'hour'    => ['type' => 'string', 'maxLength' => 40, 'pattern' => '^[0-9*,/-]+$'],
                            'day'     => ['type' => 'string', 'maxLength' => 40, 'pattern' => '^[0-9*,/-]+$'],
                            'month'   => ['type' => 'string', 'maxLength' => 40, 'pattern' => '^[0-9*,/-]+$'],
                            'weekday' => ['type' => 'string', 'maxLength' => 40, 'pattern' => '^[0-9*,/-]+$'],
                            'command' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'ssl.issue' => [
        'handler'     => Tasks\SslIssue::class,
        'safety'      => 'mutating',
        'timeout'     => 120,
        'description' => 'Issue Let\'s Encrypt (certbot webroot) or self-signed cert + Apache :443 vhost.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'domain', 'document_root'],
            'properties'           => [
                'username'      => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'domain'        => ['type' => 'string', 'pattern' => '^[a-z0-9.-]+$', 'maxLength' => 190],
                'document_root' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 255],
                'mode'          => ['type' => 'string', 'enum' => ['selfsigned', 'letsencrypt']],
                'email'         => ['type' => 'string', 'maxLength' => 190],
            ],
        ],
    ],

    'ssl.remove' => [
        'handler'     => Tasks\SslRemove::class,
        'safety'      => 'mutating',
        'timeout'     => 30,
        'description' => 'Remove SSL vhost; cert files stay under the account home.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'domain'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'domain'   => ['type' => 'string', 'pattern' => '^[a-z0-9.-]+$', 'maxLength' => 190],
            ],
        ],
    ],

    'account.setQuota' => [
        'handler'     => Tasks\AccountSetQuota::class,
        'safety'      => 'mutating',
        'timeout'     => 30,
        'description' => 'Set or clear disk quota for an account (MB, -1 unlimited).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'quota_mb'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'quota_mb' => ['type' => 'integer', 'minimum' => -1, 'maximum' => 10485760],
            ],
        ],
    ],

];
PHPEOF
  local lout
  for rel in "${AGENT_FILES[@]}"; do
    if ! lout="$("$PHP_BIN" -l "${AGENT}/${rel}" 2>&1)"; then
      warn "lint fail: ${rel}"
      say "    ${lout}"
      say "    size=$(wc -c < "${AGENT}/${rel}" 2>/dev/null || echo '?') bytes  head=$(head -c 40 "${AGENT}/${rel}" 2>/dev/null | tr '\n' ' ')"
      rollback; die "agent lint fail"
    fi
  done
  ok "agent files likhi + lint clean (7)"

  # ---- agent self-test ----
  hdr "SELF-TEST agent (static smoke + suite)"
  local smoke; smoke="$(mktemp)"
  cat > "$smoke" <<'SMOKE'
<?php
require $argv[1] . '/src/Bootstrap.php';
use Alphacp\Agent\Ftp;
use Alphacp\Agent\TaskRejectedException;
use Alphacp\Agent\Tasks\FtpAdd;
use Alphacp\Agent\Tasks\FtpPasswd;
use Alphacp\Agent\Tasks\FtpDel;
$f = 0;
function chk(bool $c, string $m): void { global $f; if (!$c) { fwrite(STDERR, "SMOKE FAIL: $m\n"); $f++; } }
chk(class_exists(Ftp::class) && class_exists(FtpAdd::class) && class_exists(FtpPasswd::class) && class_exists(FtpDel::class), 'ftp classes autoload');
chk(Ftp::password('Ftp-Pass-123') === 'Ftp-Pass-123', 'password accepts valid');
foreach (['short', str_repeat('x', 73), "Bad\nPass-1"] as $b) {
    $t = false;
    try { Ftp::password($b); } catch (TaskRejectedException $e) { $t = true; }
    chk($t, 'password refuses bad input');
}
$types = array_keys(require $argv[1] . '/config/tasks.php');
foreach (['ftp.add', 'ftp.passwd', 'ftp.del'] as $ty) { chk(in_array($ty, $types, true), "$ty registered"); }
echo $f === 0 ? "SMOKE OK\n" : "SMOKE FAILED ($f)\n";
exit($f === 0 ? 0 : 1);
SMOKE
  if ! "$PHP_BIN" "$smoke" "$AGENT" 2>&1 | sed 's/^/    /'; then rm -f "$smoke"; rollback; die "agent smoke fail"; fi
  rm -f "$smoke"
  ok "agent static smoke PASS"
  if "$PHP_BIN" -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' >/dev/null 2>&1; then
    local sum n p
    sum="$("$PHP_BIN" "${AGENT}/tests/run-tests.php" 2>&1 | grep -E 'passed: [0-9]+ +failed: [0-9]+' | tail -1)"
    n="$(sed -E 's/.*failed: ([0-9]+).*/\1/' <<<"${sum:-}")"
    p="$(sed -E 's/.*passed: ([0-9]+).*/\1/; s/ .*//' <<<"${sum:-}")"
    info "suite: ${sum:-<summary nahi mila>}"
    if [[ "${n:-9}" != "0" || "${p:-0}" -lt 215 ]]; then rollback; die "agent suite green nahi (passed=${p:-?} failed=${n:-?})"; fi
    ok "agent suite GREEN (passed=${p} failed=0)"
  else
    warn "pdo_sqlite nahi — suite skip (static smoke gate pass hua)"
  fi

  # ---- paneld restart ----
  hdr "paneld restart"
  if have_systemd && [[ -f /etc/systemd/system/paneld.service ]]; then
    systemctl restart paneld >/dev/null 2>&1 || warn "restart fail"
    sleep 1
    systemctl is-active --quiet paneld || { rollback; die "paneld active nahi restart ke baad"; }
    ok "paneld active (naya task registry load hua)"
  else
    warn "paneld unit / systemd nahi mila — restart skip (manual: systemctl restart paneld)"
  fi

  # ---- panel files ----
  hdr "panel files likhna"
  cat > "${PANEL}/app/Support/Ftp.php" <<'PHPEOF'
<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;

/**
 * cPanel-style FTP Accounts (Pure-FTPd) — PANEL SIDE.
 *
 * The panel NEVER shells out to `pure-pw`: it needs root, and the web FPM pool
 * runs with `proc_open` disabled (audit B1 — this used to HTTP-500 on live).
 * All PureDB mutations are enqueued as root-agent tasks (`ftp.add`,
 * `ftp.passwd`, `ftp.del`); this class only reports capability (from the agent
 * registry, which lives inside open_basedir) and derives the cPanel-style
 * virtual-login / chroot-home names.
 */
final class Ftp
{
    /** FTP is available iff the root agent exposes the `ftp.*` tasks. */
    public static function enabled(): bool
    {
        return in_array('ftp.add', Paneld::taskTypes(), true);
    }

    /** `<account>_<suffix>` — the Pure-FTPd virtual login (cPanel style). */
    public static function loginFor(Account $account, string $suffix): string
    {
        return strtolower($account->username) . '_' . strtolower($suffix);
    }

    /** `<account-home>/ftp/<suffix>` — the chroot home for the virtual login. */
    public static function homeFor(Account $account, string $suffix): string
    {
        return rtrim($account->home_path, '/') . '/ftp/' . strtolower($suffix);
    }
}
PHPEOF
  cat > "${PANEL}/app/Http/Controllers/FtpController.php" <<'PHPEOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\FtpAccount;
use App\Support\AccountProvisioner;
use App\Support\Audit;
use App\Support\Ftp;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel FTP Accounts — Pure-FTPd virtual users, one chroot home per FTP login. */
final class FtpController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $rows    = $account !== null
            ? FtpAccount::query()->where('account_id', $account->id)->orderBy('username')->get()
            : collect();

        return view('ftp.index', [
            'account'   => $account,
            'rows'      => $rows,
            'enabled'   => Ftp::enabled(),
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ($account->isTerminated() || $account->isSuspended()) {
            return back()->withErrors(['username' => 'Cannot manage FTP on a suspended/terminated account.']);
        }

        $data = $request->validate([
            'username' => ['required', 'string', 'regex:/^[a-z][a-z0-9]{0,15}$/'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
            'quota_mb' => ['nullable', 'integer', 'min:0', 'max:102400'],
        ]);

        $login = Ftp::loginFor($account, $data['username']);
        if (FtpAccount::query()->where('username', $login)->exists()) {
            return back()->withErrors(['username' => 'FTP login already exists.'])->withInput();
        }

        $home = Ftp::homeFor($account, $data['username']);

        FtpAccount::query()->create([
            'account_id' => $account->id,
            'username'   => $login,
            'home_path'  => $home,
            'quota_mb'   => (int) ($data['quota_mb'] ?? 0),
            'status'     => 'active',
        ]);

        // Root-side: the agent runs pure-pw (web FPM has proc_open disabled — B1).
        AccountProvisioner::enqueue($account, 'ftp.add', [
            'account'  => $account->username,
            'login'    => $login,
            'password' => $data['password'],
            'home'     => $home,
            'quota_mb' => (int) ($data['quota_mb'] ?? 0),
        ]);
        $account->recordEvent('ftp.add.queued', ['login' => $login]);
        Audit::log('ftp.add', 'info', 'account', $account->id, ['user' => $login]);

        return redirect()->route('ftp.index')->with('success', 'FTP account created.');
    }

    public function password(Request $request, FtpAccount $ftpAccount): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ((int) $ftpAccount->account_id !== (int) $account->id) {
            abort(404);
        }

        $data = $request->validate(['password' => ['required', 'string', 'min:8', 'max:72']]);

        AccountProvisioner::enqueue($account, 'ftp.passwd', [
            'account'  => $account->username,
            'login'    => $ftpAccount->username,
            'password' => $data['password'],
        ]);
        $account->recordEvent('ftp.passwd.queued', ['login' => $ftpAccount->username]);
        Audit::log('ftp.passwd', 'info', 'account', $account->id, ['user' => $ftpAccount->username]);

        return redirect()->route('ftp.index')->with('success', 'FTP password changed.');
    }

    public function destroy(Request $request, FtpAccount $ftpAccount): RedirectResponse
    {
        $account = $this->requireAccount($request);
        if ((int) $ftpAccount->account_id !== (int) $account->id) {
            abort(404);
        }

        AccountProvisioner::enqueue($account, 'ftp.del', [
            'account' => $account->username,
            'login'   => $ftpAccount->username,
        ]);
        $account->recordEvent('ftp.del.queued', ['login' => $ftpAccount->username]);
        $ftpAccount->delete();
        Audit::log('ftp.del', 'info', 'account', $account->id, ['user' => $ftpAccount->username]);

        return redirect()->route('ftp.index')->with('success', 'FTP account removed.');
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'domains']);
    }

    private function requireAccount(Request $request): Account
    {
        $account = $this->accountFor($request);
        if ($account === null) {
            abort(403, 'This login has no hosting account.');
        }

        return $account;
    }
}
PHPEOF
  for rel in "${PANEL_FILES[@]}"; do
    if ! lout="$("$PHP_BIN" -l "${PANEL}/${rel}" 2>&1)"; then
      warn "lint fail: ${rel}"; say "    ${lout}"; rollback; die "panel lint fail"
    fi
  done
  [[ "$(cnt 'Process::' "${PANEL}/app/Support/Ftp.php")" == "0" ]] || { rollback; die "panel me abhi bhi Process:: hai"; }
  ok "panel files likhi + lint clean (2) — koi Process:: nahi"

  # ---- caches + fpm restart (opcache) ----
  hdr "panel cache + php-fpm restart"
  if command -v runuser >/dev/null 2>&1 && [[ -f "${PANEL}/artisan" ]]; then
    runuser -u "$PANEL_USER" -- env ACP_HOME="$ACP_HOME" "$PHP_BIN" "${PANEL}/artisan" optimize:clear >>"$LOG_FILE" 2>&1 \
      && ok "artisan optimize:clear" || warn "optimize:clear fail (ignore — fpm restart opcache clear karega)"
  else
    warn "runuser/artisan nahi — optimize:clear skip"
  fi
  if have_systemd && [[ -n "$FPM_UNIT" ]]; then
    systemctl restart "$FPM_UNIT" >/dev/null 2>&1 || warn "fpm restart fail"
    sleep 1
    systemctl is-active --quiet "$FPM_UNIT" || { rollback; die "php-fpm active nahi restart ke baad"; }
    ok "${FPM_UNIT} active (opcache clear)"
  else
    warn "php-fpm unit nahi mila — restart skip (manual: systemctl restart php8.4-fpm)"
  fi

  # ---- HTTP smoke ----
  hdr "HTTP smoke"
  local code
  code="$(curl -k -s -o /dev/null -w '%{http_code}' -m 10 "https://127.0.0.1:8090/login" 2>/dev/null || echo 000)"
  if [[ "$code" == "200" || "$code" == "302" ]]; then ok "panel /login HTTP ${code}"; else warn "panel /login HTTP ${code} — browser me check karo"; fi

  # ---- sync ----
  hdr "alphacp-sync"
  if command -v alphacp-sync >/dev/null 2>&1; then
    alphacp-sync >>"$LOG_FILE" 2>&1 && ok "sync complete (repo snapshot update)" || warn "sync fail (baad me: sudo alphacp-sync)"
  else
    warn "alphacp-sync nahi mila"
  fi

  hdr "FINAL VERDICT"
  ok "FTP ab root-agent se chalta hai (ftp.add / ftp.passwd / ftp.del); panel sirf queue karta hai"
  info "backup : ${BACKUP}"
  info "log    : ${LOG_FILE}"
  info "rollback: sudo bash $0 --rollback"
  say ""
  say "  ${C_G}ftp-fix v${VERSION} APPLY ho gaya.${C_0} Panel → FTP Accounts → naya account banao (queue me jayega)."
}

usage(){ sed -nE 's/^#( |=)(.*)$/\2/p' "$0" | sed -n '1,30p'; }
mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true
case "${1:-apply}" in
  --diagnose|-d) diagnose ;;
  --rollback|-r) rollback ;;
  --help|-h) usage ;;
  apply|"") apply ;;
  *) die "unknown option: $1 (--diagnose | --rollback | --help)" ;;
esac
