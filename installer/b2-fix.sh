#!/usr/bin/env bash
# =============================================================================
#  AlphaCP — B2 FIX  v1.0  (Metrics via root agent — open_basedir fix)
# -----------------------------------------------------------------------------
#  Live par Metrics page khali/error tha: Support/Metrics::parse() web-FPM se
#  /var/log/apache2/<user>-access.log padhta tha jo open_basedir allow-list me
#  nahi hai (audit B2). cPanel-tareeka: log ROOT AGENT padhta hai (`metrics.access`)
#  aur panel synchronous result dikhata hai. Ye script agent ki 4 + panel ki 2
#  files byte-for-byte deploy karti hai:
#    AGENT : src/Metrics.php, src/Tasks/MetricsAccess.php,
#            src/CommandRunner.php, config/tasks.php (metrics.access = 96 types)
#    PANEL : app/Http/Controllers/MetricsController.php, app/Support/Metrics.php
#
#  Safety (ftp-fix jaisa): backup → har file par `php -l` (fail par error print) →
#  static smoke → pdo_sqlite ho to poora agent suite gate (passed>=218 failed=0) →
#  paneld restart → panel files → Process:: absence assert → optimize:clear +
#  php-fpm restart (opcache) → HTTP smoke → alphacp-sync. Kahin fail = auto-rollback.
#
#  Usage: sudo bash b2-fix-v1.0.sh | --diagnose | --rollback | --help
# =============================================================================
set -Eeuo pipefail

VERSION="1.0"
ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"
AGENT="${ACP_HOME}/agent"
PANEL="${ACP_HOME}/panel"
STAMP="$(date -u +%Y%m%d%H%M%S)"
BACKUP="${ACP_HOME}/releases/b2fix-${STAMP}"
LOG_FILE="${ACP_HOME}/logs/b2-fix-${STAMP}.txt"

C_R=$'\033[0;31m'; C_G=$'\033[0;32m'; C_Y=$'\033[0;33m'; C_B=$'\033[0;36m'; C_D=$'\033[0;2m'; C_0=$'\033[0m'
say(){ printf '%s\n' "$*" | tee -a "${LOG_FILE:-/dev/null}"; }
hdr(){ say ""; say "${C_B}== $* ==${C_0}"; }
ok(){ say "  ${C_G}✔${C_0} $*"; }
warn(){ say "  ${C_Y}⚠${C_0} $*"; }
info(){ say "  ${C_D}·${C_0} $*"; }
die(){ say "  ${C_R}✖ $*${C_0}"; exit 1; }
have_systemd(){ [[ -d /run/systemd/system ]] && command -v systemctl >/dev/null 2>&1; }
cnt(){ grep -c "$@" 2>/dev/null || true; }
procfiles(){ grep -l 'Process::run\|Process::timeout' "$@" 2>/dev/null | wc -l; }

# php-wasm (CI/sim) `PHP` env ko VERSION maanta hai — isliye binary PHP_BIN me,
# aur inherited PHP unset (warna child php calls chup-chaap fail hoti hain).
unset PHP 2>/dev/null || true

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

AGENT_FILES=(src/Metrics.php src/Tasks/MetricsAccess.php src/CommandRunner.php config/tasks.php)
PANEL_FILES=(app/Http/Controllers/MetricsController.php app/Support/Metrics.php)

rollback(){
  hdr "ROLLBACK — b2-fix v${VERSION}"
  local latest rel
  latest="$(ls -1dt "${ACP_HOME}"/releases/b2fix-* 2>/dev/null | head -1 || true)"
  [[ -n "$latest" ]] || die "koi b2fix backup nahi mila"
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
  hdr "DIAGNOSE (read-only) — b2-fix v${VERSION}"
  info "ACP_HOME=${ACP_HOME} php=${PHP_BIN:-none} panel_user=${PANEL_USER} fpm=${FPM_UNIT:-none}"
  info "agent src/Metrics.php      : $( [[ -f "${AGENT}/src/Metrics.php" ]] && echo PRESENT || echo MISSING )"
  info "agent registry metrics     : $(cnt "'metrics\.access'" "${AGENT}/config/tasks.php")  (1=registered)"
  info "panel controller Paneld    : $(cnt 'Paneld::run' "${PANEL}/app/Http/Controllers/MetricsController.php")  (>=1=theek)"
  info "panel Support parse()      : $(cnt 'function parse' "${PANEL}/app/Support/Metrics.php")  (0=theek, >0=open_basedir bug)"
  say ""; ok "diagnose complete (kuch badla nahi)"
}

apply(){
  hdr "APPLY — b2-fix v${VERSION} (Metrics via root agent)"
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
  cat > "${AGENT}/src/Metrics.php" <<'PHPEOF'
<?php

declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * cPanel Metrics — Apache access-log parser (root agent side, audit B2).
 *
 * Panel web-FPM `open_basedir` me `/var/log` nahi hai, isliye Metrics page live
 * par khali/error tha. Ab log agent padhta hai aur stats return karta hai:
 *   bytes    = total response bytes ('-' = 0)
 *   visitors = unique client IPs
 *   requests = total log lines (valid)
 *   errors   = status >= 400
 *   top      = path => hits (top 10)
 *
 * Badi logs ke liye: 8 MB se badi file sirf AAKHRI 8 MB parse hoti hai (tail),
 * warna memory blow ho jati. Har line stream hoti hai (fgets), poora file
 * memory me kabhi nahi aata.
 */
final class Metrics
{
    private const TAIL_WINDOW = 8 * 1024 * 1024;
    private const LOG_RE = '/^(\S+)\s+\S+\s+\S+\s+\[[^\]]+\]\s+"[A-Z]+\s+(\S+)[^"]*"\s+(\d{3})\s+(\d+|-)/';

    /**
     * Parse an access-log file (streamed).
     *
     * @return array{bytes:int,visitors:int,requests:int,errors:int,top:array<string,int>,tail:bool}
     */
    public static function parseFile(string $path): array
    {
        $size = (int) @filesize($path);
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return ['bytes' => 0, 'visitors' => 0, 'requests' => 0, 'errors' => 0, 'top' => [], 'tail' => false];
        }

        $tail = false;
        if ($size > self::TAIL_WINDOW) {
            fseek($fh, $size - self::TAIL_WINDOW);
            fgets($fh); // adhoori line skip
            $tail = true;
        }

        $bytes = 0;
        $requests = 0;
        $errors = 0;
        $ips = [];
        $top = [];
        $lines = 0;

        while (($line = fgets($fh)) !== false) {
            $lines++;
            if ($lines > 2_000_000) {
                break; // safety cap
            }
            if (preg_match(self::LOG_RE, $line, $m) !== 1) {
                continue;
            }
            $requests++;
            $ips[$m[1]] = true;
            $status = (int) $m[3];
            if ($status >= 400) {
                $errors++;
            }
            $bytes += $m[4] === '-' ? 0 : (int) $m[4];
            $pathKey = $m[2];
            $top[$pathKey] = ($top[$pathKey] ?? 0) + 1;
        }
        fclose($fh);

        arsort($top);

        return [
            'bytes'    => $bytes,
            'visitors' => count($ips),
            'requests' => $requests,
            'errors'   => $errors,
            'top'      => array_slice($top, 0, 10, true),
            'tail'     => $tail,
        ];
    }
}
PHPEOF
  cat > "${AGENT}/src/Tasks/MetricsAccess.php" <<'PHPEOF'
<?php

declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\Metrics;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;

/**
 * metrics.access — account ke Apache access-log se cPanel-jaise stats
 * (Bandwidth / Visitors / Requests / Errors / top pages), root agent side (B2).
 *
 * Log path default: `/var/log/apache2/<account>-access.log` (panel config wala
 * pattern), ya payload `log_path` (tests / custom layout) — dono soorton me
 * PathGuard roots ke andar hona zaroori hai. Log maujood na ho to zero-stats
 * (error nahi — naya account ho sakta hai).
 *
 * @acp-task metrics.access
 */
final class MetricsAccess implements TaskInterface
{
    /** @var list<string> jahan vhost access-log typically hota hai */
    private const CANDIDATES = [
        '/var/log/apache2/{user}-access.log',
        '/var/log/apache2/{user}-access.log.1',
        '/var/log/httpd/{user}-access.log',
        '/var/log/apache2/access.log',
    ];

    public function handle(array $payload, TaskContext $ctx): array
    {
        if ($ctx->paths === null) {
            throw new TaskRejectedException('metrics.access requires PathGuard roots');
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

        $fs = new SafeFs($ctx->paths);
        $log = $this->logPath($payload, $ctx, $username, $fs);

        if ($log === null) {
            return [
                'username' => $username,
                'log'      => null,
                'stats'    => ['bytes' => 0, 'visitors' => 0, 'requests' => 0, 'errors' => 0, 'top' => [], 'tail' => false],
                'status'   => 'ok',
            ];
        }

        return [
            'username' => $username,
            'log'      => $log,
            'stats'    => Metrics::parseFile($log),
            'status'   => 'ok',
        ];
    }

    /** Payload log_path (validated) ya pehla maujood default candidate. */
    private function logPath(array $payload, TaskContext $ctx, string $username, SafeFs $fs): ?string
    {
        $custom = trim((string) ($payload['log_path'] ?? ''));
        if ($custom !== '') {
            return $fs->assert($custom);
        }
        foreach (self::CANDIDATES as $pattern) {
            $path = str_replace('{user}', $username, $pattern);
            // /var/log roots me na ho to PathGuard allow nahi karega — chup-chaap skip
            try {
                $safe = $fs->assert($path);
            } catch (\Throwable) {
                continue;
            }
            if ($fs->isFile($safe)) {
                return $safe;
            }
        }

        return null;
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
        // B1-baaki: cPanel Git Version Control + Site Software + WHM Terminal
        // (sab argv-only; terminal sirf read-only whitelist chalata hai).
        '/usr/bin/git',
        '/usr/local/bin/git',
        '/usr/bin/curl',
        '/usr/bin/chown',
        '/bin/chown',
        '/bin/ls',
        '/usr/bin/ls',
        '/bin/pwd',
        '/usr/bin/pwd',
        '/usr/bin/whoami',
        '/bin/whoami',
        '/bin/date',
        '/usr/bin/date',
        '/bin/uname',
        '/usr/bin/uname',
        '/bin/free',
        '/bin/cat',
        '/usr/bin/cat',
        '/usr/bin/php8.4',
        '/usr/bin/php',
        '/usr/bin/node',
        '/usr/local/bin/node',
        // B1-ext: cPanel Security suite (IP Blocker / ModSecurity / ClamAV).
        '/usr/sbin/ufw',
        '/sbin/ufw',
        '/usr/sbin/a2enmod',
        '/usr/bin/a2enmod',
        '/usr/sbin/a2dismod',
        '/usr/bin/a2dismod',
        '/usr/sbin/a2query',
        '/usr/bin/a2query',
        '/usr/bin/clamscan',
        '/usr/sbin/clamscan',
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

    // ------------------------------------------------------------------
    //  B1-baaki: cPanel Git Version Control + WHM Terminal + Site Software.
    //  Web-FPM proc_open disabled hai, isliye ye sab root agent karta hai.
    // ------------------------------------------------------------------
    'git.list' => [
        'handler'     => Tasks\GitList::class,
        'safety'      => 'readonly',
        'timeout'     => 30,
        'description' => 'List git repos under <home>/git (dirs containing .git).',
        'paths'       => ['/home'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['account'],
            'properties'           => [
                'account' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
            ],
        ],
    ],

    'git.clone' => [
        'handler'     => Tasks\GitClone::class,
        'safety'      => 'mutating',
        'timeout'     => 150,
        'description' => 'Clone a repository into <home>/git/<dir> (git clone -- , argv-only).',
        'paths'       => ['/home'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['account', 'url', 'dir'],
            'properties'           => [
                'account' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'url'     => ['type' => 'string', 'minLength' => 8, 'maxLength' => 300],
                'dir'     => ['type' => 'string', 'pattern' => '^[a-z0-9._-]{1,64}$', 'maxLength' => 64],
            ],
        ],
    ],

    'git.pull' => [
        'handler'     => Tasks\GitPull::class,
        'safety'      => 'mutating',
        'timeout'     => 150,
        'description' => 'git pull --ff-only in <home>/git/<dir>.',
        'paths'       => ['/home'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['account', 'dir'],
            'properties'           => [
                'account' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'dir'     => ['type' => 'string', 'pattern' => '^[a-z0-9._-]{1,64}$', 'maxLength' => 64],
            ],
        ],
    ],

    'git.status' => [
        'handler'     => Tasks\GitStatus::class,
        'safety'      => 'readonly',
        'timeout'     => 40,
        'description' => 'git status --porcelain for <home>/git/<dir>.',
        'paths'       => ['/home'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['account', 'dir'],
            'properties'           => [
                'account' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'dir'     => ['type' => 'string', 'pattern' => '^[a-z0-9._-]{1,64}$', 'maxLength' => 64],
            ],
        ],
    ],

    'terminal.run' => [
        'handler'     => Tasks\TerminalRun::class,
        'safety'      => 'readonly',
        'timeout'     => 40,
        'description' => 'WHM-style Terminal: read-only whitelist (ls/pwd/df/…/git status/cat), agent par dobara validate.',
        'paths'       => ['/home', '/etc', '/usr/local/alphacp', '/var/log'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['command'],
            'properties'           => [
                'command' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
            ],
        ],
    ],

    'apps.install' => [
        'handler'     => Tasks\AppsInstall::class,
        'safety'      => 'mutating',
        'timeout'     => 320,
        'description' => 'One-click WordPress: public_html + <account>_wp DB/user/grant + tarball extract + wp-config + chown.',
        'paths'       => ['/home'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'app', 'db_password'],
            'properties'           => [
                'username'    => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'app'         => ['type' => 'string', 'enum' => ['wordpress']],
                'db_password' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 72],
            ],
        ],
    ],

    // ------------------------------------------------------------------
    //  B1-ext: cPanel Security suite (IP Blocker / ModSecurity / ClamAV).
    //  Web-FPM se ufw/a2enmod/clamscan proc_open disabled hone se 500 dete the.
    // ------------------------------------------------------------------
    'security.ipBlock' => [
        'handler'     => Tasks\IpBlock::class,
        'safety'      => 'mutating',
        'timeout'     => 40,
        'description' => 'ufw deny from <ip> (cPanel IP Blocker).',
        'paths'       => ['/home'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['ip'],
            'properties'           => ['ip' => ['type' => 'string', 'minLength' => 7, 'maxLength' => 45]],
        ],
    ],

    'security.ipUnblock' => [
        'handler'     => Tasks\IpUnblock::class,
        'safety'      => 'mutating',
        'timeout'     => 40,
        'description' => 'ufw delete deny from <ip>.',
        'paths'       => ['/home'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['ip'],
            'properties'           => ['ip' => ['type' => 'string', 'minLength' => 7, 'maxLength' => 45]],
        ],
    ],

    'waf.status' => [
        'handler'     => Tasks\WafStatus::class,
        'safety'      => 'readonly',
        'timeout'     => 20,
        'description' => 'ModSecurity (security2) enabled? (a2query -m security2).',
        'paths'       => ['/home'],
        'schema'      => ['type' => 'object', 'additionalProperties' => false, 'properties' => []],
    ],

    'waf.enable' => [
        'handler'     => Tasks\WafEnable::class,
        'safety'      => 'mutating',
        'timeout'     => 100,
        'description' => 'a2enmod security2 + apache2 restart.',
        'paths'       => ['/home'],
        'schema'      => ['type' => 'object', 'additionalProperties' => false, 'properties' => []],
    ],

    'waf.disable' => [
        'handler'     => Tasks\WafDisable::class,
        'safety'      => 'mutating',
        'timeout'     => 100,
        'description' => 'a2dismod security2 + apache2 restart.',
        'paths'       => ['/home'],
        'schema'      => ['type' => 'object', 'additionalProperties' => false, 'properties' => []],
    ],

    'security.scan' => [
        'handler'     => Tasks\VirusScan::class,
        'safety'      => 'readonly',
        'timeout'     => 130,
        'description' => 'clamscan -r --quiet <path> (PathGuard ke andar); exit 1 = infected result.',
        'paths'       => ['/home'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['path'],
            'properties'           => ['path' => ['type' => 'string', 'maxLength' => 255]],
        ],
    ],

    'metrics.access' => [
        'handler'     => Tasks\MetricsAccess::class,
        'safety'      => 'readonly',
        'timeout'     => 60,
        'description' => 'Apache access-log se cPanel-style stats (bytes/visitors/requests/errors/top).',
        'paths'       => ['/var/log', '/home'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['account'],
            'properties'           => [
                'account'  => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'log_path' => ['type' => 'string', 'maxLength' => 255],
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
      warn "lint fail: ${rel}"; say "    ${lout}"; rollback; die "agent lint fail"
    fi
  done
  ok "agent files likhi + lint clean (4)"

  # ---- agent self-test ----
  hdr "SELF-TEST agent (static smoke + suite)"
  local smoke; smoke="$(mktemp)"
  cat > "$smoke" <<'SMOKE'
<?php
require $argv[1] . '/src/Bootstrap.php';
use Alphacp\Agent\Metrics;
use Alphacp\Agent\Tasks\MetricsAccess;
$f = 0;
function chk(bool $c, string $m): void { global $f; if (!$c) { fwrite(STDERR, "SMOKE FAIL: $m\n"); $f++; } }
chk(class_exists(Metrics::class) && class_exists(MetricsAccess::class), 'metrics classes autoload');
$tmp = tempnam(sys_get_temp_dir(), 'acpmx');
file_put_contents($tmp, "1.1.1.1 - - [07/Oct/2026:10:00:01 +0000] \"GET / HTTP/1.1\" 200 100 \"-\" \"ua\"\n");
$st = Metrics::parseFile($tmp);
chk($st['requests'] === 1 && $st['bytes'] === 100, 'parser kaam karta hai');
unlink($tmp);
$types = array_keys(require $argv[1] . '/config/tasks.php');
chk(in_array('metrics.access', $types, true), 'metrics.access registered');
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
    if [[ "${n:-9}" != "0" || "${p:-0}" -lt 221 ]]; then rollback; die "agent suite green nahi (passed=${p:-?} failed=${n:-?})"; fi
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
    ok "paneld active (96-type registry load hua)"
  else
    warn "paneld unit / systemd nahi mila — restart skip (manual: systemctl restart paneld)"
  fi

  # ---- panel files ----
  hdr "panel files likhna"
  cat > "${PANEL}/app/Http/Controllers/MetricsController.php" <<'PHPEOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\Metrics;
use App\Support\ModuleCatalog;
use App\Support\Paneld;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * cPanel Metrics — Visitors / Errors / Bandwidth / top pages.
 *
 * B2: pehle panel ka log-parser web-FPM se `/var/log/apache2/…` padhta
 * tha jo open_basedir me nahi hai → page live par khali/error. Ab log ROOT AGENT
 * padhta hai (`metrics.access`) aur panel synchronous result dikhata hai.
 */
final class MetricsController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $stats   = null;

        if ($account !== null && in_array('metrics.access', Paneld::taskTypes(), true)) {
            $res = Paneld::run('metrics.access', ['account' => $account->username], 20);
            if (is_array($res['stats'] ?? null)) {
                $stats = $res['stats'];
            }
        }

        return view('metrics.index', [
            'account'   => $account,
            'stats'     => $stats,
            'human'     => $stats !== null ? Metrics::human((int) ($stats['bytes'] ?? 0)) : '0 B',
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount?->load(['package', 'domains']);
    }
}
PHPEOF
  cat > "${PANEL}/app/Support/Metrics.php" <<'PHPEOF'
<?php

declare(strict_types=1);

namespace App\Support;

/**
 * cPanel-style Metrics helpers.
 *
 * B2: asli log-parsing ab ROOT AGENT karta hai (`metrics.access` task) kyunki
 * web-FPM ka open_basedir `/var/log` allow nahi karta — panel ke paas sirf
 * display helpers hain (koi file I/O nahi).
 */
final class Metrics
{
    /** Bytes ko human-readable unit me (1.5 MB etc.). */
    public static function human(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1) . ' ' . $unit;
            }
            $bytes /= 1024;
        }

        return round($bytes, 1) . ' PB';
    }
}
PHPEOF
  for rel in "${PANEL_FILES[@]}"; do
    if ! lout="$("$PHP_BIN" -l "${PANEL}/${rel}" 2>&1)"; then
      warn "lint fail: ${rel}"; say "    ${lout}"; rollback; die "panel lint fail"
    fi
  done
  [[ "$(cnt 'function parse' "${PANEL}/app/Support/Metrics.php")" == "0" ]] \
    || { rollback; die "Support/Metrics me abhi bhi parse() hai (open_basedir bug)"; }
  [[ "$(cnt 'Paneld::run' "${PANEL}/app/Http/Controllers/MetricsController.php")" -ge 1 ]] \
    || { rollback; die "MetricsController Paneld::run nahi karta"; }
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
  ok "Metrics ab root-agent se (metrics.access); panel sirf dikhawa — open_basedir se free"
  info "backup : ${BACKUP}"
  info "log    : ${LOG_FILE}"
  info "rollback: sudo bash $0 --rollback"
  say ""
  say "  ${C_G}b2-fix v${VERSION} APPLY ho gaya.${C_0} Panel → Metrics page kholo (Bandwidth/Visitors/Requests/Errors)."
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
