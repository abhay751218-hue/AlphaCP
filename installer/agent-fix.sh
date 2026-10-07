#!/usr/bin/env bash
# =============================================================================
#  AlphaCP — AGENT FIX  v1.0   (B6: agent se `MysqlServer.php` gayab tha)
# -----------------------------------------------------------------------------
#  Live server par /usr/local/alphacp/agent/src/MysqlServer.php MAUJOOD NAHI tha,
#  par DbTask + saare Db* handlers (db.create / db.drop / db.user.create|grant|
#  password|drop / db.list), db.restore, CpanelMysql aur BackupArchiveStore isi
#  class ko `use` karte hain. Natija: panel ke MySQL Databases/Users UI aur
#  cPanel-import ka mysql path — agent step par
#     Class "Alphacp\Agent\MysqlServer" not found
#  se FATAL. (Agent ke apne suite me isi se 8 failures the: 204/8.)
#
#  Ye script wahi class (sandbox me reconstruct + 212/0 verify) live agent par
#  rakhti hai. Sirf EK file badalti hai; baaki agent untouched.
#
#  Safety (login-fix jaisa hi pattern):
#    • pehle backup (releases/agentfix-<ts>/) — --rollback se wapas
#    • likhne ke BAAD php -l (lint) — parse error par apne aap rollback
#    • HARD GATE: Bootstrap load + class_exists + pure static helpers ka smoke
#      (quoteIdentifier / literal / account / accountName / password fail-closed)
#    • agar server php par pdo_sqlite hai to poora agent suite bhi gate:
#      `failed: 0` zaroori, warna rollback
#    • paneld restart + is-active check (systemd na ho to skip, warn)
#    • end me alphacp-sync (snapshot me fix capture) — best effort
#    • har step idempotent: dobara chalane par kuch nahi tootta
#
#  Usage:
#    sudo bash agent-fix-v1.0.sh              # apply (default)
#    sudo bash agent-fix-v1.0.sh --diagnose   # sirf dekho, kuch badle nahi
#    sudo bash agent-fix-v1.0.sh --rollback   # pichhle backup par wapas
#    sudo bash agent-fix-v1.0.sh --help
# =============================================================================
set -Eeuo pipefail

VERSION="1.0"
ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"
AGENT="${ACP_HOME}/agent"
TARGET="${AGENT}/src/MysqlServer.php"
UNIT="paneld"
STAMP="$(date -u +%Y%m%d%H%M%S)"
BACKUP="${ACP_HOME}/releases/agentfix-${STAMP}"
LOG_FILE="${ACP_HOME}/logs/agent-fix-${STAMP}.txt"

C_R=$'\033[0;31m'; C_G=$'\033[0;32m'; C_Y=$'\033[0;33m'; C_B=$'\033[0;36m'; C_D=$'\033[0;2m'; C_0=$'\033[0m'
say(){ printf '%s\n' "$*" | tee -a "${LOG_FILE:-/dev/null}"; }
hdr(){ say ""; say "${C_B}== $* ==${C_0}"; }
ok(){ say "  ${C_G}✔${C_0} $*"; }
warn(){ say "  ${C_Y}⚠${C_0} $*"; }
info(){ say "  ${C_D}·${C_0} $*"; }
die(){ say "  ${C_R}✖ $*${C_0}"; exit 1; }

# ---- php binary: unit ke ExecStart se, warna php8.4, warna php --------------
detect_php(){
  local p
  if command -v systemctl >/dev/null 2>&1 && [[ -f /etc/systemd/system/${UNIT}.service ]]; then
    p="$(sed -nE 's#^ExecStart=([^ ]+) .*#\1#p' "/etc/systemd/system/${UNIT}.service" 2>/dev/null | head -1)"
    [[ -n "$p" && -x "$p" ]] && { printf '%s' "$p"; return; }
  fi
  for c in php8.4 php8.3 php8.2 php; do command -v "$c" >/dev/null 2>&1 && { command -v "$c"; return; }; done
  printf ''
}
PHP="$(detect_php)"

have_systemd(){ [[ -d /run/systemd/system ]] && command -v systemctl >/dev/null 2>&1; }

# ---- static-helper smoke (NO pdo needed) — the always-on hard gate ----------
write_smoke(){ # $1 = out file
  cat > "$1" <<'SMOKE'
<?php
declare(strict_types=1);
$agent = $argv[1] ?? '';
require $agent . '/src/Bootstrap.php';
use Alphacp\Agent\MysqlServer;
use Alphacp\Agent\TaskRejectedException;
$fail = 0;
function chk(bool $c, string $m): void { global $fail; if (!$c) { fwrite(STDERR, "SMOKE FAIL: $m\n"); $fail++; } }
chk(class_exists(MysqlServer::class), 'MysqlServer class autoloads');
chk(MysqlServer::quoteIdentifier('alicehost_shop') === '`alicehost_shop`', 'quoteIdentifier backticks');
chk(MysqlServer::literal("it's", 'test') === "'it''s'", 'literal doubles a quote');
chk(MysqlServer::account('u', 'localhost') === "'u'@'localhost'", 'account clause');
chk(MysqlServer::accountName('alicehost', 'shop', 'database name') === 'alicehost_shop', 'accountName prefix');
chk(MysqlServer::password('S3cret-Pass-word') === 'S3cret-Pass-word', 'password accepts a valid one');
foreach (['short', str_repeat('x', 65), "Has'Quote-1234", "Line\nBreak-1234"] as $bad) {
    $threw = false;
    try { MysqlServer::password($bad); } catch (TaskRejectedException $e) { $threw = true; }
    chk($threw, 'password refuses: ' . substr(str_replace("\n", '\\n', $bad), 0, 12));
}
$threw = false;
try { MysqlServer::quoteIdentifier('bad`name'); } catch (TaskRejectedException $e) { $threw = true; }
chk($threw, 'quoteIdentifier refuses a backtick');
$threw = false;
try { MysqlServer::accountName('alicehost', '../etc', 'database name'); } catch (TaskRejectedException $e) { $threw = true; }
chk($threw, 'accountName refuses traversal');
echo $fail === 0 ? "SMOKE OK\n" : "SMOKE FAILED ($fail)\n";
exit($fail === 0 ? 0 : 1);
SMOKE
}

run_smoke(){ # -> 0 pass / 1 fail
  local tmp; tmp="$(mktemp)"
  write_smoke "$tmp"
  local out rc=0
  out="$("$PHP" "$tmp" "$AGENT" 2>&1)" || rc=$?
  rm -f "$tmp"
  say "$out" | sed 's/^/    /'
  return $rc
}

suite_summary(){ # prints "P F" if the suite ran, else empty
  local out
  out="$("$PHP" "${AGENT}/tests/run-tests.php" 2>&1 || true)"
  printf '%s\n' "$out" | grep -E 'passed: [0-9]+ +failed: [0-9]+' | tail -1 \
    | sed -E 's/.*passed: ([0-9]+) +failed: ([0-9]+).*/\1 \2/'
}

has_pdo_sqlite(){ "$PHP" -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' >/dev/null 2>&1; }

diagnose(){
  hdr "DIAGNOSE (read-only) — AlphaCP agent-fix v${VERSION}"
  info "ACP_HOME   : ${ACP_HOME}"
  info "agent dir  : ${AGENT} $( [[ -d "$AGENT" ]] && echo '(present)' || echo '(MISSING)')"
  [[ -x "${AGENT}/bin/paneld" ]] && info "paneld bin : present" || warn "paneld bin missing"
  if [[ -f "${AGENT}/src/Bootstrap.php" ]]; then
    info "agent ver  : $(grep -oE "ACP_AGENT_VERSION', '[^']+'" "${AGENT}/src/Bootstrap.php" | head -1 | sed "s/.*, '//;s/'//")"
  fi
  info "php binary : ${PHP:-<none found>}"
  if [[ -f "$TARGET" ]]; then
    ok "MysqlServer.php PRESENT ($(wc -c <"$TARGET") bytes)"
    if [[ -n "$PHP" ]]; then
      info "static smoke:"; run_smoke && ok "smoke pass" || warn "smoke FAIL"
    fi
  else
    warn "MysqlServer.php MISSING — real db.* tasks + db.restore agent-step par FATAL honge"
  fi
  if [[ -f "${AGENT}/config/tasks.php" ]]; then
    info "db.create registered : $(grep -c "'db.create'" "${AGENT}/config/tasks.php") (1 = yes)"
    info "db.restore registered: $(grep -c "'db.restore'" "${AGENT}/config/tasks.php") (1 = yes)"
  fi
  if have_systemd; then
    info "paneld service : $(systemctl is-active "${UNIT}" 2>/dev/null || echo '?')"
  else
    warn "systemd nahi — service state check skip"
  fi
  [[ -f "${ACP_HOME}/logs/paneld.log" ]] && { info "paneld.log tail:"; tail -3 "${ACP_HOME}/logs/paneld.log" | sed 's/^/    /'; }
  say ""
  ok "diagnose complete (kuch badla nahi)"
}

rollback(){
  hdr "ROLLBACK — AlphaCP agent-fix v${VERSION}"
  local latest
  latest="$(ls -1dt "${ACP_HOME}"/releases/agentfix-* 2>/dev/null | head -1 || true)"
  [[ -n "$latest" ]] || die "koi agentfix backup nahi mila (${ACP_HOME}/releases/agentfix-*)"
  info "backup: $latest"
  if [[ -f "${latest}/MysqlServer.php" ]]; then
    cp -p "${latest}/MysqlServer.php" "$TARGET"
    ok "purani MysqlServer.php wapas rakhi"
  else
    # fix se PEHLE file thi hi nahi → hata do (original state)
    rm -f "$TARGET"
    ok "MysqlServer.php hata di (fix se pehle maujood nahi thi)"
  fi
  if have_systemd; then
    systemctl restart "${UNIT}" >/dev/null 2>&1 || warn "paneld restart fail"
    sleep 1
    systemctl is-active --quiet "${UNIT}" && ok "paneld active" || warn "paneld active nahi"
  fi
  ok "rollback complete"
}

apply(){
  hdr "APPLY — AlphaCP agent-fix v${VERSION}  (B6: MysqlServer.php restore)"
  mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true
  say "  log: ${LOG_FILE}"

  # ---- preflight -----------------------------------------------------------
  [[ -d "$AGENT" ]]        || die "agent dir nahi: ${AGENT} (AlphaCP agent install hai? step2-install.sh)"
  [[ -f "${AGENT}/src/Bootstrap.php" ]] || die "agent src adhoora: Bootstrap.php missing"
  [[ -f "${AGENT}/config/tasks.php" ]]  || die "agent config adhoora: tasks.php missing"
  grep -q "'db.create'" "${AGENT}/config/tasks.php" || die "db.create task registered nahi — ye agent AlphaCP ka nahi lagta"
  [[ -n "$PHP" && -x "$PHP" ]] || die "php binary nahi mila (php8.4/php) — agent kaise chal raha hai?"
  ok "preflight: agent + php (${PHP}) theek"

  # ---- backup --------------------------------------------------------------
  mkdir -p "$BACKUP"
  local pre_existed=0
  if [[ -f "$TARGET" ]]; then
    cp -p "$TARGET" "${BACKUP}/MysqlServer.php"
    pre_existed=1
    info "existing MysqlServer.php backed up ($(wc -c <"$TARGET") bytes)"
  else
    info "MysqlServer.php pehle maujood nahi thi (backup me 'absent' record)"
  fi
  printf '%s\n' "$pre_existed" > "${BACKUP}/pre_existed"
  ok "backup: ${BACKUP}"

  # ---- write the class -----------------------------------------------------
  cat > "$TARGET" <<'PHPEOF'
<?php
declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * The ONLY place the agent talks to MariaDB/MySQL.
 *
 * Design rules (mirrors docs/03-security-matrix.md, S8 "real db.* tasks"):
 *  - SQL always travels on **stdin** (or a streamed stdinFile for dumps) — never
 *    in argv, so a password or an identifier can never leak into `ps`/logs.
 *  - every identifier is validated + backtick-quoted, every literal is
 *    single-quoted with embedded quotes doubled — no string is ever concatenated
 *    raw into a statement.
 *  - the client binary comes from ACP_MYSQL_CLIENT (default /usr/bin/mariadb) and
 *    must be an absolute path inside CommandRunner's allowlist.
 *  - a failing client surfaces as a clean TaskRejectedException that carries NO
 *    SQL fragment (the detail goes to the task log instead).
 *
 * The class was referenced by DbTask, the Db handlers, CpanelMysql and
 * BackupArchiveStore, but the file never reached the server — so every real
 * db task (create, drop, user management, list, restore) died with
 * "Class MysqlServer not found". This restores it.
 */
final class MysqlServer
{
    /** Password length window enforced before a password ever reaches SQL. */
    private const PASSWORD_MIN = 8;
    private const PASSWORD_MAX = 64;

    /** Default timeout (seconds) for one DDL/DML round-trip. */
    private const SQL_TIMEOUT = 120;

    public function __construct(
        private readonly CommandExecutor $cmd,
        private readonly TaskLogger $log,
    ) {
    }

    // ------------------------------------------------------------------
    //  Static helpers (pure, no I/O) — used by the Db* handlers directly.
    // ------------------------------------------------------------------

    /**
     * Build the account-prefixed object name `<user>_<suffix>` (cPanel style).
     *
     * @param  string $username hosting account (already validated by AccountIdentity)
     * @param  string $suffix   customer-chosen suffix (already validated by DbTask::suffix)
     * @param  string $label    human name used in the rejection message
     */
    public static function accountName(string $username, string $suffix, string $label): string
    {
        $name = strtolower(trim($username)) . '_' . strtolower(trim($suffix));
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $name) !== 1) {
            throw new TaskRejectedException("invalid {$label}: only letters/numbers/_ , 2-64 chars, letter first");
        }

        return $name;
    }

    /** Backtick-quote a validated SQL identifier (backticks inside are doubled). */
    public static function quoteIdentifier(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $name) !== 1) {
            throw new TaskRejectedException('invalid SQL identifier');
        }

        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * Single-quote a SQL literal, doubling any embedded single quote.
     *
     * NUL / CR / LF are refused outright — a literal must stay on one line so it
     * can never smuggle a second statement past a line-based scanner.
     *
     * @param  string $label human name used in the rejection message
     */
    public static function literal(string $value, string $label = ''): string
    {
        $what = $label !== '' ? $label : 'value';
        if (str_contains($value, "\0") || str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new TaskRejectedException("invalid {$what}: control characters are not allowed");
        }

        return "'" . str_replace("'", "''", $value) . "'";
    }

    /** `'user'@'host'` account clause (both sides quoted as literals). */
    public static function account(string $user, string $host): string
    {
        return self::literal($user, 'user') . '@' . self::literal($host, 'host');
    }

    /**
     * Validate a customer database password and return it unchanged.
     *
     * Refused: wrong length, single quote, backslash, any control character.
     * The panel never generates such a password, so refusing is fail-closed and
     * keeps the value safe to embed through literal().
     */
    public static function password(string $password): string
    {
        $len = strlen($password);
        if ($len < self::PASSWORD_MIN || $len > self::PASSWORD_MAX) {
            throw new TaskRejectedException(
                'database password must be ' . self::PASSWORD_MIN . '-' . self::PASSWORD_MAX . ' characters'
            );
        }
        if (str_contains($password, "'") || str_contains($password, '\\')) {
            throw new TaskRejectedException('database password may not contain a quote or a backslash');
        }
        for ($i = 0; $i < $len; $i++) {
            $ord = ord($password[$i]);
            if ($ord < 32 || $ord === 127) {
                throw new TaskRejectedException('database password may not contain control characters');
            }
        }

        return $password;
    }

    // ------------------------------------------------------------------
    //  Instance methods — the real conversation with the client.
    // ------------------------------------------------------------------

    /** Run a SQL script on stdin; throw a clean rejection if the client fails. */
    public function sql(string $sql): void
    {
        $this->run($sql);
    }

    /** Does database `<db>` already exist on this server? */
    public function databaseExists(string $database): bool
    {
        $out = $this->run(
            'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '
            . self::literal($database, 'database') . ';'
        );

        return trim($out) !== '';
    }

    /** Does MariaDB user `<user>@<host>` already exist? */
    public function userExists(string $user, string $host = 'localhost'): bool
    {
        $out = $this->run(
            'SELECT User FROM mysql.user WHERE User = ' . self::literal($user, 'user')
            . ' AND Host = ' . self::literal($host, 'host') . ';'
        );

        return trim($out) !== '';
    }

    /**
     * Every database owned by the account (`<username>_*`), sorted.
     *
     * @return list<string>
     */
    public function accountDatabases(string $username): array
    {
        $out = $this->run('SHOW DATABASES;');
        $prefix = strtolower($username) . '_';
        $found = [];
        foreach (preg_split('/\R/', $out) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && str_starts_with(strtolower($line), $prefix)) {
                $found[] = $line;
            }
        }
        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    }

    /**
     * Every MariaDB user row owned by the account, with the databases each can
     * reach. Shape: [{user, host, databases: [..]}, ...].
     *
     * @return list<array{user: string, host: string, databases: list<string>}>
     */
    public function accountUsers(string $username): array
    {
        $out = $this->run(
            'SELECT User, Host FROM mysql.user WHERE User LIKE '
            . self::literal(strtolower($username) . '_%', 'user') . ';'
        );
        $prefix = strtolower($username) . '_';
        $users = [];
        foreach (preg_split('/\R/', $out) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\t/', $line) ?: [];
            $user = strtolower(trim((string) ($parts[0] ?? '')));
            $host = trim((string) ($parts[1] ?? 'localhost'));
            if ($user === '' || !str_starts_with($user, $prefix)) {
                continue;
            }
            $users[] = [
                'user'      => $user,
                'host'      => $host !== '' ? $host : 'localhost',
                'databases' => $this->grantedDatabases($user, $host !== '' ? $host : 'localhost'),
            ];
        }

        return $users;
    }

    /**
     * Databases on which `<user>@<host>` holds ALL PRIVILEGES (parsed from
     * SHOW GRANTS; the USAGE line and any non-account grant are ignored).
     *
     * @return list<string>
     */
    public function grantedDatabases(string $user, string $host = 'localhost'): array
    {
        $out = $this->run('SHOW GRANTS FOR ' . self::account($user, $host) . ';');
        $found = [];
        foreach (preg_split('/\R/', $out) ?: [] as $line) {
            if (preg_match('/GRANT ALL PRIVILEGES ON `([A-Za-z0-9_]+)`\.\*/i', $line, $m) === 1) {
                $found[] = $m[1];
            }
        }
        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    }

    /**
     * Create `<db>` (utf8mb4) if it is missing.
     *
     * @return bool true when this call created it, false when it already existed
     */
    public function createDatabase(string $database): bool
    {
        if ($this->databaseExists($database)) {
            return false;
        }
        $this->sql(
            'CREATE DATABASE IF NOT EXISTS ' . self::quoteIdentifier($database)
            . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
        );

        return true;
    }

    /**
     * Stream a prepared .sql dump into the client (stdinFile — never buffered in
     * PHP). The dump is already sanitised + carries its own `USE` line.
     */
    public function importFile(string $path, int $timeout): void
    {
        if (!is_file($path) || is_link($path)) {
            throw new TaskRejectedException('prepared SQL dump is missing');
        }
        $res = $this->cmd->run([$this->client(), '-N', '-B'], $timeout, null, $path);
        if (!$res->ok()) {
            $this->logFailure('MariaDB import failed', $res);

            throw new TaskRejectedException('MariaDB import failed');
        }
    }

    // ------------------------------------------------------------------
    //  Internals
    // ------------------------------------------------------------------

    /** Absolute client binary (ACP_MYSQL_CLIENT, default mariadb). */
    private function client(): string
    {
        $env = getenv('ACP_MYSQL_CLIENT');
        $client = is_string($env) && trim($env) !== '' ? trim($env) : '/usr/bin/mariadb';
        if ($client === '' || $client[0] !== '/') {
            throw new TaskRejectedException('ACP_MYSQL_CLIENT must be an absolute path');
        }

        return $client;
    }

    /** Send one SQL script on stdin and return the client's stdout. */
    private function run(string $sql, int $timeout = self::SQL_TIMEOUT): string
    {
        $res = $this->cmd->run([$this->client(), '-N', '-B'], $timeout, $sql);
        if (!$res->ok()) {
            $this->logFailure('MariaDB command failed', $res);

            throw new TaskRejectedException('MariaDB command failed');
        }

        return $res->stdout;
    }

    /** Record the client's stderr in the task log — never in the exception. */
    private function logFailure(string $message, CommandResult $res): void
    {
        $detail = trim((string) preg_replace('/\s+/', ' ', $res->stderr));
        if ($detail === '') {
            $detail = 'exit ' . $res->exitCode . ($res->timedOut ? ' (timeout)' : '');
        }
        $this->log->error($message . ': ' . substr($detail, 0, 200));
    }
}
PHPEOF
  chmod 0644 "$TARGET"
  ok "MysqlServer.php likhi ($(wc -c <"$TARGET") bytes)"

  # ---- lint (parse-error par rollback) -------------------------------------
  if ! "$PHP" -l "$TARGET" >/dev/null 2>&1; then
    warn "php -l FAIL — rollback kar raha hoon"
    if [[ "$pre_existed" == "1" ]]; then cp -p "${BACKUP}/MysqlServer.php" "$TARGET"; else rm -f "$TARGET"; fi
    die "lint fail: MysqlServer.php parse nahi hui (deploy roka, backup wapas)"
  fi
  ok "php -l clean"

  # ---- HARD GATE: static smoke (no pdo needed) -----------------------------
  hdr "SELF-TEST 1/2 — static smoke (class load + pure helpers)"
  if ! run_smoke; then
    warn "smoke FAIL — rollback"
    if [[ "$pre_existed" == "1" ]]; then cp -p "${BACKUP}/MysqlServer.php" "$TARGET"; else rm -f "$TARGET"; fi
    die "static smoke fail (deploy roka, backup wapas)"
  fi
  ok "static smoke PASS"

  # ---- GATE 2 (best effort): full agent suite if pdo_sqlite present --------
  hdr "SELF-TEST 2/2 — full agent suite (agar pdo_sqlite ho)"
  if has_pdo_sqlite; then
    local sum p f
    sum="$(suite_summary)"
    if [[ -n "$sum" ]]; then
      p="${sum% *}"; f="${sum#* }"
      info "suite: passed=${p} failed=${f}"
      if [[ "$f" != "0" ]]; then
        warn "suite me ${f} failures — rollback"
        if [[ "$pre_existed" == "1" ]]; then cp -p "${BACKUP}/MysqlServer.php" "$TARGET"; else rm -f "$TARGET"; fi
        die "agent suite green nahi (failed=${f}) — deploy roka, backup wapas"
      fi
      ok "agent suite GREEN (passed=${p} failed=0)"
    else
      warn "suite chal nahi paya (env) — static smoke gate pass hai, aage badh rahe hain"
    fi
  else
    warn "pdo_sqlite nahi — full suite skip; static smoke gate pass hai"
  fi

  # ---- restart paneld ------------------------------------------------------
  hdr "paneld restart"
  if have_systemd && [[ -f "/etc/systemd/system/${UNIT}.service" ]]; then
    systemctl restart "${UNIT}" >/dev/null 2>&1 || warn "systemctl restart fail"
    sleep 1
    if systemctl is-active --quiet "${UNIT}"; then
      ok "paneld active"
    else
      warn "paneld active nahi — rollback"
      if [[ "$pre_existed" == "1" ]]; then cp -p "${BACKUP}/MysqlServer.php" "$TARGET"; else rm -f "$TARGET"; fi
      systemctl restart "${UNIT}" >/dev/null 2>&1 || true
      die "paneld restart ke baad active nahi (deploy roka, backup wapas)"
    fi
  else
    warn "paneld unit install nahi (ya systemd nahi) — restart skip; autoloader naya file next db.* task par le lega"
  fi

  # ---- sync (best effort) --------------------------------------------------
  hdr "alphacp-sync (snapshot capture)"
  if command -v alphacp-sync >/dev/null 2>&1; then
    if alphacp-sync >>"$LOG_FILE" 2>&1; then ok "sync complete (fix snapshot me capture)"; else warn "sync fail (deploy par asar nahi) — baad me: sudo alphacp-sync"; fi
  else
    warn "alphacp-sync nahi mila — baad me chalana: sudo alphacp-sync"
  fi

  # ---- final verdict -------------------------------------------------------
  hdr "FINAL VERDICT"
  ok "MysqlServer.php deployed: ${TARGET}"
  ok "real db.* tasks (create/drop/user.*/list) + db.restore ab agent-step par chalenge"
  info "backup : ${BACKUP}"
  info "log    : ${LOG_FILE}"
  info "rollback: sudo bash $0 --rollback"
  say ""
  say "  ${C_G}agent-fix v${VERSION} APPLY ho gaya.${C_0} Panel me ek MySQL database/user bana kar verify karo."
}

usage(){ sed -nE 's/^#( |=)(.*)$/\2/p' "$0" | sed -n '1,40p'; }

mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true
case "${1:-apply}" in
  --diagnose|-d) diagnose ;;
  --rollback|-r) rollback ;;
  --help|-h)     usage ;;
  apply|"")      apply ;;
  *)             die "unknown option: $1 (--diagnose | --rollback | --help)" ;;
esac
