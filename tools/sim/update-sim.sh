#!/usr/bin/env bash
# =============================================================================
#  AlphaCP — panel-update.sh simulation test  (sirf throwaway sandbox/container me!)
#
#  Setup : doctor-sim.sh chalata hai => server ki asli haalat (panel 0.3.0 + doctor v1.7
#          drop-in, HTTP 200). Phir installer/panel-update.sh ko stubs ke saath chalata hai:
#            curl     : raw.githubusercontent -> local artifact;  127.0.0.1:8090 -> asli Laravel request
#            composer : 0.3.0 bundle ka vendor/ (composer.lock same hona chahiye — check hota hai)
#            systemctl: doctor-sim ka stub
#  U1 normal update 0.3.0 -> 0.3.2      U2 sha256 mismatch -> kuch nahi chhedta
#  U3 health fail -> auto rollback       U4 backups prune (KEEP=1) + sync-tool checksum fail
#  U1 me alphacp-sync v1.0 -> v1.2 upgrade + sync hook bhi
#  U5 private repo (raw 404) + sync v1.2 -> get      U6 private + purana sync -> saaf error
# =============================================================================
set -uo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
UPDATER="${REPO}/installer/panel-update.sh"
ART="${ART:-$(ls -1 "${REPO}"/artifacts/panel-code-*.tar.gz | sort -V | tail -1)}"
ART_VER="$(basename "${ART}" .tar.gz)"; ART_VER="${ART_VER#panel-code-}"
U=/tmp/updsim
ACP_HOME=/usr/local/alphacp; PANEL=${ACP_HOME}/panel; REL=${ACP_HOME}/releases
PASS=0; FAIL=0
[[ ${EUID} -eq 0 ]] || { echo "sudo se chalao"; exit 1; }
t_ok()   { echo "  PASS: $*"; PASS=$((PASS+1)); }
t_fail() { echo "  FAIL: $*"; FAIL=$((FAIL+1)); }
chk()    { local d="$1"; shift; if "$@"; then t_ok "$d"; else t_fail "$d"; fi; }

echo "=== setup: doctor-sim (server = 0.3.0 + doctor v1.7) ==="
bash "${REPO}/tools/sim/doctor-sim.sh" > /tmp/updsim-doctor.log 2>&1 || { echo "doctor-sim fail — /tmp/updsim-doctor.log"; exit 1; }
tail -1 /tmp/updsim-doctor.log

rm -rf "${U}"; mkdir -p "${U}/bin" "${U}/state"
TMPV="$(mktemp -d)"; unzip -q -o "${REPO}/alphacp-code-bundle.zip" artifacts/panel-bundle-0.3.0.tar.gz -d "${TMPV}"
cp "${TMPV}/artifacts/panel-bundle-0.3.0.tar.gz" "${U}/vendor-bundle.tar.gz"; rm -rf "${TMPV}"
cp "${ART}" "${U}/artifact.tar.gz"
AGENT_ART="${AGENT_ART:-$(ls -1 "${REPO}"/artifacts/agent-*.tar.gz | sort -V | tail -1)}"
cp "${AGENT_ART}" "${U}/agent.tar.gz"
cp "${REPO}/installer/alphacp-sync.sh" "${U}/sync-v11.sh"
SYNC_BIN="${ACP_HOME}/bin/alphacp-sync"
install_old_sync() {
  mkdir -p "${ACP_HOME}/bin"
  if git -C "${REPO}" cat-file -e aa2091dc3ee28850266b8348aea1ea89408c64c2^{commit} 2>/dev/null \
     && git -C "${REPO}" show aa2091dc3ee28850266b8348aea1ea89408c64c2:installer/alphacp-sync.sh > "${SYNC_BIN}" 2>/dev/null; then
    :
  else
    # shallow clone fallback — real v1.0 file repo me na ho to stub (no get mode)
    cat > "${SYNC_BIN}" <<'STUB'
#!/bin/bash
SYNC_VERSION="1.0"
echo "alphacp-sync v1.0 stub $*"
exit 0
STUB
  fi
  chmod 0755 "${SYNC_BIN}"
}
install_old_sync   # server par v1.0 setup hai
install_new_sync() { install -m 0755 "${REPO}/installer/alphacp-sync.sh" "${SYNC_BIN}"; }
# alphacp-sync get ke liye "GitHub" = is repo ka bare clone (file://), deploy key = dummy file
rm -rf "${U}/remote.git"; git clone -q --bare "${REPO}" "${U}/remote.git"
mkdir -p "${U}/conf"; echo dummy > "${U}/conf/github_deploy_key"; chmod 0600 "${U}/conf/github_deploy_key"
SHA="$(sha256sum "${ART}" | cut -d' ' -f1)"

cat > "${U}/bin/curl" <<'EOF'
#!/bin/bash
# download -> local artifact ; 127.0.0.1:8090 -> doctor-sim ka asli-Laravel curl stub
out=""; url=""; for a in "$@"; do [[ "$prev" == "-o" ]] && out="$a"; [[ "$a" == http* ]] && url="$a"; prev="$a"; done
if [[ "$url" == *raw.githubusercontent.com* && -f /tmp/updsim/state/private ]]; then echo "download $url -> 404 (private)" >> /tmp/updsim/state/calls.log; echo "curl: (22) The requested URL returned error: 404" >&2; exit 22; fi
if [[ "$url" == *raw.githubusercontent.com*/installer/alphacp-sync.sh ]]; then echo "download $url" >> /tmp/updsim/state/calls.log; if [[ -n "$out" ]]; then cp /tmp/updsim/sync-v11.sh "$out"; else cat /tmp/updsim/sync-v11.sh; fi; exit 0; fi
if [[ "$url" == *raw.githubusercontent.com*/artifacts/agent-* ]]; then echo "download $url" >> /tmp/updsim/state/calls.log; if [[ -n "$out" ]]; then cp /tmp/updsim/agent.tar.gz "$out"; else cat /tmp/updsim/agent.tar.gz; fi; exit 0; fi
if [[ "$url" == *raw.githubusercontent.com* ]]; then echo "download $url" >> /tmp/updsim/state/calls.log; if [[ -n "$out" ]]; then cp /tmp/updsim/artifact.tar.gz "$out"; else cat /tmp/updsim/artifact.tar.gz; fi; exit 0; fi
if [[ "$url" == *127.0.0.1:8090* && -f /tmp/updsim/state/fail-health-once ]]; then
  rm -f /tmp/updsim/state/fail-health-once; echo "health FORCED 500" >> /tmp/updsim/state/calls.log
  for a in "$@"; do [[ "$a" == *http_code* ]] && printf 500; done; exit 0
fi
exec /tmp/acpsim/bin/curl "$@"
EOF
cat > "${U}/bin/composer" <<'EOF'
#!/bin/bash
# composer stub: lock same hai to 0.3.0 bundle ka vendor/ (server par asli composer install hota hai)
echo "composer $* (cwd=$PWD)" >> /tmp/updsim/state/calls.log
diff -q <(tar xzOf /tmp/updsim/vendor-bundle.tar.gz panel/composer.lock) composer.lock >/dev/null || { echo "composer.lock differs"; exit 1; }
tar xzf /tmp/updsim/vendor-bundle.tar.gz --strip-components=1 panel/vendor
EOF
cat > "${U}/bin/alphacp-sync" <<'EOF'
#!/bin/bash
echo "alphacp-sync $*" >> /tmp/updsim/state/calls.log; touch /tmp/updsim/state/sync-called
EOF
chmod 0755 "${U}/bin/"*

run_update() {  # $1 = label, rest = env overrides
  local label="$1"; shift
  ( cd /root && env PATH="${U}/bin:/tmp/acpsim/bin:${PATH}" ACP_PANEL_BUNDLE_SHA256="${SHA}" ACP_PANEL_VERSION="${ART_VER}" ACP_SKIP_EXTRA_PACKAGES=1 \
    SYNC_REPO_URL="file://${U}/remote.git" SYNC_CONF_DIR="${U}/conf" SYNC_WORK_DIR="${U}/syncwork" "$@" bash "${UPDATER}" ) \
    > "${U}/update-${label}.out" 2>&1
  local rc=$?; sed 's/^/    | /' "${U}/update-${label}.out"; return ${rc}
}
http_now()  { env PATH="/tmp/acpsim/bin:${PATH}" curl -k -s -o /dev/null -w '%{http_code}' "https://127.0.0.1:8090/"; }
panel_ver() { python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["version"])' "${PANEL}/MANIFEST.json" 2>/dev/null || echo none; }
php_boot() {  # $1 = php code after Laravel boot (panel dir, alphacp user)
  ( cd "${PANEL}" && runuser -u alphacp -- /usr/bin/php8.4 -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php";
      $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); '"$1" ) 2>/dev/null
}
admin_hash() { php_boot 'echo Illuminate\Support\Facades\DB::table("users")->where("username","admin")->value("password_hash");' | tail -1; }
pw_works()  { local h; h="$(admin_hash)"; [[ -n "${h}" && "${h}" == "${HASH_BEFORE}" ]]; }
nbackups()  { find "${REL}" -maxdepth 1 -name 'panel-backup-*' -type d 2>/dev/null | wc -l; }
HASH_BEFORE="$(admin_hash)"; [[ ${#HASH_BEFORE} -gt 20 ]] || { echo "setup: admin hash nahi mila"; exit 1; }
APPKEY_BEFORE="$(grep '^APP_KEY=' "${PANEL}/.env")"
BEFORE_VER="$(panel_ver)"

# ================================================================= U1
echo; echo "=== U1: normal update ${BEFORE_VER} -> ${ART_VER} ==="
chk "update se pehle HTTP 200" test "$(http_now)" = 200
run_update U1; rc=$?
chk "exit 0" test ${rc} -eq 0
chk "banner 'updater 0.57.0'" grep -q "updater 0.57.0" "${U}/update-U1.out"
chk "purana sync (no get) -> public URL se artifact" grep -q "artifact source: raw.githubusercontent (public)" "${U}/update-U1.out"
chk "alphacp-sync v1.0 -> v1.2 upgrade hua" grep -q '^SYNC_VERSION="1.2"' "${SYNC_BIN}"
chk "sync tool = GitHub wali file (sha256)" test "$(sha256sum < "${SYNC_BIN}")" = "$(sha256sum < "${REPO}/installer/alphacp-sync.sh")"
chk "sync tool 0755" test "$(stat -c %a "${SYNC_BIN}")" = 755
chk "'alphacp-sync v1.2 install hua' dikha" grep -q "alphacp-sync v1.2 install hua" "${U}/update-U1.out"
chk "'Update complete'" grep -q "UPDATE COMPLETE" "${U}/update-U1.out"
chk "download commit-pinned URL se (branch nahi)" grep -qE "download https://raw.githubusercontent.com/abhay751218-hue/AlphaCP/[0-9a-f]{40}/" "${U}/state/calls.log"
chk "MANIFEST version = ${ART_VER}" test "$(panel_ver)" = "${ART_VER}"
chk ".env ACP_VERSION=${ART_VER}" grep -q "^ACP_VERSION=${ART_VER}$" "${PANEL}/.env"
chk "APP_KEY same (sessions/encryption safe)" test "$(grep '^APP_KEY=' "${PANEL}/.env")" = "${APPKEY_BEFORE}"
chk ".env 0640 alphacp" test "$(stat -c '%a %U' "${PANEL}/.env")" = "640 alphacp"
chk "update ke baad HTTP 200" test "$(http_now)" = 200
chk "admin password hash same (DB data preserve)" pw_works
chk "License code present" test -f "${PANEL}/app/Support/License/LicenseClient.php"
chk "PasswordGenerator present (0.3.2 fix)" test -f "${PANEL}/app/Support/PasswordGenerator.php"
chk "AccountsController present (0.4.0)" test -f "${PANEL}/app/Http/Controllers/AccountsController.php"
chk "PackagesController present (0.5.0)" test -f "${PANEL}/app/Http/Controllers/PackagesController.php"
chk "DomainsController present (0.6.0)" test -f "${PANEL}/app/Http/Controllers/DomainsController.php"
chk "PhpController present (0.7.0)" test -f "${PANEL}/app/Http/Controllers/PhpController.php"
chk "CronController present (0.7.0)" test -f "${PANEL}/app/Http/Controllers/CronController.php"
chk "SslController present (0.8.0)" test -f "${PANEL}/app/Http/Controllers/SslController.php"
chk "AutoSSL migration 000008 present (0.9.0)" test -f "${PANEL}/database/migrations/2026_09_29_000008_add_autossl_to_domains.php"
chk "PhpIniController present (0.10.0)" test -f "${PANEL}/app/Http/Controllers/PhpIniController.php"
chk "ErrorPagesController present (0.11.0)" test -f "${PANEL}/app/Http/Controllers/ErrorPagesController.php"
chk "IndexesController present (0.12.0)" test -f "${PANEL}/app/Http/Controllers/IndexesController.php"
chk "MimeTypesController present (0.13.0)" test -f "${PANEL}/app/Http/Controllers/MimeTypesController.php"
chk "HandlersController present (0.14.0)" test -f "${PANEL}/app/Http/Controllers/HandlersController.php"
chk "FilesController present (0.15.0)" test -f "${PANEL}/app/Http/Controllers/FilesController.php"
chk "PrivacyController present (0.16.0)" test -f "${PANEL}/app/Http/Controllers/PrivacyController.php"
chk "DiskUsageController present (0.17.0)" test -f "${PANEL}/app/Http/Controllers/DiskUsageController.php"
chk "SshController present (0.18.0)" test -f "${PANEL}/app/Http/Controllers/SshController.php"
chk "MailController present (0.19.0)" test -f "${PANEL}/app/Http/Controllers/MailController.php"
chk "ForwardersController present (0.20.0)" test -f "${PANEL}/app/Http/Controllers/ForwardersController.php"
chk "AutorespondersController present (0.21.0)" test -f "${PANEL}/app/Http/Controllers/AutorespondersController.php"
chk "DefaultAddressController present (0.22.0)" test -f "${PANEL}/app/Http/Controllers/DefaultAddressController.php"
chk "EmailFiltersController present (0.23.0)" test -f "${PANEL}/app/Http/Controllers/EmailFiltersController.php"
chk "DeliverabilityController present (0.24.0)" test -f "${PANEL}/app/Http/Controllers/DeliverabilityController.php"
chk "SpamFiltersController present (0.25.0)" test -f "${PANEL}/app/Http/Controllers/SpamFiltersController.php"
chk "MailingListsController present (0.26.0)" test -f "${PANEL}/app/Http/Controllers/MailingListsController.php"
chk "EmailRoutingController present (0.27.0)" test -f "${PANEL}/app/Http/Controllers/EmailRoutingController.php"
chk "TrackDeliveryController present (0.28.0)" test -f "${PANEL}/app/Http/Controllers/TrackDeliveryController.php"
chk "GlobalFiltersController present (0.29.0)" test -f "${PANEL}/app/Http/Controllers/GlobalFiltersController.php"
chk "AddressImporterController present (0.30.0)" test -f "${PANEL}/app/Http/Controllers/AddressImporterController.php"
chk "EncryptionController present (0.31.0)" test -f "${PANEL}/app/Http/Controllers/EncryptionController.php"
chk "BoxTrapperController present (0.32.0)" test -f "${PANEL}/app/Http/Controllers/BoxTrapperController.php"
chk "CalendarController present (0.33.0)" test -f "${PANEL}/app/Http/Controllers/CalendarController.php"
chk "EmailDiskUsageController present (0.34.0)" test -f "${PANEL}/app/Http/Controllers/EmailDiskUsageController.php"
chk "WebmailController present (0.35.0)" test -f "${PANEL}/app/Http/Controllers/WebmailController.php"
chk "MysqlDatabasesController present (0.36.0)" test -f "${PANEL}/app/Http/Controllers/MysqlDatabasesController.php"
chk "MysqlWizardController present (0.37.0)" test -f "${PANEL}/app/Http/Controllers/MysqlWizardController.php"
chk "PhpmyadminController present (0.38.0)" test -f "${PANEL}/app/Http/Controllers/PhpmyadminController.php"
chk "RemoteMysqlController present (0.39.0)" test -f "${PANEL}/app/Http/Controllers/RemoteMysqlController.php"
chk "ZoneEditorController present (0.40.0)" test -f "${PANEL}/app/Http/Controllers/ZoneEditorController.php"
chk "DynamicDnsController present (0.41.0)" test -f "${PANEL}/app/Http/Controllers/DynamicDnsController.php"
chk "TrackDnsController present (0.42.0)" test -f "${PANEL}/app/Http/Controllers/TrackDnsController.php"
chk "DnsZonesController present (0.43.0)" test -f "${PANEL}/app/Http/Controllers/DnsZonesController.php"
chk "DnsZonesController store (0.44.0)" grep -q "function store" "${PANEL}/app/Http/Controllers/DnsZonesController.php"
chk "HostnameAController present (0.45.0)" test -f "${PANEL}/app/Http/Controllers/HostnameAController.php"
chk "ZoneTemplatesController present (0.46.0)" test -f "${PANEL}/app/Http/Controllers/ZoneTemplatesController.php"
chk "GlobalEmailRoutingController present (0.47.0)" test -f "${PANEL}/app/Http/Controllers/GlobalEmailRoutingController.php"
chk "NsReportController present (0.48.0)" test -f "${PANEL}/app/Http/Controllers/NsReportController.php"
chk "ParkDomainController present (0.49.0)" test -f "${PANEL}/app/Http/Controllers/ParkDomainController.php"
chk "DnsCleanupController present (0.50.0)" test -f "${PANEL}/app/Http/Controllers/DnsCleanupController.php"
chk "ZoneTtlController present (0.51.0)" test -f "${PANEL}/app/Http/Controllers/ZoneTtlController.php"
chk "DomainForwardController present (0.52.0)" test -f "${PANEL}/app/Http/Controllers/DomainForwardController.php"
chk "DnsSyncController present (0.53.0)" test -f "${PANEL}/app/Http/Controllers/DnsSyncController.php"
chk "NameserverSelectionController present (0.54.0)" test -f "${PANEL}/app/Http/Controllers/NameserverSelectionController.php"
chk "BackupController present (0.55.0)" test -f "${PANEL}/app/Http/Controllers/BackupController.php"
chk "BackupWizardController present (0.56.0)" test -f "${PANEL}/app/Http/Controllers/BackupWizardController.php"
chk "FileRestorationController present (0.57.0)" test -f "${PANEL}/app/Http/Controllers/FileRestorationController.php"
chk "agent 0.50.0 Bootstrap" grep -q "ACP_AGENT_VERSION', '0.50.0'" "${ACP_HOME}/agent/src/Bootstrap.php"
chk "account.create in paneld allowlist" grep -q "account.create" "${ACP_HOME}/agent/config/tasks.php"
chk "domain.add in paneld allowlist" grep -q "domain.add" "${ACP_HOME}/agent/config/tasks.php"
chk "php.setVersion in paneld allowlist" grep -q "php.setVersion" "${ACP_HOME}/agent/config/tasks.php"
chk "cron.set in paneld allowlist" grep -q "cron.set" "${ACP_HOME}/agent/config/tasks.php"
chk "ssl.issue in paneld allowlist" grep -q "ssl.issue" "${ACP_HOME}/agent/config/tasks.php"
chk "php.setIni in paneld allowlist" grep -q "php.setIni" "${ACP_HOME}/agent/config/tasks.php"
chk "errorpages.set in paneld allowlist" grep -q "errorpages.set" "${ACP_HOME}/agent/config/tasks.php"
chk "indexes.set in paneld allowlist" grep -q "indexes.set" "${ACP_HOME}/agent/config/tasks.php"
chk "mime.set in paneld allowlist" grep -q "mime.set" "${ACP_HOME}/agent/config/tasks.php"
chk "handlers.set in paneld allowlist" grep -q "handlers.set" "${ACP_HOME}/agent/config/tasks.php"
chk "files.set in paneld allowlist" grep -q "files.set" "${ACP_HOME}/agent/config/tasks.php"
chk "privacy.set in paneld allowlist" grep -q "privacy.set" "${ACP_HOME}/agent/config/tasks.php"
chk "files.usage in paneld allowlist" grep -q "files.usage" "${ACP_HOME}/agent/config/tasks.php"
chk "ssh.set in paneld allowlist" grep -q "ssh.set" "${ACP_HOME}/agent/config/tasks.php"
chk "mail.set in paneld allowlist" grep -q "mail.set" "${ACP_HOME}/agent/config/tasks.php"
chk "mail.forward in paneld allowlist" grep -q "mail.forward" "${ACP_HOME}/agent/config/tasks.php"
chk "mail.autorespond in paneld allowlist" grep -q "mail.autorespond" "${ACP_HOME}/agent/config/tasks.php"
chk "mail.catchall in paneld allowlist" grep -q "mail.catchall" "${ACP_HOME}/agent/config/tasks.php"
chk "mail.filter in paneld allowlist" grep -q "mail.filter" "${ACP_HOME}/agent/config/tasks.php"
chk "mail.deliverability in paneld allowlist" grep -q "mail.deliverability" "${ACP_HOME}/agent/config/tasks.php"
chk "mail.spam in paneld allowlist" grep -q "mail.spam" "${ACP_HOME}/agent/config/tasks.php"
chk "mail.list in paneld allowlist" grep -q "mail.list" "${ACP_HOME}/agent/config/tasks.php"
chk "mail.routing in paneld allowlist" grep -q "mail.routing" "${ACP_HOME}/agent/config/tasks.php"
chk "mail.track in paneld allowlist" grep -q "mail.track" "${ACP_HOME}/agent/config/tasks.php"
chk "mail.gfilter in paneld allowlist" grep -q "mail.gfilter" "${ACP_HOME}/agent/config/tasks.php"
chk "mail.encrypt in paneld allowlist" grep -q "mail.encrypt" "${ACP_HOME}/agent/config/tasks.php"
chk "mail.boxtrapper in paneld allowlist" grep -q "mail.boxtrapper" "${ACP_HOME}/agent/config/tasks.php"
chk "mail.calendar in paneld allowlist" grep -q "mail.calendar" "${ACP_HOME}/agent/config/tasks.php"
chk "mail.usage in paneld allowlist" grep -q "mail.usage" "${ACP_HOME}/agent/config/tasks.php"
chk "mail.webmail in paneld allowlist" grep -q "mail.webmail" "${ACP_HOME}/agent/config/tasks.php"
chk "db.set in paneld allowlist" grep -q "db.set" "${ACP_HOME}/agent/config/tasks.php"
chk "db.phpmyadmin in paneld allowlist" grep -q "db.phpmyadmin" "${ACP_HOME}/agent/config/tasks.php"
chk "db.remote in paneld allowlist" grep -q "db.remote" "${ACP_HOME}/agent/config/tasks.php"
chk "dns.zone in paneld allowlist" grep -q "dns.zone" "${ACP_HOME}/agent/config/tasks.php"
chk "dns.dynamic in paneld allowlist" grep -q "dns.dynamic" "${ACP_HOME}/agent/config/tasks.php"
chk "dns.track in paneld allowlist" grep -q "dns.track" "${ACP_HOME}/agent/config/tasks.php"
chk "dns.hostname in paneld allowlist" grep -q "dns.hostname" "${ACP_HOME}/agent/config/tasks.php"
chk "dns.templates in paneld allowlist" grep -q "dns.templates" "${ACP_HOME}/agent/config/tasks.php"
chk "mail.globalrouting in paneld allowlist" grep -q "mail.globalrouting" "${ACP_HOME}/agent/config/tasks.php"
chk "dns.nsreport in paneld allowlist" grep -q "dns.nsreport" "${ACP_HOME}/agent/config/tasks.php"
chk "dns.park in paneld allowlist" grep -q "dns.park" "${ACP_HOME}/agent/config/tasks.php"
chk "dns.cleanup in paneld allowlist" grep -q "dns.cleanup" "${ACP_HOME}/agent/config/tasks.php"
chk "dns.ttl in paneld allowlist" grep -q "dns.ttl" "${ACP_HOME}/agent/config/tasks.php"
chk "dns.forward in paneld allowlist" grep -q "dns.forward" "${ACP_HOME}/agent/config/tasks.php"
chk "dns.sync in paneld allowlist" grep -q "dns.sync" "${ACP_HOME}/agent/config/tasks.php"
chk "dns.nameserver in paneld allowlist" grep -q "dns.nameserver" "${ACP_HOME}/agent/config/tasks.php"
chk "backup.create in paneld allowlist" grep -q "backup.create" "${ACP_HOME}/agent/config/tasks.php"
chk "backup.wizard in paneld allowlist" grep -q "backup.wizard" "${ACP_HOME}/agent/config/tasks.php"
chk "backup.restore in paneld allowlist" grep -q "backup.restore" "${ACP_HOME}/agent/config/tasks.php"
chk "issueLetsEncrypt in agent" grep -q "issueLetsEncrypt" "${ACP_HOME}/agent/src/AccountOs.php"
chk "suspended page installed" test -f "${ACP_HOME}/share/suspended/index.html"
chk ".env ACP_AGENT_VERSION=0.50.0" grep -q "^ACP_AGENT_VERSION=0.50.0$" "${PANEL}/.env"
chk "route cache me /license" grep -rqs "license" "${PANEL}/bootstrap/cache/"
chk "backup bana (1)" test "$(nbackups)" -eq 1
chk "backup = purana ${BEFORE_VER}" grep -q "\"version\": \"${BEFORE_VER}\"" "$(find "${REL}" -maxdepth 1 -name 'panel-backup-*' | head -1)/MANIFEST.json"
chk "alphacp-sync hook chala" test -f "${U}/state/sync-called"
chk "no leftover release dirs" test "$(find "${REL}" -maxdepth 1 -name 'panel-2*' | wc -l)" -eq 0
chk "artisan + DB chalte (unknown user -> 'nahi mila')" bash -c "cd ${PANEL} && runuser -u alphacp -- /usr/bin/php8.4 artisan alphacp:admin-password nosuchuser 2>&1 | grep -q 'nahi mila'"
GEN="$(php_boot 'echo App\Support\PasswordGenerator::generate();' | tail -1)"
chk "PasswordGenerator (0.3.2) live code me chalta: ${GEN:0:4}…" bash -c "[[ '${GEN}' =~ ^[A-Za-z0-9]{20}$ && '${GEN}' =~ [0-9] && '${GEN}' =~ [A-Z] && '${GEN}' =~ [a-z] ]]"

# ================================================================= U2
echo; echo "=== U2: sha256 mismatch -> abort, panel untouched ==="
B2="$(nbackups)"; rm -f "${U}/state/sync-called"
run_update U2 ACP_PANEL_BUNDLE_SHA256=0000000000000000000000000000000000000000000000000000000000000000; rc=$?
chk "exit != 0" test ${rc} -ne 0
chk "checksum error message" grep -qi "sha\|checksum" "${U}/update-U2.out"
chk "panel version same" test "$(panel_ver)" = "${ART_VER}"
chk "koi naya backup nahi" test "$(nbackups)" -eq "${B2}"
chk "HTTP 200 abhi bhi" test "$(http_now)" = 200
chk "sync NAHI chala (fail par)" test ! -f "${U}/state/sync-called"

# ================================================================= U3
echo; echo "=== U3: health check fail -> automatic rollback ==="
sleep 1; touch "${U}/state/fail-health-once"
MARK="rollback-marker-$$"; echo "${MARK}" > "${PANEL}/storage/app/private/marker.txt"; chown alphacp:alphacp "${PANEL}/storage/app/private/marker.txt"
run_update U3; rc=$?
chk "exit != 0" test ${rc} -ne 0
chk "'Rollback successful'" grep -q "Rollback successful" "${U}/update-U3.out"
chk "panel wapas purana (marker file)" grep -q "${MARK}" "${PANEL}/storage/app/private/marker.txt"
chk "HTTP 200 (rollback ke baad)" test "$(http_now)" = 200
chk "failed release rakha gaya (debug ke liye)" test "$(find "${REL}" -maxdepth 1 -name 'panel-failed-*' | wc -l)" -ge 1
chk "admin password hash same" pw_works

# ================================================================= U4
echo; echo "=== U4: backups prune (ACP_KEEP_BACKUPS=1) ==="
sleep 1; install_old_sync
run_update U4 ACP_KEEP_BACKUPS=1 ACP_SYNC_TOOL_SHA256=badbadbad; rc=$?
chk "sync tool checksum galat -> warning, v1.0 hi rehta" grep -q '^SYNC_VERSION="1.0"' "${SYNC_BIN}"
chk "sync tool fail par bhi panel update safal" grep -q "UPDATE COMPLETE" "${U}/update-U4.out"
chk "exit 0" test ${rc} -eq 0
chk "sirf 1 backup bacha" test "$(nbackups)" -eq 1
chk "'purana backup hataya' log" grep -q "purana backup hataya" "${U}/update-U4.out"
chk "HTTP 200" test "$(http_now)" = 200
chk "storage preserve hua (marker)" grep -q "${MARK}" "${PANEL}/storage/app/private/marker.txt"

# ================================================================= U5
echo; echo "=== U5: repo PRIVATE (raw URL 404) + sync v1.2 -> 'get' se update ==="
sleep 1; install_new_sync; touch "${U}/state/private"; echo "U5-marker" > "${PANEL}/storage/app/private/u5.txt"
run_update U5; rc=$?
chk "exit 0" test ${rc} -eq 0
chk "artifact source: alphacp-sync get (deploy key)" grep -q "artifact source: alphacp-sync get (deploy key)" "${U}/update-U5.out"
chk "raw URL try hi nahi hua (get pehle)" bash -c "! grep -q 'artifact.*404' '${U}/state/calls.log'"
chk "UPDATE COMPLETE" grep -q "UPDATE COMPLETE" "${U}/update-U5.out"
chk "HTTP 200" test "$(http_now)" = 200
chk "storage preserve (U5 marker)" grep -q "U5-marker" "${PANEL}/storage/app/private/u5.txt"

# ================================================================= U6
echo; echo "=== U6: repo PRIVATE + purana sync v1.0 (no get) -> saaf error, panel untouched ==="
sleep 1; install_old_sync; B6="$(nbackups)"
run_update U6; rc=$?
chk "exit != 0" test ${rc} -ne 0
chk "message: alphacp-sync v1.2 chahiye" grep -q "alphacp-sync v1.2 chahiye" "${U}/update-U6.out"
chk "koi naya backup/swap nahi" test "$(nbackups)" -eq "${B6}"
chk "HTTP 200 abhi bhi" test "$(http_now)" = 200
rm -f "${U}/state/private"

echo; echo "=== UPDATE-SIM: ${PASS} pass, ${FAIL} fail ==="
[[ ${FAIL} -eq 0 ]]
