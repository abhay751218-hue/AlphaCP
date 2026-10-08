#!/usr/bin/env bash
# =============================================================================
#  AlphaCP — B3 FIX  v1.0  (WebDisk = asli WebDAV provisioning root-agent se)
# -----------------------------------------------------------------------------
#  Live par Web Disk page sirf DB rows likhta tha — koi WebDAV exist hi nahi
#  karta tha (audit B3). cPanel-tareeka: agent account ke liye WebDAV provision
#  karta hai — Digest credential file + managed Apache DAV conf + vhost include
#  + reload. Ye script agent ki 7 + panel ki 3 files byte-for-byte deploy karti
#  hai:
#    AGENT : src/WebDisk.php, src/Tasks/WebDiskList.php,
#            src/Tasks/WebDiskCreate.php, src/Tasks/WebDiskDelete.php,
#            src/AccountOs.php, src/AccountPaths.php, config/tasks.php
#            (webdisk.* = 99 types)
#    PANEL : app/Http/Controllers/WebDiskController.php, routes/web.php,
#            resources/views/webdisk/index.blade.php
#
#  Safety (b2-fix jaisa): backup → har file par `php -l` (fail par error print) →
#  static smoke → pdo_sqlite ho to poora agent suite gate (passed>=222 failed=0) →
#  paneld restart → apache WebDAV modules (dav/dav_fs/auth_digest, warn-only) →
#  panel files + blade → agent-truth asserts → optimize:clear + php-fpm restart
#  (opcache) → HTTP smoke → alphacp-sync. Kahin fail = auto-rollback.
#
#  Usage: sudo bash b3-fix-v1.0.sh | --diagnose | --rollback | --help
# =============================================================================
set -Eeuo pipefail

VERSION="1.0"
ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"
AGENT="${ACP_HOME}/agent"
PANEL="${ACP_HOME}/panel"
STAMP="$(date -u +%Y%m%d%H%M%S)"
BACKUP="${ACP_HOME}/releases/b3fix-${STAMP}"
LOG_FILE="${ACP_HOME}/logs/b3-fix-${STAMP}.txt"

C_R=$'\033[0;31m'; C_G=$'\033[0;32m'; C_Y=$'\033[0;33m'; C_B=$'\033[0;36m'; C_D=$'\033[0;2m'; C_0=$'\033[0m'
say(){ printf '%s\n' "$*" | tee -a "${LOG_FILE:-/dev/null}"; }
hdr(){ say ""; say "${C_B}== $* ==${C_0}"; }
ok(){ say "  ${C_G}✔${C_0} $*"; }
warn(){ say "  ${C_Y}⚠${C_0} $*"; }
info(){ say "  ${C_D}·${C_0} $*"; }
die(){ say "  ${C_R}✖ $*${C_0}"; exit 1; }
have_systemd(){ [[ -d /run/systemd/system ]] && command -v systemctl >/dev/null 2>&1; }
cnt(){ grep -c "$@" 2>/dev/null || true; }

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

AGENT_FILES=(src/WebDisk.php src/Tasks/WebDiskList.php src/Tasks/WebDiskCreate.php src/Tasks/WebDiskDelete.php src/AccountOs.php src/AccountPaths.php config/tasks.php)
PANEL_FILES=(app/Http/Controllers/WebDiskController.php routes/web.php)
PANEL_EXTRA=(resources/views/webdisk/index.blade.php)

rollback(){
  hdr "ROLLBACK — b3-fix v${VERSION}"
  local latest rel
  latest="$(ls -1dt "${ACP_HOME}"/releases/b3fix-* 2>/dev/null | head -1 || true)"
  [[ -n "$latest" ]] || die "koi b3fix backup nahi mila"
  info "backup: $latest"
  for rel in "${AGENT_FILES[@]}"; do
    if [[ -f "${latest}/agent/${rel}" ]]; then cp -p "${latest}/agent/${rel}" "${AGENT}/${rel}"; else rm -f "${AGENT}/${rel}"; fi
  done
  for rel in "${PANEL_FILES[@]}" "${PANEL_EXTRA[@]}"; do
    if [[ -f "${latest}/panel/${rel}" ]]; then cp -p "${latest}/panel/${rel}" "${PANEL}/${rel}"; else rm -f "${PANEL}/${rel}"; fi
  done
  if have_systemd; then
    systemctl restart paneld >/dev/null 2>&1 || true
    [[ -n "$FPM_UNIT" ]] && { systemctl restart "$FPM_UNIT" >/dev/null 2>&1 || true; }
  fi
  ok "rollback complete (purani files wapas)"
}

diagnose(){
  hdr "DIAGNOSE (read-only) — b3-fix v${VERSION}"
  info "ACP_HOME=${ACP_HOME} php=${PHP_BIN:-none} panel_user=${PANEL_USER} fpm=${FPM_UNIT:-none}"
  info "agent src/WebDisk.php        : $( [[ -f "${AGENT}/src/WebDisk.php" ]] && echo PRESENT || echo MISSING )"
  info "agent registry webdisk       : $(cnt "'webdisk\.create'" "${AGENT}/config/tasks.php")  (1=registered)"
  info "panel controller Paneld      : $(cnt 'Paneld::run' "${PANEL}/app/Http/Controllers/WebDiskController.php")  (>=1=theek)"
  info "panel view password field    : $(cnt 'name="password"' "${PANEL}/resources/views/webdisk/index.blade.php")  (>=1=theek)"
  say ""; ok "diagnose complete (kuch badla nahi)"
}

apply(){
  hdr "APPLY — b3-fix v${VERSION} (WebDisk via root agent)"
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
  for rel in "${PANEL_FILES[@]}" "${PANEL_EXTRA[@]}"; do
    mkdir -p "${BACKUP}/panel/$(dirname "$rel")"
    [[ -f "${PANEL}/${rel}" ]] && cp -p "${PANEL}/${rel}" "${BACKUP}/panel/${rel}" || true
  done
  ok "backup: ${BACKUP}"

  # ---- agent files ----
  hdr "agent files likhna"
  cat > "${AGENT}/src/WebDisk.php" <<'PHPEOF'
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
PHPEOF
  cat > "${AGENT}/src/Tasks/WebDiskList.php" <<'PHPEOF'
<?php

declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\WebDisk;

/**
 * webdisk.list — account ke provisioned WebDAV (Web Disk) accounts.
 *
 * Source of truth = `<home>/etc/webdisk.conf` ke managed comments (agent-side);
 * panel isi list se Web Disk page render karta hai (cPanel parity, audit B3).
 *
 * @acp-task webdisk.list
 */
final class WebDiskList implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        [$username, $wd] = WebDisk::forAccount($payload, $ctx);

        return [
            'username' => $username,
            'accounts' => $wd->entries($username),
            'realm'    => WebDisk::REALM,
            'status'   => 'ok',
        ];
    }
}
PHPEOF
  cat > "${AGENT}/src/Tasks/WebDiskCreate.php" <<'PHPEOF'
<?php

declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\WebDisk;

/**
 * webdisk.create — WebDAV (Web Disk) login banata / reset karta hai.
 *
 * Upsert semantics (cPanel jaisa): same login dobara = password/permissions
 * reset. Agent digest file (`login:realm:md5(...)`) + managed Apache DAV conf
 * likhta hai, vhost include ensure karta hai aur apache reload karta hai.
 * Plaintext password kahin persist nahi hota (DB me bhi nahi).
 *
 * @acp-task webdisk.create
 */
final class WebDiskCreate implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        [$username, $wd] = WebDisk::forAccount($payload, $ctx);
        $login = (string) ($payload['login'] ?? '');
        $perm  = (string) ($payload['permissions'] ?? '');
        $pass  = (string) ($payload['password'] ?? '');

        $wd->create($username, $login, $perm, $pass);

        return [
            'username'    => $username,
            'login'       => $login,
            'permissions' => $perm,
            'accounts'    => count($wd->entries($username)),
            'status'      => 'ok',
        ];
    }
}
PHPEOF
  cat > "${AGENT}/src/Tasks/WebDiskDelete.php" <<'PHPEOF'
<?php

declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\WebDisk;

/**
 * webdisk.delete — WebDAV (Web Disk) login hatata hai.
 *
 * Digest entry + managed conf entry remove; aakhri account delete hone par
 * conf+digest files bhi clean ho jati hain (IncludeOptional missing tolerate
 * karta hai, reload se Alias/DAV block hat jata hai).
 *
 * @acp-task webdisk.delete
 */
final class WebDiskDelete implements TaskInterface
{
    public function handle(array $payload, TaskContext $ctx): array
    {
        [$username, $wd] = WebDisk::forAccount($payload, $ctx);
        $login = (string) ($payload['login'] ?? '');

        $wd->delete($username, $login);

        return [
            'username' => $username,
            'login'    => $login,
            'accounts' => count($wd->entries($username)),
            'status'   => 'ok',
        ];
    }
}
PHPEOF
  cat > "${AGENT}/src/AccountOs.php" <<'PHPEOF'
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
            $body = $this->fs->read($file);   // symlink-safe read (root process)
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
     * WebDisk (B3) ka managed snippet vhost me include karta hai — privacy/mime/
     * handlers wala hi pattern: `IncludeOptional <home>/etc/webdisk.conf`.
     */
    public function ensureWebDiskInclude(string $username): void
    {
        $home = $this->paths->home($username);
        $needle = 'IncludeOptional ' . $home . '/etc/webdisk.conf';
        $files = [$this->paths->vhost($username)];
        foreach ($this->listExtraVhosts($username) as $extra) {
            $files[] = $extra;
        }
        foreach ($files as $file) {
            if (!$this->fs->isFile($file)) {
                continue;
            }
            $body = $this->fs->read($file);   // symlink-safe read (root process)
            if (str_contains($body, 'webdisk.conf')) {
                continue;
            }
            if (!str_contains($body, '</VirtualHost>')) {
                continue;
            }
            $body = str_replace('</VirtualHost>', "    {$needle}\n</VirtualHost>", $body);
            $this->fs->write($file, $body, 0644);
        }
    }

    /** Sirf apache reload (WebDisk conf/include changes ke liye). */
    public function reloadApache(): void
    {
        $this->reload($this->paths->apacheService);
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

    /**
     * @param  list<array{local: string, domain: string, field: string, needle: string, action: string, folder: string}> $rows
     * @return list<array{local: string, domain: string, field: string, needle: string, action: string, folder: string}>
     */
    public function setFilters(string $username, array $rows): array
    {
        $rows = Mail::sanitizeFilters($rows);
        $home = $this->paths->home($username);
        $dir = Files::resolve($home, 'etc/mail');
        if (is_link($dir)) {
            throw new RuntimeException('mail conf dir is a symlink');
        }
        $this->fs->mkdir($dir, 0750);
        $this->fs->chownName($dir, $username);
        $file = Files::resolve($home, 'etc/mail/filters');
        if (is_link($file)) {
            throw new RuntimeException('mail filters is a symlink');
        }
        $this->fs->write($file, Mail::filtersJson($rows), 0640);
        $this->fs->chownName($file, $username);
        $this->log->info('mail filters ' . count($rows) . " for {$username}");

        return $rows;
    }

    /**
     * @param  list<array{domain: string, spf: string, dmarc: string, dkim_selector: string}> $rows
     * @return list<array{domain: string, spf: string, dmarc: string, dkim_selector: string}>
     */
    public function setDeliverability(string $username, array $rows): array
    {
        $rows = Mail::sanitizeDeliverability($rows);
        $home = $this->paths->home($username);
        $dir = Files::resolve($home, 'etc/mail');
        if (is_link($dir)) {
            throw new RuntimeException('mail conf dir is a symlink');
        }
        $this->fs->mkdir($dir, 0750);
        $this->fs->chownName($dir, $username);
        $file = Files::resolve($home, 'etc/mail/deliverability.json');
        if (is_link($file)) {
            throw new RuntimeException('mail deliverability is a symlink');
        }
        $this->fs->write($file, Mail::deliverabilityJson($rows), 0640);
        $this->fs->chownName($file, $username);
        $this->log->info('mail deliverability ' . count($rows) . " for {$username}");

        return $rows;
    }

    /**
     * @param  array{required_score: int, blacklist: list<string>, whitelist: list<string>} $cfg
     * @return array{required_score: int, blacklist: list<string>, whitelist: list<string>}
     */
    public function setSpam(string $username, array $cfg): array
    {
        $cfg = Mail::sanitizeSpam($cfg);
        $home = $this->paths->home($username);
        $dir = Files::resolve($home, 'etc/mail');
        if (is_link($dir)) {
            throw new RuntimeException('mail conf dir is a symlink');
        }
        $this->fs->mkdir($dir, 0750);
        $this->fs->chownName($dir, $username);
        $file = Files::resolve($home, 'etc/mail/spam.json');
        if (is_link($file)) {
            throw new RuntimeException('mail spam is a symlink');
        }
        $this->fs->write($file, Mail::spamJson($cfg), 0640);
        $this->fs->chownName($file, $username);
        $this->log->info('mail spam score ' . $cfg['required_score'] . " for {$username}");

        return $cfg;
    }

    /**
     * @param  list<array{local: string, domain: string, owner: string, members: list<string>}> $rows
     * @return list<array{local: string, domain: string, owner: string, members: list<string>}>
     */
    public function setLists(string $username, array $rows): array
    {
        $rows = Mail::sanitizeLists($rows);
        $home = $this->paths->home($username);
        $dir = Files::resolve($home, 'etc/mail');
        if (is_link($dir)) {
            throw new RuntimeException('mail conf dir is a symlink');
        }
        $this->fs->mkdir($dir, 0750);
        $this->fs->chownName($dir, $username);
        $file = Files::resolve($home, 'etc/mail/lists.json');
        if (is_link($file)) {
            throw new RuntimeException('mail lists is a symlink');
        }
        $this->fs->write($file, Mail::listsJson($rows), 0640);
        $this->fs->chownName($file, $username);
        $this->log->info('mail lists ' . count($rows) . " for {$username}");

        return $rows;
    }

    /**
     * @param  list<array{domain: string, mode: string}> $rows
     * @return list<array{domain: string, mode: string}>
     */
    public function setRouting(string $username, array $rows): array
    {
        $rows = Mail::sanitizeRouting($rows);
        $home = $this->paths->home($username);
        $dir = Files::resolve($home, 'etc/mail');
        if (is_link($dir)) {
            throw new RuntimeException('mail conf dir is a symlink');
        }
        $this->fs->mkdir($dir, 0750);
        $this->fs->chownName($dir, $username);
        $file = Files::resolve($home, 'etc/mail/routing.json');
        if (is_link($file)) {
            throw new RuntimeException('mail routing is a symlink');
        }
        $this->fs->write($file, Mail::routingJson($rows), 0640);
        $this->fs->chownName($file, $username);
        $this->log->info('mail routing ' . count($rows) . " for {$username}");

        return $rows;
    }

    /**
     * @param  list<array{kind: string, path: string}> $jobs
     * @return list<array{kind: string, path: string}>
     */
    public function setBackup(string $username, array $jobs): array
    {
        $jobs = Backup::sanitize($jobs);
        $home = $this->paths->home($username);
        $dir = Files::resolve($home, 'etc/backup');
        if (is_link($dir)) {
            throw new RuntimeException('backup conf dir is a symlink');
        }
        $this->fs->mkdir($dir, 0750);
        $this->fs->chownName($dir, $username);
        $file = Files::resolve($home, 'etc/backup/jobs.json');
        if (is_link($file)) {
            throw new RuntimeException('backup jobs is a symlink');
        }
        foreach ($jobs as $row) {
            if ($row['path'] !== '') {
                Files::resolve($home, $row['path']);
            }
        }
        $this->fs->write($file, Backup::backupJson($jobs), 0640);
        $this->fs->chownName($file, $username);
        $this->log->info('backup jobs ' . count($jobs) . " for {$username}");

        return $jobs;
    }

    /**
     * @return array{action: string, scope: string}
     */
    public function setBackupWizard(string $username, string $action, string $scope): array
    {
        $action = Backup::normalizeAction($action);
        $scope = Backup::normalizeScope($scope);
        $home = $this->paths->home($username);
        $dir = Files::resolve($home, 'etc/backup');
        if (is_link($dir)) {
            throw new RuntimeException('backup conf dir is a symlink');
        }
        $this->fs->mkdir($dir, 0750);
        $this->fs->chownName($dir, $username);
        $file = Files::resolve($home, 'etc/backup/wizard.json');
        if (is_link($file)) {
            throw new RuntimeException('backup wizard is a symlink');
        }
        $this->fs->write($file, Backup::wizardJson($action, $scope), 0640);
        $this->fs->chownName($file, $username);
        $this->log->info("backup wizard {$action}/{$scope} for {$username}");

        return ['action' => $action, 'scope' => $scope];
    }

    /**
     * @param  list<array{path: string}> $rows
     * @return list<array{path: string}>
     */
    public function setBackupRestore(string $username, array $rows): array
    {
        $rows = Backup::sanitizeRestore($rows);
        $home = $this->paths->home($username);
        $dir = Files::resolve($home, 'etc/backup');
        if (is_link($dir)) {
            throw new RuntimeException('backup conf dir is a symlink');
        }
        $this->fs->mkdir($dir, 0750);
        $this->fs->chownName($dir, $username);
        $file = Files::resolve($home, 'etc/backup/restore.json');
        if (is_link($file)) {
            throw new RuntimeException('backup restore is a symlink');
        }
        foreach ($rows as $row) {
            Files::resolve($home, $row['path']);
        }
        $this->fs->write($file, Backup::restoreJson($rows), 0640);
        $this->fs->chownName($file, $username);
        $this->log->info('backup restore ' . count($rows) . " for {$username}");

        return $rows;
    }

    /**
     * @return list<array{id: string, time: string, sender: string, recipient: string, status: string}>
     */
    public function track(string $username, string $query): array
    {
        $query = Mail::normalizeDest($query);
        $home = $this->paths->home($username);
        $file = Files::resolve($home, 'etc/mail/track.json');
        if (is_link($file)) {
            throw new RuntimeException('mail track is a symlink');
        }
        if (!$this->fs->exists($file)) {
            return [];
        }
        $hits = Mail::filterTrack($query, $this->fs->read($file));
        $this->log->info('mail track ' . count($hits) . " for {$username}");

        return $hits;
    }

    /**
     * @param  list<array{domain: string, field: string, needle: string, action: string, folder: string}> $rows
     * @return list<array{domain: string, field: string, needle: string, action: string, folder: string}>
     */
    public function setGlobalFilters(string $username, array $rows): array
    {
        $rows = Mail::sanitizeGlobalFilters($rows);
        $home = $this->paths->home($username);
        $dir = Files::resolve($home, 'etc/mail');
        if (is_link($dir)) {
            throw new RuntimeException('mail conf dir is a symlink');
        }
        $this->fs->mkdir($dir, 0750);
        $this->fs->chownName($dir, $username);
        $file = Files::resolve($home, 'etc/mail/global-filters.json');
        if (is_link($file)) {
            throw new RuntimeException('mail global filters is a symlink');
        }
        $this->fs->write($file, Mail::globalFiltersJson($rows), 0640);
        $this->fs->chownName($file, $username);
        $this->log->info('mail global filters ' . count($rows) . " for {$username}");

        return $rows;
    }

    /**
     * @param  list<array{local: string, domain: string, comment: string}> $rows
     * @return list<array{local: string, domain: string, comment: string}>
     */
    public function setEncrypt(string $username, array $rows): array
    {
        $rows = Mail::sanitizeEncrypt($rows);
        $home = $this->paths->home($username);
        $dir = Files::resolve($home, 'etc/mail');
        if (is_link($dir)) {
            throw new RuntimeException('mail conf dir is a symlink');
        }
        $this->fs->mkdir($dir, 0750);
        $this->fs->chownName($dir, $username);
        $file = Files::resolve($home, 'etc/mail/encrypt.json');
        if (is_link($file)) {
            throw new RuntimeException('mail encrypt is a symlink');
        }
        $this->fs->write($file, Mail::encryptJson($rows), 0640);
        $this->fs->chownName($file, $username);
        $this->log->info('mail encrypt ' . count($rows) . " for {$username}");

        return $rows;
    }

    /**
     * @param  array{enabled: bool, allowlist: list<string>} $cfg
     * @return array{enabled: bool, allowlist: list<string>}
     */
    public function setBoxtrapper(string $username, array $cfg): array
    {
        $cfg = Mail::sanitizeBoxtrapper($cfg);
        $home = $this->paths->home($username);
        $dir = Files::resolve($home, 'etc/mail');
        if (is_link($dir)) {
            throw new RuntimeException('mail conf dir is a symlink');
        }
        $this->fs->mkdir($dir, 0750);
        $this->fs->chownName($dir, $username);
        $file = Files::resolve($home, 'etc/mail/boxtrapper.json');
        if (is_link($file)) {
            throw new RuntimeException('mail boxtrapper is a symlink');
        }
        $this->fs->write($file, Mail::boxtrapperJson($cfg), 0640);
        $this->fs->chownName($file, $username);
        $this->log->info('mail boxtrapper ' . ($cfg['enabled'] ? 'on' : 'off') . " for {$username}");

        return $cfg;
    }

    /**
     * @param  array{calendars: list<array{name: string}>, contacts: list<array{name: string}>} $cfg
     * @return array{calendars: list<array{name: string}>, contacts: list<array{name: string}>}
     */
    public function setCalendar(string $username, array $cfg): array
    {
        $cfg = [
            'calendars' => Mail::sanitizeCalNames($cfg['calendars'] ?? [], 'calendar'),
            'contacts' => Mail::sanitizeCalNames($cfg['contacts'] ?? [], 'contact'),
        ];
        $home = $this->paths->home($username);
        $dir = Files::resolve($home, 'etc/mail');
        if (is_link($dir)) {
            throw new RuntimeException('mail conf dir is a symlink');
        }
        $this->fs->mkdir($dir, 0750);
        $this->fs->chownName($dir, $username);
        $file = Files::resolve($home, 'etc/mail/calendar.json');
        if (is_link($file)) {
            throw new RuntimeException('mail calendar is a symlink');
        }
        $this->fs->write($file, Mail::calendarJson($cfg), 0640);
        $this->fs->chownName($file, $username);
        $this->log->info('mail calendar ' . count($cfg['calendars']) . '+' . count($cfg['contacts']) . " for {$username}");

        return $cfg;
    }

    /**
     * Folder-wise usage under ~/mail only. Symlinks skipped. Missing mail/ = empty.
     *
     * @return array{bytes:int, truncated:bool, entries:list<array{name:string,type:string,bytes:int}>}
     */
    public function mailUsage(string $username, string $rel): array
    {
        $home = $this->paths->home($username);
        $mailRoot = Files::resolve($home, 'mail');
        $mailRel = $rel === '' ? 'mail' : 'mail/' . $rel;
        $root = Files::resolve($home, $mailRel);
        $canonical = PathGuard::canonicalize($root);
        $mailCanon = PathGuard::canonicalize($mailRoot);
        if ($canonical !== $mailCanon && !str_starts_with($canonical, $mailCanon . '/')) {
            throw new RuntimeException('path outside mail');
        }
        if (is_link($root)) {
            throw new RuntimeException('mail path is a symlink');
        }
        if (!is_dir($root)) {
            return ['bytes' => 0, 'truncated' => false, 'entries' => []];
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
     * @param  array{enabled: bool, client: string} $cfg
     * @return array{enabled: bool, client: string}
     */
    public function setWebmail(string $username, array $cfg): array
    {
        $cfg = Mail::sanitizeWebmail($cfg);
        $home = $this->paths->home($username);
        $dir = Files::resolve($home, 'etc/mail');
        if (is_link($dir)) {
            throw new RuntimeException('mail conf dir is a symlink');
        }
        $this->fs->mkdir($dir, 0750);
        $this->fs->chownName($dir, $username);
        $file = Files::resolve($home, 'etc/mail/webmail.json');
        if (is_link($file)) {
            throw new RuntimeException('mail webmail is a symlink');
        }
        $this->fs->write($file, Mail::webmailJson($cfg), 0640);
        $this->fs->chownName($file, $username);
        $this->log->info('mail webmail ' . ($cfg['enabled'] ? $cfg['client'] : 'off') . " for {$username}");

        return $cfg;
    }

    /**
     * @param  list<array{name: string, full: string}> $rows
     * @return list<array{name: string, full: string}>
     */
    public function setDatabases(string $username, array $rows): array
    {
        $home = $this->paths->home($username);
        $dir = Files::resolve($home, 'etc/mysql');
        if (is_link($dir)) {
            throw new RuntimeException('mysql conf dir is a symlink');
        }
        $this->fs->mkdir($dir, 0750);
        $this->fs->chownName($dir, $username);
        $file = Files::resolve($home, 'etc/mysql/databases.json');
        if (is_link($file)) {
            throw new RuntimeException('mysql databases is a symlink');
        }
        $this->fs->write($file, Mysql::databasesJson($rows), 0640);
        $this->fs->chownName($file, $username);
        $this->log->info('mysql databases ' . count($rows) . " for {$username}");

        return $rows;
    }

    /**
     * @param  array{enabled: bool} $cfg
     * @return array{enabled: bool}
     */
    public function setPhpmyadmin(string $username, array $cfg): array
    {
        $cfg = Mysql::sanitizePma($cfg);
        $home = $this->paths->home($username);
        $dir = Files::resolve($home, 'etc/mysql');
        if (is_link($dir)) {
            throw new RuntimeException('mysql conf dir is a symlink');
        }
        $this->fs->mkdir($dir, 0750);
        $this->fs->chownName($dir, $username);
        $file = Files::resolve($home, 'etc/mysql/phpmyadmin.json');
        if (is_link($file)) {
            throw new RuntimeException('mysql phpmyadmin is a symlink');
        }
        $this->fs->write($file, Mysql::pmaJson($cfg), 0640);
        $this->fs->chownName($file, $username);
        $this->log->info('mysql phpmyadmin ' . ($cfg['enabled'] ? 'on' : 'off') . " for {$username}");

        return $cfg;
    }

    /**
     * @param  list<array{host: string}> $rows
     * @return list<array{host: string}>
     */
    public function setRemoteHosts(string $username, array $rows): array
    {
        $home = $this->paths->home($username);
        $dir = Files::resolve($home, 'etc/mysql');
        if (is_link($dir)) {
            throw new RuntimeException('mysql conf dir is a symlink');
        }
        $this->fs->mkdir($dir, 0750);
        $this->fs->chownName($dir, $username);
        $file = Files::resolve($home, 'etc/mysql/remote.json');
        if (is_link($file)) {
            throw new RuntimeException('mysql remote is a symlink');
        }
        $this->fs->write($file, Mysql::remoteJson($rows), 0640);
        $this->fs->chownName($file, $username);
        $this->log->info('mysql remote ' . count($rows) . " for {$username}");

        return $rows;
    }

    /**
     * @param  list<array{domain: string, name: string, type: string, value: string}> $rows
     * @return list<array{domain: string, name: string, type: string, value: string}>
     */
    public function setZone(string $username, array $rows): array
    {
        $home = $this->paths->home($username);
        $dir = Files::resolve($home, 'etc/dns');
        if (is_link($dir)) {
            throw new RuntimeException('dns conf dir is a symlink');
        }
        $this->fs->mkdir($dir, 0750);
        $this->fs->chownName($dir, $username);
        $file = Files::resolve($home, 'etc/dns/zone.json');
        if (is_link($file)) {
            throw new RuntimeException('dns zone is a symlink');
        }
        $this->fs->write($file, Dns::zoneJson($rows), 0640);
        $this->fs->chownName($file, $username);
        $this->log->info('dns zone ' . count($rows) . " for {$username}");

        return $rows;
    }

    /**
     * @param  list<array{domain: string, name: string, token: string, ip: string}> $rows
     * @return list<array{domain: string, name: string, token: string, ip: string}>
     */
    public function setDynamic(string $username, array $rows): array
    {
        $home = $this->paths->home($username);
        $dir = Files::resolve($home, 'etc/dns');
        if (is_link($dir)) {
            throw new RuntimeException('dns conf dir is a symlink');
        }
        $this->fs->mkdir($dir, 0750);
        $this->fs->chownName($dir, $username);
        $file = Files::resolve($home, 'etc/dns/dynamic.json');
        if (is_link($file)) {
            throw new RuntimeException('dns dynamic is a symlink');
        }
        $this->fs->write($file, Dns::dynamicJson($rows), 0640);
        $this->fs->chownName($file, $username);
        $this->log->info('dns dynamic ' . count($rows) . " for {$username}");

        return $rows;
    }

    /**
     * @return list<array{source: string, domain: string, name: string, type: string, value: string}>
     */
    public function trackDns(string $username, string $query, string $type): array
    {
        $home = $this->paths->home($username);
        $zoneFile = Files::resolve($home, 'etc/dns/zone.json');
        $dynFile = Files::resolve($home, 'etc/dns/dynamic.json');
        if (is_link($zoneFile) || is_link($dynFile)) {
            throw new RuntimeException('dns track is a symlink');
        }
        $zoneJson = $this->fs->exists($zoneFile) ? $this->fs->read($zoneFile) : '[]';
        $dynJson = $this->fs->exists($dynFile) ? $this->fs->read($dynFile) : '[]';
        $hits = Dns::filterTrack($query, $type, $zoneJson, $dynJson);
        $this->log->info('dns track ' . count($hits) . " for {$username}");

        return $hits;
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

    /**
     * Marker we put in the GECOS field so AlphaCP-created Linux users stay
     * recognisable (and are the only ones we will delete).
     *
     * NOTE: `useradd` rejects ANY comment containing a colon ("invalid
     * comment"), so the marker uses a space. Legacy 'AlphaCP:' users are still
     * recognised for backward compatibility.
     */
    public const GECOS_MARKER = 'AlphaCP';

    public function isOurUser(string $username): bool
    {
        $result = $this->cmd->run(['/usr/bin/getent', 'passwd', $username], 10);
        if (!$result->ok()) {
            return false;
        }
        return str_contains($result->stdout, self::GECOS_MARKER . ' ')
            || str_contains($result->stdout, self::GECOS_MARKER . ':');
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
            // useradd rejects a colon in the comment (GECOS) field.
            '-c', self::GECOS_MARKER . ' ' . $domain,
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
        $this->fs->write($cert, $this->fs->read($liveCert), 0644);
        $this->fs->write($key, $this->fs->read($liveKey), 0600);
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
        try {
            $body = $this->fs->read($path);   // symlink-safe read (root process)
        } catch (RuntimeException) {
            return [];
        }
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
            $body = $this->fs->read($file);   // symlink-safe read (root process)
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
            $body = $this->fs->read($file);   // symlink-safe read (root process)
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
            $body = $this->fs->read($file);   // symlink-safe read (root process)
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
            $body = $this->fs->read($file);   // symlink-safe read (root process)
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
PHPEOF
  cat > "${AGENT}/src/AccountPaths.php" <<'PHPEOF'
<?php
declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * Where an account's OS objects live. Overridable via env for tests/sim.
 * Production defaults match Ubuntu 24.04 + Apache + Ondrej PHP-FPM.
 */
final class AccountPaths
{
    public function __construct(
        public readonly string $accountsRoot,
        public readonly string $apacheSites,
        public readonly string $apacheEnabled,
        public readonly string $phpPoolDir,
        public readonly string $suspendedRoot,
        public readonly string $phpFpmService,
        public readonly string $apacheService,
        public readonly string $nologin,
        public readonly string $phpVersion,
    ) {
    }

    public static function fromEnv(?string $phpVersion = null): self
    {
        $php = $phpVersion ?: self::detectPhpVersion();
        $nologin = getenv('ACP_NOLOGIN') ?: '';
        if ($nologin === '') {
            $nologin = is_file('/usr/sbin/nologin') ? '/usr/sbin/nologin' : '/sbin/nologin';
        }

        return new self(
            accountsRoot: rtrim((string) (getenv('ACP_ACCOUNTS_ROOT') ?: '/home'), '/'),
            apacheSites: rtrim((string) (getenv('ACP_APACHE_SITES') ?: '/etc/apache2/sites-available'), '/'),
            apacheEnabled: rtrim((string) (getenv('ACP_APACHE_ENABLED') ?: '/etc/apache2/sites-enabled'), '/'),
            phpPoolDir: rtrim((string) (getenv('ACP_PHP_POOL_DIR') ?: "/etc/php/{$php}/fpm/pool.d"), '/'),
            suspendedRoot: rtrim((string) (getenv('ACP_SUSPENDED_ROOT') ?: '/usr/local/alphacp/share/suspended'), '/'),
            phpFpmService: (string) (getenv('ACP_PHP_FPM_SERVICE') ?: "php{$php}-fpm"),
            apacheService: (string) (getenv('ACP_APACHE_SERVICE') ?: 'apache2'),
            nologin: $nologin,
            phpVersion: $php,
        );
    }

    public function home(string $username): string
    {
        return $this->accountsRoot . '/' . $username;
    }

    public function vhost(string $username): string
    {
        return $this->apacheSites . '/acp-' . $username . '.conf';
    }

    public function vhostEnabled(string $username): string
    {
        return $this->apacheEnabled . '/acp-' . $username . '.conf';
    }

    /** Extra (addon/sub/parked/redirect) vhost slug from a validated FQDN. */
    public function vhostSlug(string $domain): string
    {
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/', '-', $domain));
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'domain';
        }
        return substr($slug, 0, 80);
    }

    public function vhostExtra(string $username, string $domain): string
    {
        return $this->apacheSites . '/acp-' . $username . '-' . $this->vhostSlug($domain) . '.conf';
    }

    public function vhostExtraEnabled(string $username, string $domain): string
    {
        return $this->apacheEnabled . '/acp-' . $username . '-' . $this->vhostSlug($domain) . '.conf';
    }

    public function phpIniFile(string $username): string
    {
        return $this->home($username) . '/etc/php.ini';
    }

    public function errorpagesDir(string $username): string
    {
        return $this->home($username) . '/errorpages';
    }

    public function errorpagesConf(string $username): string
    {
        return $this->home($username) . '/etc/errorpages.conf';
    }

    public function indexesConf(string $username): string
    {
        return $this->home($username) . '/etc/indexes.conf';
    }

    public function mimeConf(string $username): string
    {
        return $this->home($username) . '/etc/mime.conf';
    }

    public function handlersConf(string $username): string
    {
        return $this->home($username) . '/etc/handlers.conf';
    }

    public function privacyConf(string $username): string
    {
        return $this->home($username) . '/etc/privacy.conf';
    }

    public function privacyDir(string $username): string
    {
        return $this->home($username) . '/etc/privacy';
    }

    public function webdiskConf(string $username): string
    {
        return $this->home($username) . '/etc/webdisk.conf';
    }

    public function webdiskDigest(string $username): string
    {
        return $this->home($username) . '/etc/webdisk.digest';
    }

    public function sslDir(string $username, string $domain): string
    {
        return $this->home($username) . '/ssl/' . $this->vhostSlug($domain);
    }

    public function leConfigDir(string $username): string
    {
        return $this->home($username) . '/ssl/letsencrypt';
    }

    public function vhostSsl(string $username, string $domain): string
    {
        return $this->apacheSites . '/acp-' . $username . '-' . $this->vhostSlug($domain) . '-ssl.conf';
    }

    public function vhostSslEnabled(string $username, string $domain): string
    {
        return $this->apacheEnabled . '/acp-' . $username . '-' . $this->vhostSlug($domain) . '-ssl.conf';
    }

    public function pool(string $username): string
    {
        return $this->phpPoolDir . '/acp-' . $username . '.conf';
    }

    public function poolDisabled(string $username): string
    {
        return $this->phpPoolDir . '/acp-' . $username . '.conf.suspended';
    }

    public function socketName(string $username): string
    {
        return 'acp-' . $username . '.sock';
    }

    public static function detectPhpVersion(): string
    {
        $env = getenv('ACP_PHP_VERSION') ?: '';
        if (is_string($env) && preg_match('/^8\.[0-9]$/', $env) === 1) {
            return $env;
        }
        foreach (['8.4', '8.3', '8.2', '8.1'] as $version) {
            if (is_dir("/etc/php/{$version}/fpm/pool.d")) {
                return $version;
            }
        }
        return '8.4';
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

    'webdisk.list' => [
        'handler'     => Tasks\WebDiskList::class,
        'safety'      => 'readonly',
        'timeout'     => 15,
        'description' => 'List provisioned Web Disk (WebDAV) accounts of an account.',
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

    'webdisk.create' => [
        'handler'     => Tasks\WebDiskCreate::class,
        'safety'      => 'mutating',
        'timeout'     => 30,
        'description' => 'Create/reset a Web Disk (WebDAV) login: digest credential + managed Apache DAV conf.',
        'paths'       => ['/home', '/etc/apache2'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['account', 'login', 'permissions', 'password'],
            'properties'           => [
                'account'     => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'login'       => ['type' => 'string', 'pattern' => '^[a-zA-Z0-9._-]{1,60}$', 'maxLength' => 60],
                'permissions' => ['type' => 'string', 'enum' => ['ro', 'rw']],
                'password'    => ['type' => 'string', 'minLength' => 8, 'maxLength' => 128],
            ],
        ],
    ],

    'webdisk.delete' => [
        'handler'     => Tasks\WebDiskDelete::class,
        'safety'      => 'mutating',
        'timeout'     => 30,
        'description' => 'Remove a Web Disk (WebDAV) login; last account par conf+digest clean.',
        'paths'       => ['/home', '/etc/apache2'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['account', 'login'],
            'properties'           => [
                'account' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'login'   => ['type' => 'string', 'pattern' => '^[a-zA-Z0-9._-]{1,60}$', 'maxLength' => 60],
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
  ok "agent files likhi + lint clean (7)"

  # ---- agent self-test ----
  hdr "SELF-TEST agent (static smoke + suite)"
  local smoke; smoke="$(mktemp)"
  cat > "$smoke" <<'SMOKE'
<?php
require $argv[1] . '/src/Bootstrap.php';
use Alphacp\Agent\WebDisk;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\Tasks\WebDiskList;
use Alphacp\Agent\Tasks\WebDiskCreate;
use Alphacp\Agent\Tasks\WebDiskDelete;
$f = 0;
function chk(bool $c, string $m): void { global $f; if (!$c) { fwrite(STDERR, "SMOKE FAIL: $m\n"); $f++; } }
chk(class_exists(WebDisk::class) && class_exists(WebDiskList::class)
    && class_exists(WebDiskCreate::class) && class_exists(WebDiskDelete::class), 'webdisk classes autoload');
chk(method_exists(AccountOs::class, 'ensureWebDiskInclude') && method_exists(AccountOs::class, 'reloadApache'), 'AccountOs webdisk hooks');
chk(method_exists(AccountPaths::class, 'webdiskConf') && method_exists(AccountPaths::class, 'webdiskDigest'), 'AccountPaths webdisk paths');
$types = array_keys(require $argv[1] . '/config/tasks.php');
chk(in_array('webdisk.list', $types, true) && in_array('webdisk.create', $types, true)
    && in_array('webdisk.delete', $types, true), 'webdisk types registered');
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
    if [[ "${n:-9}" != "0" || "${p:-0}" -lt 222 ]]; then rollback; die "agent suite green nahi (passed=${p:-?} failed=${n:-?})"; fi
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
    ok "paneld active (99-type registry load hua)"
  else
    warn "paneld unit / systemd nahi mila — restart skip (manual: systemctl restart paneld)"
  fi

  # ---- apache WebDAV modules ----
  hdr "apache WebDAV modules (dav/dav_fs/auth_digest)"
  if [[ -d /etc/apache2/mods-available ]] && command -v a2enmod >/dev/null 2>&1; then
    local missing="" m
    for m in dav dav_fs auth_digest; do
      [[ -e "/etc/apache2/mods-enabled/${m}.load" ]] || missing="${missing} ${m}"
    done
    if [[ -n "${missing// /}" ]]; then
      if a2enmod ${missing} >>"$LOG_FILE" 2>&1; then
        ok "a2enmod${missing} (WebDAV modules enable)"
        if have_systemd; then
          systemctl reload apache2 >/dev/null 2>&1 || systemctl restart apache2 >/dev/null 2>&1 || warn "apache reload manual karo"
        fi
      else
        warn "a2enmod${missing} fail — manual: sudo a2enmod${missing} && sudo systemctl reload apache2"
      fi
    else
      ok "dav + dav_fs + auth_digest pehle se enabled"
    fi
  else
    warn "apache2 mods-dir/a2enmod nahi mila — WebDAV module check skip"
  fi

  # ---- panel files ----
  hdr "panel files likhna"
  cat > "${PANEL}/app/Http/Controllers/WebDiskController.php" <<'PHPEOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\WebDiskAccount;
use App\Support\ModuleCatalog;
use App\Support\Paneld;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * cPanel "Web Disk" — WebDAV accounts (read-only / read-write).
 *
 * B3: provisioning ab ROOT AGENT karta hai (`webdisk.list` / `webdisk.create` /
 * `webdisk.delete`) — digest credential file + managed Apache DAV conf + vhost
 * include + reload. Pehle sirf DB row likhi jati thi (koi asli WebDAV nahi).
 * DB table sirf ownership/permissions cache hai; password DB me kabhi nahi jata.
 */
final class WebDiskController extends Controller
{
    public function index(Request $request): View
    {
        $account  = $this->accountFor($request);
        $accounts = [];
        $error    = null;

        if ($account !== null && in_array('webdisk.list', Paneld::taskTypes(), true)) {
            $res = Paneld::run('webdisk.list', ['account' => $account->username], 15);
            if ($res === null) {
                $error = 'Web Disk list agent se nahi mili (task fail/timeout) — agent logs dekhein.';
            } else {
                $accounts = is_array($res['accounts'] ?? null) ? $res['accounts'] : [];
            }
        } else {
            $error = 'Agent par webdisk tasks available nahi (agent update zaroori hai).';
        }

        return view('webdisk.index', [
            'account'  => $account,
            'accounts' => $accounts,
            'error'    => $error,
            'realm'    => 'AlphaCP-WebDisk',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'login'       => 'required|string|regex:/^[a-z0-9._-]+$/i|max:60',
            'permissions' => 'required|in:ro,rw',
            'password'    => 'required|string|min:8|max:128',
        ]);

        $account = $this->accountFor($request);
        if ($account === null || !in_array('webdisk.create', Paneld::taskTypes(), true)) {
            return back()->with('error', 'Web Disk provisioning ke liye agent update zaroori hai.')->withInput();
        }

        $res = Paneld::run('webdisk.create', [
            'account'     => $account->username,
            'login'       => $data['login'],
            'permissions' => $data['permissions'],
            'password'    => $data['password'],
        ], 30);
        if ($res === null) {
            return back()->with('error', 'Web Disk account agent par create/reset nahi hua (task fail).')->withInput();
        }

        WebDiskAccount::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'login' => $data['login']],
            ['permissions' => $data['permissions']],
        );

        return redirect('/webdisk')->with('success', "Web Disk account '{$data['login']}' provision ho gaya.");
    }

    public function destroy(string $login, Request $request): RedirectResponse
    {
        $account = $this->accountFor($request);
        if ($account === null || !in_array('webdisk.delete', Paneld::taskTypes(), true)) {
            return back()->with('error', 'Web Disk provisioning ke liye agent update zaroori hai.');
        }

        $res = Paneld::run('webdisk.delete', [
            'account' => $account->username,
            'login'   => $login,
        ], 30);
        if ($res === null) {
            return back()->with('error', "Web Disk account '{$login}' agent se delete nahi hua (task fail).");
        }

        WebDiskAccount::query()
            ->where('user_id', $request->user()->id)
            ->where('login', $login)
            ->delete();

        return redirect('/webdisk')->with('success', "Web Disk account '{$login}' remove ho gaya.");
    }

    private function accountFor(Request $request): ?\App\Models\Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount;
    }
}
PHPEOF
  cat > "${PANEL}/routes/web.php" <<'PHPEOF'
<?php

declare(strict_types=1);

use App\Http\Controllers\AccountsController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\PackagesController;
use App\Http\Controllers\Auth\EntryLoginController as LoginController; // ACP-ENTRY-GATE
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\CronController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DomainsController;
use App\Http\Controllers\ErrorPagesController;
use App\Http\Controllers\AutorespondersController;
use App\Http\Controllers\DefaultAddressController;
use App\Http\Controllers\DeliverabilityController;
use App\Http\Controllers\EmailFiltersController;
use App\Http\Controllers\ForwardersController;
use App\Http\Controllers\EmailRoutingController;
use App\Http\Controllers\TrackDeliveryController;
use App\Http\Controllers\GlobalFiltersController;
use App\Http\Controllers\AddressImporterController;
use App\Http\Controllers\EncryptionController;
use App\Http\Controllers\BoxTrapperController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\EmailDiskUsageController;
use App\Http\Controllers\WebmailController;
use App\Http\Controllers\MysqlDatabasesController;
use App\Http\Controllers\MysqlUsersController;
use App\Http\Controllers\MysqlWizardController;
use App\Http\Controllers\PhpmyadminController;
use App\Http\Controllers\RemoteMysqlController;
use App\Http\Controllers\ZoneEditorController;
use App\Http\Controllers\DynamicDnsController;
use App\Http\Controllers\TrackDnsController;
use App\Http\Controllers\DnsZonesController;
use App\Http\Controllers\HostnameAController;
use App\Http\Controllers\ZoneTemplatesController;
use App\Http\Controllers\GlobalEmailRoutingController;
use App\Http\Controllers\NsReportController;
use App\Http\Controllers\ParkDomainController;
use App\Http\Controllers\DnsCleanupController;
use App\Http\Controllers\ZoneTtlController;
use App\Http\Controllers\DomainForwardController;
use App\Http\Controllers\DnsSyncController;
use App\Http\Controllers\BackupConfigController;
use App\Http\Controllers\BackupDestinationController;
use App\Http\Controllers\BackupRestorationController;
use App\Http\Controllers\BackupUserSelectionController;
use App\Http\Controllers\FileDirectoryRestorationController;
use App\Http\Controllers\TransferRestoreController;
use App\Http\Controllers\TransferToolController;
use App\Http\Controllers\TransferReviewController;
use App\Http\Controllers\NameserverSelectionController;
use App\Http\Controllers\MailingListsController;
use App\Http\Controllers\SpamFiltersController;
use App\Http\Controllers\MailController;
use App\Http\Controllers\IndexesController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BackupWizardController;
use App\Http\Controllers\FileRestorationController;
use App\Http\Controllers\DiskUsageController;
use App\Http\Controllers\FilesController;
use App\Http\Controllers\HandlersController;
use App\Http\Controllers\MimeTypesController;
use App\Http\Controllers\PhpController;
use App\Http\Controllers\PhpIniController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\SshController;
use App\Http\Controllers\SslController;
use App\Http\Controllers\LicenseController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\UsersController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AlphaCP panel routes
|--------------------------------------------------------------------------
| Contract for future edits (any AI/dev):
|   * Every privileged action lives behind `auth` + `2fa` + `password.fresh`.
|   * Permission checks live in the `perm:` middleware, never in views.
|   * Module routes keep the module prefix (users.*, system.*, audit.* …) so
|   * Step 3+ modules can be dropped in without touching what exists here.
|   * Anything that changes state MUST write to the audit log.
*/

// ---------------------------------------------------------------------------
// Guest
// ---------------------------------------------------------------------------
Route::middleware('guest')->group(function (): void {
    Route::get('/', [LoginController::class, 'show'])->name('login');
    // /login bhi wahi login page — bookmark/WHMCS/cPanel aadat. Pehle 404 deta tha.
    Route::get('/login', [LoginController::class, 'show'])->name('login.page');
    Route::post('/login', [LoginController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login.attempt');
});

// ---------------------------------------------------------------------------
// Authenticated (2FA challenge happens before anything else)
// ---------------------------------------------------------------------------
Route::middleware('auth')->group(function (): void {
    Route::get('/two-factor', [TwoFactorController::class, 'challenge'])->name('twofactor.challenge');
    Route::post('/two-factor', [TwoFactorController::class, 'verify'])
        ->middleware('throttle:10,1')
        ->name('twofactor.verify');

    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
});

// ---------------------------------------------------------------------------
// Panel (auth + 2FA verified + fresh password)
// ---------------------------------------------------------------------------
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/domains', [DomainsController::class, 'index'])
        ->middleware('perm:domains.view')->name('domains.index');
    Route::post('/domains', [DomainsController::class, 'store'])
        ->middleware('perm:domains.manage')->name('domains.store');
    Route::delete('/domains/{domain}', [DomainsController::class, 'destroy'])
        ->middleware('perm:domains.manage')->name('domains.destroy');

    Route::get('/php', [PhpController::class, 'index'])
        ->middleware('perm:software.view')->name('php.index');
    Route::post('/php', [PhpController::class, 'update'])
        ->middleware('perm:software.manage')->name('php.update');
    Route::get('/php/ini', [PhpIniController::class, 'index'])
        ->middleware('perm:software.view')->name('php.ini');
    Route::post('/php/ini', [PhpIniController::class, 'update'])
        ->middleware('perm:software.manage')->name('php.ini.update');

    Route::get('/errorpages', [ErrorPagesController::class, 'index'])
        ->middleware('perm:errorpages.view')->name('errorpages.index');
    Route::post('/errorpages', [ErrorPagesController::class, 'update'])
        ->middleware('perm:errorpages.manage')->name('errorpages.update');

    Route::get('/indexes', [IndexesController::class, 'index'])
        ->middleware('perm:indexes.view')->name('indexes.index');
    Route::post('/indexes', [IndexesController::class, 'update'])
        ->middleware('perm:indexes.manage')->name('indexes.update');

    Route::get('/mime', [MimeTypesController::class, 'index'])
        ->middleware('perm:mime.view')->name('mime.index');
    Route::post('/mime', [MimeTypesController::class, 'store'])
        ->middleware('perm:mime.manage')->name('mime.store');
    Route::delete('/mime/{ext}', [MimeTypesController::class, 'destroy'])
        ->middleware('perm:mime.manage')->where('ext', '[A-Za-z0-9]{1,16}')->name('mime.destroy');

    Route::get('/handlers', [HandlersController::class, 'index'])
        ->middleware('perm:handlers.view')->name('handlers.index');
    Route::post('/handlers', [HandlersController::class, 'store'])
        ->middleware('perm:handlers.manage')->name('handlers.store');
    Route::delete('/handlers/{ext}', [HandlersController::class, 'destroy'])
        ->middleware('perm:handlers.manage')->where('ext', '[A-Za-z0-9]{1,16}')->name('handlers.destroy');

    Route::get('/files', [FilesController::class, 'index'])
        ->middleware('perm:files.view')->name('files.index');
    Route::post('/files/mkdir', [FilesController::class, 'mkdir'])
        ->middleware('perm:files.manage')->name('files.mkdir');
    Route::post('/files/write', [FilesController::class, 'write'])
        ->middleware('perm:files.manage')->name('files.write');
    Route::post('/files/rename', [FilesController::class, 'rename'])
        ->middleware('perm:files.manage')->name('files.rename');
    Route::post('/files/delete', [FilesController::class, 'destroy'])
        ->middleware('perm:files.manage')->name('files.destroy');

    Route::get('/disk', [DiskUsageController::class, 'index'])
        ->middleware('perm:files.view')->name('disk.index');

    Route::get('/backup', [BackupController::class, 'index'])
        ->middleware('perm:files.view')->name('backup.index');
    Route::post('/backup', [BackupController::class, 'store'])
        ->middleware('perm:files.manage')->name('backup.store');
    Route::post('/backup/archive', [BackupController::class, 'archive'])
        ->middleware('perm:files.manage')->name('backup.archive');
    Route::get('/backup/archive/{archiveId}/download', [BackupController::class, 'download'])
        ->middleware('perm:files.view')->name('backup.archive-download');
    Route::post('/backup/restore', [BackupController::class, 'restore'])
        ->middleware('perm:files.manage')->name('backup.restore-archive');

    Route::get('/backup-wizard', [BackupWizardController::class, 'index'])
        ->middleware('perm:files.view')->name('backup-wizard.index');
    Route::post('/backup-wizard', [BackupWizardController::class, 'store'])
        ->middleware('perm:files.manage')->name('backup-wizard.store');

    Route::get('/file-restoration', [FileRestorationController::class, 'index'])
        ->middleware('perm:files.view')->name('file-restoration.index');
    Route::post('/file-restoration', [FileRestorationController::class, 'store'])
        ->middleware('perm:files.manage')->name('file-restoration.store');

    Route::get('/email', [MailController::class, 'index'])
        ->middleware('perm:email.view')->name('email.index');
    Route::post('/email', [MailController::class, 'store'])
        ->middleware('perm:email.manage')->name('email.store');
    Route::delete('/email/{mailbox}', [MailController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('email.destroy');

    Route::get('/forwarders', [ForwardersController::class, 'index'])
        ->middleware('perm:email.view')->name('forwarders.index');
    Route::post('/forwarders', [ForwardersController::class, 'store'])
        ->middleware('perm:email.manage')->name('forwarders.store');
    Route::delete('/forwarders/{forwarder}', [ForwardersController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('forwarders.destroy');

    Route::get('/autoresponders', [AutorespondersController::class, 'index'])
        ->middleware('perm:email.view')->name('autoresponders.index');
    Route::post('/autoresponders', [AutorespondersController::class, 'store'])
        ->middleware('perm:email.manage')->name('autoresponders.store');
    Route::delete('/autoresponders/{autoresponder}', [AutorespondersController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('autoresponders.destroy');

    Route::get('/default-address', [DefaultAddressController::class, 'index'])
        ->middleware('perm:email.view')->name('default-address.index');
    Route::post('/default-address', [DefaultAddressController::class, 'store'])
        ->middleware('perm:email.manage')->name('default-address.store');
    Route::delete('/default-address/{catchall}', [DefaultAddressController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('default-address.destroy');

    Route::get('/email-filters', [EmailFiltersController::class, 'index'])
        ->middleware('perm:email.view')->name('email-filters.index');
    Route::post('/email-filters', [EmailFiltersController::class, 'store'])
        ->middleware('perm:email.manage')->name('email-filters.store');
    Route::delete('/email-filters/{filter}', [EmailFiltersController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('email-filters.destroy');

    Route::get('/deliverability', [DeliverabilityController::class, 'index'])
        ->middleware('perm:email.view')->name('deliverability.index');
    Route::post('/deliverability', [DeliverabilityController::class, 'store'])
        ->middleware('perm:email.manage')->name('deliverability.store');

    Route::get('/spam-filters', [SpamFiltersController::class, 'index'])
        ->middleware('perm:email.view')->name('spam-filters.index');
    Route::post('/spam-filters', [SpamFiltersController::class, 'store'])
        ->middleware('perm:email.manage')->name('spam-filters.store');

    Route::get('/mailing-lists', [MailingListsController::class, 'index'])
        ->middleware('perm:email.view')->name('mailing-lists.index');
    Route::post('/mailing-lists', [MailingListsController::class, 'store'])
        ->middleware('perm:email.manage')->name('mailing-lists.store');
    Route::patch('/mailing-lists/{mailing_list}', [MailingListsController::class, 'update'])
        ->middleware('perm:email.manage')->name('mailing-lists.update');
    Route::delete('/mailing-lists/{mailing_list}', [MailingListsController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('mailing-lists.destroy');

    Route::get('/email-routing', [EmailRoutingController::class, 'index'])
        ->middleware('perm:email.view')->name('email-routing.index');
    Route::post('/email-routing', [EmailRoutingController::class, 'store'])
        ->middleware('perm:email.manage')->name('email-routing.store');

    Route::get('/track-delivery', [TrackDeliveryController::class, 'index'])
        ->middleware('perm:email.view')->name('track-delivery.index');
    Route::post('/track-delivery', [TrackDeliveryController::class, 'store'])
        ->middleware('perm:email.manage')->name('track-delivery.store');

    Route::get('/global-filters', [GlobalFiltersController::class, 'index'])
        ->middleware('perm:email.view')->name('global-filters.index');
    Route::post('/global-filters', [GlobalFiltersController::class, 'store'])
        ->middleware('perm:email.manage')->name('global-filters.store');
    Route::delete('/global-filters/{global_filter}', [GlobalFiltersController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('global-filters.destroy');

    Route::get('/address-importer', [AddressImporterController::class, 'index'])
        ->middleware('perm:email.view')->name('address-importer.index');
    Route::post('/address-importer', [AddressImporterController::class, 'store'])
        ->middleware('perm:email.manage')->name('address-importer.store');

    Route::get('/encryption', [EncryptionController::class, 'index'])
        ->middleware('perm:email.view')->name('encryption.index');
    Route::post('/encryption', [EncryptionController::class, 'store'])
        ->middleware('perm:email.manage')->name('encryption.store');
    Route::delete('/encryption/{encryption_key}', [EncryptionController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('encryption.destroy');

    Route::get('/boxtrapper', [BoxTrapperController::class, 'index'])
        ->middleware('perm:email.view')->name('boxtrapper.index');
    Route::post('/boxtrapper', [BoxTrapperController::class, 'store'])
        ->middleware('perm:email.manage')->name('boxtrapper.store');

    Route::get('/calendar', [CalendarController::class, 'index'])
        ->middleware('perm:email.view')->name('calendar.index');
    Route::post('/calendar', [CalendarController::class, 'store'])
        ->middleware('perm:email.manage')->name('calendar.store');
    Route::delete('/calendar/{calendar_item}', [CalendarController::class, 'destroy'])
        ->middleware('perm:email.manage')->name('calendar.destroy');

    Route::get('/email-disk', [EmailDiskUsageController::class, 'index'])
        ->middleware('perm:email.view')->name('email-disk.index');

    Route::get('/webmail', [WebmailController::class, 'index'])
        ->middleware('perm:email.view')->name('webmail.index');
    Route::post('/webmail', [WebmailController::class, 'store'])
        ->middleware('perm:email.manage')->name('webmail.store');

    Route::get('/mysql', [MysqlDatabasesController::class, 'index'])
        ->middleware('perm:databases.view')->name('mysql.index');
    Route::post('/mysql', [MysqlDatabasesController::class, 'store'])
        ->middleware('perm:databases.manage')->name('mysql.store');
    Route::delete('/mysql/{mysql_database}', [MysqlDatabasesController::class, 'destroy'])
        ->middleware('perm:databases.manage')->name('mysql.destroy');

    Route::get('/mysql-users', [MysqlUsersController::class, 'index'])
        ->middleware('perm:databases.view')->name('mysql-users.index');
    Route::post('/mysql-users', [MysqlUsersController::class, 'store'])
        ->middleware('perm:databases.manage')->name('mysql-users.store');
    Route::post('/mysql-users/grant', [MysqlUsersController::class, 'grant'])
        ->middleware('perm:databases.manage')->name('mysql-users.grant');
    Route::post('/mysql-users/{mysql_user}/password', [MysqlUsersController::class, 'password'])
        ->middleware('perm:databases.manage')->name('mysql-users.password');
    Route::delete('/mysql-users/{mysql_user}', [MysqlUsersController::class, 'destroy'])
        ->middleware('perm:databases.manage')->name('mysql-users.destroy');

    Route::get('/mysql-wizard', [MysqlWizardController::class, 'index'])
        ->middleware('perm:databases.view')->name('mysql-wizard.index');
    Route::post('/mysql-wizard', [MysqlWizardController::class, 'store'])
        ->middleware('perm:databases.manage')->name('mysql-wizard.store');

    Route::get('/phpmyadmin', [PhpmyadminController::class, 'index'])
        ->middleware('perm:databases.view')->name('phpmyadmin.index');
    Route::post('/phpmyadmin', [PhpmyadminController::class, 'store'])
        ->middleware('perm:databases.manage')->name('phpmyadmin.store');

    Route::get('/remote-mysql', [RemoteMysqlController::class, 'index'])
        ->middleware('perm:databases.view')->name('remote-mysql.index');
    Route::post('/remote-mysql', [RemoteMysqlController::class, 'store'])
        ->middleware('perm:databases.manage')->name('remote-mysql.store');
    Route::delete('/remote-mysql/{mysql_remote_host}', [RemoteMysqlController::class, 'destroy'])
        ->middleware('perm:databases.manage')->name('remote-mysql.destroy');

    Route::get('/zone-editor', [ZoneEditorController::class, 'index'])
        ->middleware('perm:dns.view')->name('zone-editor.index');
    Route::post('/zone-editor', [ZoneEditorController::class, 'store'])
        ->middleware('perm:dns.manage')->name('zone-editor.store');
    Route::delete('/zone-editor/{dns_record}', [ZoneEditorController::class, 'destroy'])
        ->middleware('perm:dns.manage')->name('zone-editor.destroy');

    Route::get('/dynamic-dns', [DynamicDnsController::class, 'index'])
        ->middleware('perm:dns.view')->name('dynamic-dns.index');
    Route::post('/dynamic-dns', [DynamicDnsController::class, 'store'])
        ->middleware('perm:dns.manage')->name('dynamic-dns.store');
    Route::delete('/dynamic-dns/{dns_dynamic_host}', [DynamicDnsController::class, 'destroy'])
        ->middleware('perm:dns.manage')->name('dynamic-dns.destroy');

    Route::get('/track-dns', [TrackDnsController::class, 'index'])
        ->middleware('perm:dns.view')->name('track-dns.index');
    Route::post('/track-dns', [TrackDnsController::class, 'store'])
        ->middleware('perm:dns.view')->name('track-dns.store');

    Route::get('/dns-zones', [DnsZonesController::class, 'index'])
        ->middleware('perm:accounts.view')->name('dns-zones.index');
    Route::post('/dns-zones', [DnsZonesController::class, 'store'])
        ->middleware('perm:accounts.view')->name('dns-zones.store');
    Route::delete('/dns-zones', [DnsZonesController::class, 'destroy'])
        ->middleware('perm:accounts.view')->name('dns-zones.destroy');
    Route::post('/dns-zones/{account}/sync', [DnsZonesController::class, 'sync'])
        ->middleware('perm:accounts.view')->name('dns-zones.sync');

    Route::get('/hostname-a', [HostnameAController::class, 'index'])
        ->middleware('perm:accounts.view')->name('hostname-a.index');
    Route::post('/hostname-a', [HostnameAController::class, 'store'])
        ->middleware('perm:accounts.view')->name('hostname-a.store');

    Route::get('/zone-templates', [ZoneTemplatesController::class, 'index'])
        ->middleware('perm:accounts.view')->name('zone-templates.index');
    Route::post('/zone-templates', [ZoneTemplatesController::class, 'store'])
        ->middleware('perm:accounts.view')->name('zone-templates.store');
    Route::delete('/zone-templates/{dns_template}', [ZoneTemplatesController::class, 'destroy'])
        ->middleware('perm:accounts.view')->name('zone-templates.destroy');

    Route::get('/global-email-routing', [GlobalEmailRoutingController::class, 'index'])
        ->middleware('perm:accounts.view')->name('global-email-routing.index');
    Route::post('/global-email-routing', [GlobalEmailRoutingController::class, 'store'])
        ->middleware('perm:accounts.view')->name('global-email-routing.store');

    Route::get('/ns-report', [NsReportController::class, 'index'])
        ->middleware('perm:accounts.view')->name('ns-report.index');
    Route::post('/ns-report', [NsReportController::class, 'store'])
        ->middleware('perm:accounts.view')->name('ns-report.store');

    Route::get('/park-domain', [ParkDomainController::class, 'index'])
        ->middleware('perm:accounts.view')->name('park-domain.index');
    Route::post('/park-domain', [ParkDomainController::class, 'store'])
        ->middleware('perm:accounts.view')->name('park-domain.store');

    Route::get('/dns-cleanup', [DnsCleanupController::class, 'index'])
        ->middleware('perm:accounts.view')->name('dns-cleanup.index');
    Route::post('/dns-cleanup', [DnsCleanupController::class, 'store'])
        ->middleware('perm:accounts.view')->name('dns-cleanup.store');

    Route::get('/zone-ttl', [ZoneTtlController::class, 'index'])
        ->middleware('perm:accounts.view')->name('zone-ttl.index');
    Route::post('/zone-ttl', [ZoneTtlController::class, 'store'])
        ->middleware('perm:accounts.view')->name('zone-ttl.store');

    Route::get('/domain-forward', [DomainForwardController::class, 'index'])
        ->middleware('perm:accounts.view')->name('domain-forward.index');
    Route::post('/domain-forward', [DomainForwardController::class, 'store'])
        ->middleware('perm:accounts.view')->name('domain-forward.store');

    Route::get('/dns-sync', [DnsSyncController::class, 'index'])
        ->middleware('perm:accounts.view')->name('dns-sync.index');
    Route::post('/dns-sync', [DnsSyncController::class, 'store'])
        ->middleware('perm:accounts.view')->name('dns-sync.store');

    Route::get('/nameserver-selection', [NameserverSelectionController::class, 'index'])
        ->middleware('perm:accounts.view')->name('nameserver-selection.index');
    Route::post('/nameserver-selection', [NameserverSelectionController::class, 'store'])
        ->middleware('perm:accounts.view')->name('nameserver-selection.store');

    Route::get('/backup-config', [BackupConfigController::class, 'index'])
        ->middleware('perm:accounts.view')->name('backup-config.index');
    Route::post('/backup-config', [BackupConfigController::class, 'store'])
        ->middleware('perm:accounts.view')->name('backup-config.store');

    Route::get('/backup-destinations', [BackupDestinationController::class, 'index'])
        ->middleware('perm:accounts.view')->name('backup-destinations.index');
    Route::post('/backup-destinations', [BackupDestinationController::class, 'store'])
        ->middleware('perm:accounts.manage')->name('backup-destinations.store');
    Route::post('/backup-destinations/test', [BackupDestinationController::class, 'test'])
        ->middleware('perm:accounts.manage')->name('backup-destinations.test');
    Route::post('/backup-destinations/push', [BackupDestinationController::class, 'push'])
        ->middleware('perm:accounts.manage')->name('backup-destinations.push');
    Route::post('/backup-destinations/browse', [BackupDestinationController::class, 'browse'])
        ->middleware('perm:accounts.view')->name('backup-destinations.browse');
    Route::delete('/backup-destinations/{name}', [BackupDestinationController::class, 'destroy'])
        ->middleware('perm:accounts.manage')->name('backup-destinations.destroy');

    Route::get('/backup-restoration', [BackupRestorationController::class, 'index'])
        ->middleware('perm:accounts.view')->name('backup-restoration.index');
    Route::post('/backup-restoration', [BackupRestorationController::class, 'store'])
        ->middleware('perm:accounts.view')->name('backup-restoration.store');

    Route::get('/backup-user-selection', [BackupUserSelectionController::class, 'index'])
        ->middleware('perm:accounts.view')->name('backup-user-selection.index');
    Route::post('/backup-user-selection', [BackupUserSelectionController::class, 'store'])
        ->middleware('perm:accounts.view')->name('backup-user-selection.store');

    Route::get('/file-directory-restoration', [FileDirectoryRestorationController::class, 'index'])
        ->middleware('perm:accounts.view')->name('file-directory-restoration.index');
    Route::post('/file-directory-restoration', [FileDirectoryRestorationController::class, 'store'])
        ->middleware('perm:accounts.view')->name('file-directory-restoration.store');

    Route::get('/transfer-tool', [TransferToolController::class, 'index'])
        ->middleware('perm:accounts.view')->name('transfer-tool.index');
    Route::post('/transfer-tool', [TransferToolController::class, 'store'])
        ->middleware('perm:accounts.view')->name('transfer-tool.store');
    // S10 remote pull: 1) host key fingerprint lao (probe) 2) archive lao (pull)
    Route::post('/transfer-tool/probe', [TransferToolController::class, 'probe'])
        ->middleware('perm:accounts.view')->name('transfer-tool.probe');
    Route::post('/transfer-tool/pull', [TransferToolController::class, 'pull'])
        ->middleware('perm:accounts.view')->name('transfer-tool.pull');

    Route::get('/transfer-restore', [TransferRestoreController::class, 'index'])
        ->middleware('perm:accounts.view')->name('transfer-restore.index');
    Route::post('/transfer-restore', [TransferRestoreController::class, 'store'])
        ->middleware('perm:accounts.view')->name('transfer-restore.store');

    Route::get('/transfer-review', [TransferReviewController::class, 'index'])
        ->middleware('perm:accounts.view')->name('transfer-review.index');
    Route::post('/transfer-review', [TransferReviewController::class, 'store'])
        ->middleware('perm:accounts.view')->name('transfer-review.store');

    Route::get('/ssh', [SshController::class, 'index'])
        ->middleware('perm:ssh.view')->name('ssh.index');
    Route::post('/ssh', [SshController::class, 'store'])
        ->middleware('perm:ssh.manage')->name('ssh.store');
    Route::post('/ssh/delete', [SshController::class, 'destroy'])
        ->middleware('perm:ssh.manage')->name('ssh.destroy');
    Route::post('/ssh/shell', [SshController::class, 'shell'])
        ->middleware('perm:ssh.manage')->name('ssh.shell');

    Route::get('/privacy', [PrivacyController::class, 'index'])
        ->middleware('perm:privacy.view')->name('privacy.index');
    Route::post('/privacy', [PrivacyController::class, 'store'])
        ->middleware('perm:privacy.manage')->name('privacy.store');
    Route::post('/privacy/delete', [PrivacyController::class, 'destroy'])
        ->middleware('perm:privacy.manage')->name('privacy.destroy');

    Route::get('/cron', [CronController::class, 'index'])
        ->middleware('perm:cron.view')->name('cron.index');
    Route::post('/cron', [CronController::class, 'store'])
        ->middleware('perm:cron.manage')->name('cron.store');
    Route::delete('/cron/{cron}', [CronController::class, 'destroy'])
        ->middleware('perm:cron.manage')->name('cron.destroy');

    Route::get('/ssl', [SslController::class, 'index'])
        ->middleware('perm:ssl.view')->name('ssl.index');
    Route::post('/ssl/autossl', [SslController::class, 'autossl'])
        ->middleware('perm:ssl.manage')->name('ssl.autossl');
    Route::post('/ssl/{domain}', [SslController::class, 'issue'])
        ->middleware('perm:ssl.manage')->name('ssl.issue');
    Route::post('/ssl/{domain}/autossl', [SslController::class, 'toggle'])
        ->middleware('perm:ssl.manage')->name('ssl.toggle');
    Route::delete('/ssl/{domain}', [SslController::class, 'destroy'])
        ->middleware('perm:ssl.manage')->name('ssl.destroy');

    // -- Security (always available to the logged-in user) -------------------
    Route::prefix('security')->name('security.')->group(function (): void {
        Route::get('/', [SecurityController::class, 'index'])->name('index');
        Route::post('/2fa/start', [SecurityController::class, 'startTwoFactor'])->name('2fa.start');
        Route::post('/2fa/confirm', [SecurityController::class, 'confirmTwoFactor'])->name('2fa.confirm');
        Route::post('/2fa/disable', [SecurityController::class, 'disableTwoFactor'])->name('2fa.disable');
        Route::get('/password', [SecurityController::class, 'password'])->name('password');
        Route::post('/password', [SecurityController::class, 'updatePassword'])->name('password.update');
        Route::get('/sessions', [SecurityController::class, 'sessions'])->name('sessions');
        Route::delete('/sessions/{id}', [SecurityController::class, 'destroySession'])->name('sessions.destroy');
    });

    // -- Hosting accounts (Step 3). /create MUST sit before /{account}.
    Route::get('/accounts', [AccountsController::class, 'index'])
        ->middleware('perm:accounts.view')->name('accounts.index');
    Route::get('/accounts/create', [AccountsController::class, 'create'])
        ->middleware('perm:accounts.create')->name('accounts.create');
    Route::post('/accounts', [AccountsController::class, 'store'])
        ->middleware('perm:accounts.create')->name('accounts.store');
    Route::get('/accounts/{account}', [AccountsController::class, 'show'])
        ->middleware('perm:accounts.view')->name('accounts.show');
    Route::post('/accounts/{account}/suspend', [AccountsController::class, 'suspend'])
        ->middleware('perm:accounts.suspend')->name('accounts.suspend');
    Route::post('/accounts/{account}/unsuspend', [AccountsController::class, 'unsuspend'])
        ->middleware('perm:accounts.suspend')->name('accounts.unsuspend');
    Route::post('/accounts/{account}/terminate', [AccountsController::class, 'terminate'])
        ->middleware('perm:accounts.terminate')->name('accounts.terminate');
    Route::post('/accounts/{account}/upgrade', [AccountsController::class, 'upgrade'])
        ->middleware('perm:accounts.modify')->name('accounts.upgrade');
    Route::post('/accounts/{account}/quota', [AccountsController::class, 'quota'])
        ->middleware('perm:accounts.modify')->name('accounts.quota');
    Route::post('/accounts/{account}/php', [AccountsController::class, 'php'])
        ->middleware('perm:accounts.modify')->name('accounts.php');

    Route::get('/packages', [PackagesController::class, 'index'])
        ->middleware('perm:packages.view')->name('packages.index');
    Route::middleware('perm:packages.manage')->group(function (): void {
        Route::get('/packages/create', [PackagesController::class, 'create'])->name('packages.create');
        Route::post('/packages', [PackagesController::class, 'store'])->name('packages.store');
        Route::get('/packages/{package}/edit', [PackagesController::class, 'edit'])->name('packages.edit');
        Route::put('/packages/{package}', [PackagesController::class, 'update'])->name('packages.update');
        Route::post('/packages/{package}/archive', [PackagesController::class, 'archive'])->name('packages.archive');
    });

    // -- Users (panel logins) -------------------------------------------------
    Route::middleware('perm:users.view')->group(function (): void {
        Route::get('/users', [UsersController::class, 'index'])->name('users.index');
    });
    Route::middleware('perm:users.manage')->group(function (): void {
        Route::get('/users/create', [UsersController::class, 'create'])->name('users.create');
        Route::post('/users', [UsersController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [UsersController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UsersController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/password', [UsersController::class, 'resetPassword'])->name('users.password');
    });

    // -- Audit -----------------------------------------------------------------
    Route::get('/audit', [AuditController::class, 'index'])
        ->middleware('perm:audit.view')->name('audit.index');

    // -- License / trial (admin) -----------------------------------------------
    Route::prefix('license')->name('license.')->middleware('perm:license.view')->group(function (): void {
        Route::get('/', [LicenseController::class, 'index'])->name('index');
        Route::post('/activate', [LicenseController::class, 'activate'])
            ->middleware('perm:license.manage')->name('activate');
    });

    // -- Server (admin) ----------------------------------------------------------
    Route::prefix('system')->name('system.')->middleware('perm:system.view')->group(function (): void {
        Route::get('/', [SystemController::class, 'index'])->name('index');
        Route::get('/services', [SystemController::class, 'services'])->name('services');
        Route::get('/tasks', [SystemController::class, 'tasks'])->name('tasks');
        Route::post('/tasks/run', [SystemController::class, 'runTask'])
            ->middleware('perm:system.manage')->name('tasks.run');
    });
});

// Fallback: unknown panel URLs get a clean 404 page, not a stack trace.
Route::fallback(fn () => response()->view('errors.404', [], 404));
// ---- FTP Accounts (portable feature: pure-ftpd) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/ftp', [\App\Http\Controllers\FtpController::class, 'index'])
        ->middleware('perm:files.view')->name('ftp.index');
    Route::post('/ftp', [\App\Http\Controllers\FtpController::class, 'store'])
        ->middleware('perm:files.manage')->name('ftp.store');
    Route::post('/ftp/{ftpAccount}/password', [\App\Http\Controllers\FtpController::class, 'password'])
        ->middleware('perm:files.manage')->name('ftp.password');
    Route::delete('/ftp/{ftpAccount}', [\App\Http\Controllers\FtpController::class, 'destroy'])
        ->middleware('perm:files.manage')->name('ftp.destroy');
});
// ---- /FTP ----
// ---- Metrics (portable feature: access-log stats) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/metrics', [\App\Http\Controllers\MetricsController::class, 'index'])
        ->middleware('perm:metrics.view')->name('metrics.index');
});
// ---- /Metrics ----
// ---- IP Blocker (portable feature: ufw/iptables deny) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/ip-blocker', [\App\Http\Controllers\IpBlockerController::class, 'index'])
        ->middleware('perm:security.view')->name('ip-blocker.index');
    Route::post('/ip-blocker', [\App\Http\Controllers\IpBlockerController::class, 'store'])
        ->middleware('perm:security.view')->name('ip-blocker.store');
    Route::delete('/ip-blocker/{blockedIp}', [\App\Http\Controllers\IpBlockerController::class, 'destroy'])
        ->middleware('perm:security.view')->name('ip-blocker.destroy');
});
// ---- /IP Blocker ----
// ---- Security Tools (ModSecurity WAF + Virus Scanner) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/security-tools', [\App\Http\Controllers\SecurityToolsController::class, 'index'])
        ->middleware('perm:security.view')->name('security-tools.index');
    Route::post('/security-tools/modsec', [\App\Http\Controllers\SecurityToolsController::class, 'toggleModsec'])
        ->middleware('perm:security.view')->name('security-tools.modsec');
    Route::post('/security-tools/scan', [\App\Http\Controllers\SecurityToolsController::class, 'scan'])
        ->middleware('perm:security.view')->name('security-tools.scan');
});
// ---- /Security Tools ----
// ---- Site Software / App Installer (WordPress one-click) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/apps', [\App\Http\Controllers\AppsController::class, 'index'])
        ->middleware('perm:software.view')->name('apps.index');
    Route::post('/apps', [\App\Http\Controllers\AppsController::class, 'store'])
        ->middleware('perm:software.manage')->name('apps.store');
});
// ---- /Site Software ----
// ---- Monitoring / Resource Usage ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/monitoring', [\App\Http\Controllers\MonitoringController::class, 'index'])
        ->middleware('perm:metrics.view')->name('monitoring.index');
});
// ---- /Monitoring ----
// ---- WHM API 1 compatible (billing integration, Bearer token) ----
Route::prefix('json-api')->middleware([\App\Http\Middleware\EnsureApiToken::class])->group(function (): void {
    Route::get('/listaccts', [\App\Http\Controllers\WhmApiController::class, 'listaccts']);
    Route::get('/accountsummary', [\App\Http\Controllers\WhmApiController::class, 'accountsummary']);
    Route::post('/createacct', [\App\Http\Controllers\WhmApiController::class, 'createacct']);
    Route::get('/suspendacct', [\App\Http\Controllers\WhmApiController::class, 'suspendacct']);
    Route::get('/unsuspendacct', [\App\Http\Controllers\WhmApiController::class, 'unsuspendacct']);
    Route::get('/removeacct', [\App\Http\Controllers\WhmApiController::class, 'removeacct']);
});
// ---- /WHM API ----
// ---- Manage API Tokens (panel UI) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/api-tokens', [\App\Http\Controllers\ApiTokensController::class, 'index'])
        ->middleware('perm:api.view')->name('api-tokens.index');
    Route::post('/api-tokens', [\App\Http\Controllers\ApiTokensController::class, 'store'])
        ->middleware('perm:api.manage')->name('api-tokens.store');
    Route::delete('/api-tokens/{apiToken}', [\App\Http\Controllers\ApiTokensController::class, 'destroy'])
        ->middleware('perm:api.manage')->name('api-tokens.destroy');
});
// ---- /API Tokens ----

// ---- Reseller Center (WHM-style) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/resellers', [\App\Http\Controllers\ResellersController::class, 'index'])
        ->middleware('perm:users.view')->name('resellers.index');
    Route::post('/resellers', [\App\Http\Controllers\ResellersController::class, 'store'])
        ->middleware('perm:roles.manage')->name('resellers.store');
    Route::post('/resellers/privileges', [\App\Http\Controllers\ResellersController::class, 'updatePrivileges'])
        ->middleware('perm:roles.manage')->name('resellers.privileges');
    Route::delete('/resellers/{user}', [\App\Http\Controllers\ResellersController::class, 'destroy'])
        ->middleware('perm:roles.manage')->name('resellers.destroy');
});
// ---- /Reseller Center ----

// ---- G5: Git Version Control + Terminal ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/git', [\App\Http\Controllers\GitController::class, 'index'])
        ->middleware('perm:files.view')->name('git.index');
    Route::post('/git/clone', [\App\Http\Controllers\GitController::class, 'clone'])
        ->middleware('perm:files.manage')->name('git.clone');
    Route::get('/git/status/{dir}', [\App\Http\Controllers\GitController::class, 'status'])
        ->middleware('perm:files.view')->name('git.status');
    Route::post('/git/pull/{dir}', [\App\Http\Controllers\GitController::class, 'pull'])
        ->middleware('perm:files.manage')->name('git.pull');

    Route::get('/terminal', [\App\Http\Controllers\TerminalController::class, 'index'])
        ->middleware('perm:system.manage')->name('terminal.index');
    Route::post('/terminal', [\App\Http\Controllers\TerminalController::class, 'run'])
        ->middleware('perm:system.manage')->name('terminal.run');
});
// ---- /G5 ----

// ---- License Server (sellable signed licenses) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/license-server', [\App\Http\Controllers\LicenseServerController::class, 'index'])
        ->middleware('perm:license.manage')->name('license-server.index');
    Route::post('/license-server', [\App\Http\Controllers\LicenseServerController::class, 'store'])
        ->middleware('perm:license.manage')->name('license-server.store');
    Route::delete('/license-server/{licenseKey}', [\App\Http\Controllers\LicenseServerController::class, 'destroy'])
        ->middleware('perm:license.manage')->name('license-server.destroy');
});
// Customer panel ka online verify (public, read-only)
Route::post('/license-server/verify', [\App\Http\Controllers\LicenseServerController::class, 'verify'])
    ->name('license-server.verify');
// ---- /License Server ----

// ---- Security extras: Hotlink + Leech Protection ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/hotlink-protection', [\App\Http\Controllers\SecurityExtrasController::class, 'hotlink'])
        ->middleware('perm:security.view')->name('secextra.hotlink');
    Route::get('/leech-protection', [\App\Http\Controllers\SecurityExtrasController::class, 'leech'])
        ->middleware('perm:security.view')->name('secextra.leech');
    Route::post('/security-extras', [\App\Http\Controllers\SecurityExtrasController::class, 'store'])
        ->middleware('perm:security.manage')->name('secextra.store');
});
// ---- /Security extras ----

// ---- Web Disk (WebDAV accounts) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/webdisk', [\App\Http\Controllers\WebDiskController::class, 'index'])
        ->middleware('perm:files.view')->name('webdisk.index');
    Route::post('/webdisk', [\App\Http\Controllers\WebDiskController::class, 'store'])
        ->middleware('perm:files.manage')->name('webdisk.store');
    Route::delete('/webdisk/{login}', [\App\Http\Controllers\WebDiskController::class, 'destroy'])
        ->where('login', '[A-Za-z0-9._-]+')
        ->middleware('perm:files.manage')->name('webdisk.destroy');
});
// ---- /Web Disk ----

// ---- File extras: Images + Optimize Website + Trash ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/images', [\App\Http\Controllers\ImagesController::class, 'index'])
        ->middleware('perm:files.view')->name('images.index');
    Route::get('/optimize-website', [\App\Http\Controllers\OptimizeController::class, 'index'])
        ->middleware('perm:files.view')->name('optimize.index');
    Route::post('/optimize-website', [\App\Http\Controllers\OptimizeController::class, 'store'])
        ->middleware('perm:files.manage')->name('optimize.store');
    Route::get('/trash', [\App\Http\Controllers\TrashController::class, 'index'])
        ->middleware('perm:files.view')->name('trash.index');
    Route::delete('/trash/{file}', [\App\Http\Controllers\TrashController::class, 'destroy'])
        ->middleware('perm:files.manage')->name('trash.destroy');
});
// ---- /File extras ----

// ---- DNS Cluster (WHM) ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/dns-cluster', [\App\Http\Controllers\DnsClusterController::class, 'index'])
        ->middleware('perm:dns.view')->name('dns-cluster.index');
    Route::post('/dns-cluster', [\App\Http\Controllers\DnsClusterController::class, 'store'])
        ->middleware('perm:dns.manage')->name('dns-cluster.store');
    Route::post('/dns-cluster/sync', [\App\Http\Controllers\DnsClusterController::class, 'sync'])
        ->middleware('perm:dns.manage')->name('dns-cluster.sync');
    Route::delete('/dns-cluster/{dnsClusterNode}', [\App\Http\Controllers\DnsClusterController::class, 'destroy'])
        ->middleware('perm:dns.manage')->name('dns-cluster.destroy');
});
// ---- /DNS Cluster ----

// ---- Owner Ports Config ----
Route::middleware(['auth', '2fa', 'password.fresh'])->group(function (): void {
    Route::get('/ports', [\App\Http\Controllers\PortsController::class, 'index'])
        ->middleware('perm:system.manage')->name('ports.index');
    Route::post('/ports', [\App\Http\Controllers\PortsController::class, 'store'])
        ->middleware('perm:system.manage')->name('ports.store');
});
// ---- /Ports ----
PHPEOF
  mkdir -p "${PANEL}/resources/views/webdisk"
  cat > "${PANEL}/resources/views/webdisk/index.blade.php" <<'PHPEOF'
@extends('layouts.panel')

@section('title', 'Web Disk')
@section('subtitle', 'WebDAV accounts — files ko desktop/client se access karo (read-only / read-write)')

@section('actions')
    <a class="btn small secondary" href="{{ route('dashboard') }}">← Dashboard</a>
@endsection

@section('content')
@if ($error !== null)
    <div class="flash error">{{ $error }}</div>
@endif

<div class="card">
    <h3>Naya Web Disk account / password reset</h3>
    <form method="POST" action="{{ route('webdisk.store') }}">
        @csrf
        <label>Login
            <input type="text" name="login" placeholder="designer" pattern="[A-Za-z0-9._-]+" maxlength="60" required>
        </label>
        <label>Password
            <input type="password" name="password" minlength="8" maxlength="128" required>
        </label>
        <label>Permissions
            <select name="permissions" required>
                <option value="rw">Read-Write</option>
                <option value="ro">Read-Only</option>
            </select>
        </label>
        <button class="btn" type="submit">Save account</button>
    </form>
    <p class="muted">Maujooda login dobara save karna = password/permissions reset.</p>
</div>

<div class="card">
    <h3>Web Disk accounts ({{ count($accounts) }})</h3>
    @if ($accounts === [])
        <p class="muted">Koi Web Disk account nahi.</p>
    @else
        <table>
            <tr><th>Login</th><th>Permissions</th><th></th></tr>
            @foreach ($accounts as $a)
            <tr>
                <td>{{ $a['login'] ?? '' }}</td>
                <td>{{ ($a['permissions'] ?? '') === 'rw' ? 'Read-Write' : 'Read-Only' }}</td>
                <td>
                    <form method="POST" action="{{ route('webdisk.destroy', $a['login'] ?? '') }}" onsubmit="return confirm('Delete?')">
                        @csrf @method('DELETE')
                        <button class="btn small danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
            @endforeach
        </table>
    @endif
</div>

<div class="card">
    <h3>Connect (WebDAV client)</h3>
    <p>URL: <code>https://{{ request()->getHost() }}/webdisk/</code> · Realm: <code>{{ $realm }}</code></p>
    <p class="muted">Digest authentication — wahi login/password jo upar set kiya. Read-Only accounts
       sirf padh sakte hain (write methods agent-side deny hain).</p>
</div>
@endsection
PHPEOF
  for rel in "${PANEL_FILES[@]}"; do
    if ! lout="$("$PHP_BIN" -l "${PANEL}/${rel}" 2>&1)"; then
      warn "lint fail: ${rel}"; say "    ${lout}"; rollback; die "panel lint fail"
    fi
  done
  [[ "$(cnt 'Paneld::run' "${PANEL}/app/Http/Controllers/WebDiskController.php")" -ge 1 ]] \
    || { rollback; die "WebDiskController Paneld::run nahi karta"; }
  [[ "$(cnt 'name="password"' "${PANEL}/resources/views/webdisk/index.blade.php")" -ge 1 ]] \
    || { rollback; die "WebDisk view me password field nahi"; }
  [[ "$(cnt 'webdisk/{login}' "${PANEL}/routes/web.php")" -ge 1 ]] \
    || { rollback; die "routes me webdisk/{login} destroy route nahi"; }
  ok "panel files likhi + lint clean (2) — WebDisk ab agent-truth"

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
  ok "WebDisk ab root-agent se: digest credential + managed DAV conf + vhost include (99 types)"
  info "backup : ${BACKUP}"
  info "log    : ${LOG_FILE}"
  info "rollback: sudo bash $0 --rollback"
  say ""
  say "  ${C_G}b3-fix v${VERSION} APPLY ho gaya.${C_0} Panel → Web Disk page kholo; account banao, WebDAV client se https://<host>/webdisk/ try karo."
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
