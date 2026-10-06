#!/usr/bin/env bash
# =============================================================================
#  alphacp-sync simulation test (sirf throwaway sandbox me — sudo chahiye)
#  Pehle tools/sim/doctor-sim.sh chalta hai (fake server + asli panel), phir:
#   * server par nakli secrets + license/trial files daalte hain
#   * local bare repo (= GitHub) par sync -> check: code gaya, SECRET EK BHI NAHI gaya
#   * re-run = no change, file badlo = naya commit, remote aage badha ho = rebase + push
#   * asli GitHub se SSH: deploy key banti hai, host key pinned, "key add karo" flow
# =============================================================================
set -uo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
SYNC="${REPO}/installer/alphacp-sync.sh"
ACP=/usr/local/alphacp; PANEL=${ACP}/panel
REMOTE=/tmp/syncsim/remote.git
PASS=0; FAIL=0
t_ok()   { echo "  PASS: $*"; PASS=$((PASS+1)); }
t_fail() { echo "  FAIL: $*"; FAIL=$((FAIL+1)); }
[[ ${EUID} -eq 0 ]] || { echo "sudo se chalao"; exit 1; }

if [[ ! -f "${PANEL}/artisan" || ! -f /tmp/acpsim/bin/curl ]]; then
  echo "(fake server nahi mila — pehle doctor-sim chala raha hoon)"; bash "${REPO}/tools/sim/doctor-sim.sh" >/dev/null 2>&1 || true
fi

# ---------------------------------------------------------------- nakli secrets + license files
DBPASS='SuperSecretDb123'; LIC='ACP-TRIAL-XYZ123456'
mkdir -p ${ACP}/etc ${ACP}/var ${ACP}/license/keys ${ACP}/bin /opt/alphacp-license /etc/nginx/sites-available
printf 'ACP_DB_HOST=127.0.0.1\nACP_DB_NAME=alphacp\nACP_DB_USER=alphacp\nACP_DB_PASS=%s\n' "$DBPASS" > ${ACP}/etc/database.env
printf 'LICENSE_KEY=%s\nLICENSE_SERVER=https://license.example.com\nTRIAL_DAYS=15\n' "$LIC" > ${ACP}/etc/license.env
printf -- '-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASC\n-----END PRIVATE KEY-----\n' > ${ACP}/license/keys/signing.pem
printf -- '-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----\n' > ${ACP}/license/embedded_key.php
APPKEY="$(sed -n 's/^APP_KEY=base64://p' ${PANEL}/.env)"; echo "base64:${APPKEY}" > ${ACP}/var/panel-appkey.txt
ADMINPW="$(sed -n 's/^panel_pass=//p' ${ACP}/var/panel-admin.txt)"
mkdir -p ${PANEL}/app/Services/License
cat > ${PANEL}/app/Services/License/LicenseManager.php <<'EOF'
<?php
declare(strict_types=1);
namespace App\Services\License;
/** 15 din ka trial + license verify (sim) */
final class LicenseManager { public const TRIAL_DAYS = 15; }
EOF
echo '<?php return ["db" => "SuperSecretDb123"];' > ${PANEL}/app/Leak.php           # leak attempt 1
echo '<?php // token ghp_abcdefghijklmnopqrstuvwxyz0123456789AB' > ${PANEL}/config/leak2.php   # leak attempt 2
# v1.3 regression fixtures:
#  (a) `backup/` aur `ssl/` NAAM ke source directories — v1.2 ki bare-name prune inhe uda deti thi
mkdir -p ${PANEL}/resources/views/backup ${PANEL}/resources/views/ssl
cat > ${PANEL}/resources/views/backup/index.blade.php <<'EOF'
@extends('layouts.panel')
@section('content')<h3>Backup</h3><p>jobs.json</p>@endsection
EOF
cat > ${PANEL}/resources/views/ssl/index.blade.php <<'EOF'
@extends('layouts.panel')
@section('content')<h3>SSL/TLS Status</h3><button>Run AutoSSL</button>@endsection
EOF
#  (b) normal JS jo v1.2/v1.3 ka secret pattern "secret" samajh kar POORI file drop kar deta tha.
#      v1.4 ka asli server false-positive bhi yahi tha: JS object property `PASSWORD: password,`
cat > ${PANEL}/resources/views/backup/destinations.blade.php <<'EOF'
@extends('layouts.panel')
@section('content')
<script>
    PASSWORD = document.getElementById('password').value;
    TOKEN = form.querySelector('[name=_token]').value;
    body: JSON.stringify({ PASSWORD: password, TOKEN: csrf, });
</script>
@endsection
EOF
#  (c) CONFIG-type file me ASLI secret shape — ye drop hona hi chahiye (kv check config par chalta hai)
cat > ${PANEL}/resources/views/backup/leaky.conf <<'EOF'
DB_PASSWORD=Sup3rS3cretValue
EOF
#  (d) runtime junk jo v1.3 me snapshot me leak hone laga tha — ab prune hona chahiye
mkdir -p ${PANEL}/bootstrap/cache ${ACP}/logs ${ACP}/backups/locks
echo '<?php return ["junk"=>1];' > ${PANEL}/bootstrap/cache/zz-sync-sim.php   # Laravel is naam ko load nahi karta
echo 'verify log' > ${ACP}/logs/verify-report.txt
: > ${ACP}/backups/locks/xyz.lock
echo '<?php // license server (sim)' > /opt/alphacp-license/server.php
printf 'server { listen 8090 ssl; root %s/public; }\n' "${PANEL}" > /etc/nginx/sites-available/alphacp-panel.conf
printf '#!/bin/sh\necho alphacp cli\n' > ${ACP}/bin/alphacp; chmod +x ${ACP}/bin/alphacp
# v1.1: releases/ (backup/failed panel copies) + license/trial store
rm -rf ${ACP}/releases; mkdir -p ${ACP}/releases/panel-backup-20260928224358/app ${ACP}/releases/panel-failed-20260928223644/app
echo '<?php // old backup' > ${ACP}/releases/panel-backup-20260928224358/app/Old.php
echo '<?php // failed' > ${ACP}/releases/panel-failed-20260928223644/app/Failed.php
mkdir -p ${PANEL}/storage/app/private
cat > ${PANEL}/storage/app/private/license.json <<'EOF'
{"source":"local_trial","fingerprint":"deadbeefcafebabe0123456789abcdef","payload":{"license_uid":"TRIAL-DEADBEEFCAFEBABE","tier":"trial","max_accounts":20,"issued_at":"2026-09-28T22:40:00+00:00","expires_at":"2099-10-13T22:40:00+00:00"},"signature":null,"stored_at":"2026-09-28T22:40:00+00:00"}
EOF

# ---------------------------------------------------------------- fake GitHub (bare repo, main = repo ka main)
rm -rf /tmp/syncsim /var/lib/alphacp-sync; mkdir -p /tmp/syncsim
git clone -q --bare "${REPO}" "${REMOTE}"
git -C "${REMOTE}" symbolic-ref HEAD refs/heads/main
git config --global --add safe.directory '*'
BASE_README="$(git -C "${REMOTE}" show main:AI-HANDOFF.md | sha256sum)"

run_sync() { ( cd /root && env PATH="/tmp/acpsim/bin:${PATH}" SYNC_REPO_URL="file://${REMOTE}" SYNC_NO_TIMER=1 bash "${SYNC}" ) > "/tmp/syncsim/run-$1.out" 2>&1; echo $?; }
tree_has()  { git -C "${REMOTE}" cat-file -e "main:$1" 2>/dev/null; }
count()     { git -C "${REMOTE}" rev-list --count main; }

echo; echo "=== Run 1: pehla sync ==="
rc="$(run_sync 1)"; tail -4 /tmp/syncsim/run-1.out | sed 's/^/    | /'
[[ "$rc" == 0 ]] && grep -q "SYNC OK" /tmp/syncsim/run-1.out && t_ok "sync OK (exit 0)" || { t_fail "sync fail rc=$rc"; cat /tmp/syncsim/run-1.out; }
grep -q "v1.4" /tmp/syncsim/run-1.out && t_ok "banner v1.4" || t_fail "banner"
for f in server-snapshot/STATE.md server-snapshot/README.md server-snapshot/LAST-SYNC.md server-snapshot/MANIFEST.txt \
         server-snapshot/files/usr/local/alphacp/panel/app/Services/License/LicenseManager.php \
         server-snapshot/files/usr/local/alphacp/panel/routes/web.php \
         server-snapshot/files/usr/local/alphacp/bin/alphacp \
         server-snapshot/files/opt/alphacp-license/server.php \
         server-snapshot/files/etc/nginx/sites-available/alphacp-panel.conf \
         server-snapshot/files/etc/php/8.4/fpm/pool.d/alphacp.conf \
         server-snapshot/files/etc/systemd/system/php8.4-fpm.service.d/alphacp-panel.conf; do
  tree_has "$f" && t_ok "GitHub par hai: ${f#server-snapshot/}" || t_fail "missing: $f"
done
for f in files/usr/local/alphacp/panel/.env files/usr/local/alphacp/etc files/usr/local/alphacp/var \
         files/usr/local/alphacp/panel/vendor files/usr/local/alphacp/panel/storage \
         files/usr/local/alphacp/license/keys/signing.pem files/usr/local/alphacp/license/embedded_key.php \
         files/usr/local/alphacp/panel/app/Leak.php files/usr/local/alphacp/panel/config/leak2.php \
         files/usr/local/alphacp/panel/database/panel.sqlite; do
  tree_has "server-snapshot/$f" && t_fail "LEAK/heavy push ho gaya: $f" || t_ok "nahi gaya (sahi): $f"
done
# poore GitHub tree me koi server secret?
LEAKS=0
for s in "$DBPASS" "$LIC" "$APPKEY" "$ADMINPW" "BEGIN PRIVATE KEY" "ghp_abcdefghij"; do
  if git -C "${REMOTE}" grep -qF -- "$s" main -- server-snapshot; then t_fail "SECRET GitHub par: ${s:0:6}…"; LEAKS=1; fi
done
[[ $LEAKS == 0 ]] && t_ok "server-snapshot me ek bhi secret value nahi (DB pass, license key, APP_KEY, admin pw, private key, token)"
ST="$(git -C "${REMOTE}" show main:server-snapshot/STATE.md)"
grep -q "Laravel Framework 13.33.0" <<<"$ST" && t_ok "STATE: laravel version" || t_fail "STATE: laravel version nahi"
grep -q "login" <<<"$ST" && t_ok "STATE: routes list" || t_fail "STATE: routes nahi"
grep -q "create_panel_core_tables" <<<"$ST" && t_ok "STATE: migrations" || t_fail "STATE: migrations nahi"
grep -q "alphacp:admin-password" <<<"$ST" && t_ok "STATE: custom artisan commands" || t_fail "STATE: commands nahi"
grep -q "LicenseManager.php" <<<"$ST" && t_ok "STATE: license files list" || t_fail "STATE: license list nahi"
grep -q "license.env  keys: LICENSE_KEY LICENSE_SERVER TRIAL_DAYS" <<<"$ST" && t_ok "STATE: secret file ke sirf KEY naam" || t_fail "STATE: secret keys list nahi"
grep -q "Leak.php  (server secret value mila)" <<<"$ST" && t_ok "STATE: skipped leak file report" || t_fail "STATE: skip report nahi"
# ---- v1.3: snapshot completeness (bare-name prune + secret false-positive fix)
for f in files/usr/local/alphacp/panel/resources/views/backup/index.blade.php \
         files/usr/local/alphacp/panel/resources/views/ssl/index.blade.php; do
  tree_has "server-snapshot/$f" && t_ok "v1.3: ${f##*/views/} snapshot me hai" \
    || t_fail "v1.3 REGRESSION: ${f} snapshot me NAHI (bare-name prune wapas aa gaya?)"
done
tree_has server-snapshot/files/usr/local/alphacp/panel/resources/views/backup/destinations.blade.php \
  && t_ok "v1.3: normal JS wali blade drop NAHI hui (false positive fix)" \
  || t_fail "v1.3 REGRESSION: 'PASSWORD = document.getElementById(...)' wali blade drop ho gayi"
tree_has server-snapshot/files/usr/local/alphacp/panel/resources/views/backup/leaky.conf \
  && t_fail "v1.4: config me 'DB_PASSWORD=...' push ho gaya (secret leak!)" \
  || t_ok "v1.4: config file me DB_PASSWORD= line ab bhi drop hoti hai (kv check)"
grep -q "leaky.conf  (secret jaisa pattern)" <<<"$ST" && t_ok "v1.4: STATE me leaky.conf ka reason" || t_fail "STATE: leaky.conf reason nahi"
grep -q "destinations.blade.php" <<<"$ST" && t_fail "v1.4: 'PASSWORD: password,' wali blade skip list me aa gayi" || t_ok "v1.4: JS object-prop wali blade drop NAHI hui"
grep -q "Snapshot completeness" <<<"$ST" && t_ok "v1.3: STATE me completeness section" || t_fail "STATE: completeness section nahi"
# v1.4: runtime junk prune hona chahiye
tree_has server-snapshot/files/usr/local/alphacp/panel/bootstrap/cache/zz-sync-sim.php \
  && t_fail "v1.4: bootstrap/cache leak ho gaya" || t_ok "v1.4: bootstrap/cache prune hua"
tree_has server-snapshot/files/usr/local/alphacp/logs/verify-report.txt \
  && t_fail "v1.4: logs/ leak ho gaya" || t_ok "v1.4: logs/ prune hua"
tree_has server-snapshot/files/usr/local/alphacp/backups/locks/xyz.lock \
  && t_fail "v1.4: backups/locks leak ho gaya" || t_ok "v1.4: backups/locks prune hua"
grep -q "panel http    : 200" <<<"$ST" && t_ok "STATE: panel http 200" || t_fail "STATE: panel http"
tree_has server-snapshot/files/usr/local/alphacp/releases && t_fail "releases/ snapshot me chala gaya" || t_ok "v1.1: releases/ snapshot me NAHI"
grep -q "panel-backup-20260928224358" <<<"$ST" && grep -q "panel-failed-20260928223644" <<<"$ST" && t_ok "v1.1: STATE me releases ke naam" || t_fail "STATE: releases naam nahi"
grep -q "expires_at : 2099-10-13T22:40:00+00:00   -> valid" <<<"$ST" && grep -q "source     : local_trial" <<<"$ST" && grep -q "signed     : no (local trial)" <<<"$ST" && t_ok "v1.1: STATE me license/trial haalat" || t_fail "STATE: license section nahi"
grep -q "deadbeefcafebabe" <<<"$ST" && t_fail "fingerprint STATE me leak" || t_ok "v1.1: fingerprint STATE me nahi"
grep -qE "panel code    : [0-9]+\.[0-9]+\.[0-9]+" <<<"$ST" && t_ok "v1.1: STATE me panel MANIFEST version" || t_fail "STATE: MANIFEST version nahi"
tree_has server-snapshot/files/usr/local/alphacp/panel/storage/app/private/license.json && t_fail "license.json push ho gaya" || t_ok "license.json push nahi hua"
[[ "$(git -C "${REMOTE}" show main:AI-HANDOFF.md | sha256sum)" == "$BASE_README" ]] && t_ok "repo ki baaki files untouched" || t_fail "AI-HANDOFF.md badal gaya"

echo; echo "=== Run 2: kuch nahi badla ==="
c1="$(count)"; rc="$(run_sync 2)"
[[ "$rc" == 0 && "$(count)" == "$c1" ]] && grep -q "koi badlav nahi" /tmp/syncsim/run-2.out && t_ok "no-change: koi commit nahi" || t_fail "no-change run ne commit kiya / fail (rc=$rc)"

echo; echo "=== Run 3: server par update (file badli) ==="
echo "// trial update" >> ${PANEL}/app/Services/License/LicenseManager.php
rc="$(run_sync 3)"
[[ "$rc" == 0 && "$(count)" == "$((c1+1))" ]] && t_ok "update -> naya commit" || t_fail "update commit nahi (rc=$rc)"
git -C "${REMOTE}" show main:server-snapshot/files/usr/local/alphacp/panel/app/Services/License/LicenseManager.php | grep -q "trial update" \
  && t_ok "GitHub par updated content" || t_fail "updated content nahi"

echo; echo "=== Run 4: GitHub pe kisi ne (AI/PR) aur commit kiya — rebase + push ==="
W=/tmp/syncsim/other; git clone -q "file://${REMOTE}" "$W"; echo "ai work" > "$W/AI-NOTE.md"
git -C "$W" add AI-NOTE.md; git -C "$W" -c user.name=ai -c user.email=ai@x commit -q -m "ai note"; git -C "$W" push -q origin main
echo "// second update" >> ${PANEL}/app/Services/License/LicenseManager.php
rc="$(run_sync 4)"
[[ "$rc" == 0 ]] && tree_has AI-NOTE.md && git -C "${REMOTE}" show main:server-snapshot/files/usr/local/alphacp/panel/app/Services/License/LicenseManager.php | grep -q "second update" \
  && t_ok "dono commits GitHub par (koi overwrite nahi)" || { t_fail "rebase/push fail rc=$rc"; tail -20 /tmp/syncsim/run-4.out; }

echo; echo "=== Run 5: deploy key flow (key add nahi hui) ==="
# sandbox se GitHub SSH blocked hai -> ssh stub: GitHub jaisa jawab (key add hone tak "Permission denied")
mkdir -p /tmp/syncsim/sshbin
cat > /tmp/syncsim/sshbin/ssh <<'EOS'
#!/bin/bash
[[ " $* " == *" -p 22 "* && -f /tmp/syncsim/port22-blocked ]] && { echo "ssh: connect to host github.com port 22: Connection timed out"; exit 255; }
if [[ -f /tmp/syncsim/key-added ]]; then echo "Hi abhay751218-hue/AlphaCP! You've successfully authenticated, but GitHub does not provide shell access."; exit 1; fi
echo "git@github.com: Permission denied (publickey)."; exit 255
EOS
chmod +x /tmp/syncsim/sshbin/ssh
rm -rf /tmp/syncsim/conf /tmp/syncsim/key-added /tmp/syncsim/port22-blocked
( cd /root && PATH="/tmp/syncsim/sshbin:${PATH}" SYNC_CONF_DIR=/tmp/syncsim/conf SYNC_WORK_DIR=/tmp/syncsim/work SYNC_WAIT_SECS=20 SYNC_NO_TIMER=1 \
    script -qec "bash ${SYNC}" /dev/null ) > /tmp/syncsim/run-5.out 2>&1
grep -q "ssh-ed25519 AAAA" /tmp/syncsim/run-5.out && t_ok "deploy key bani + screen par dikhi" || t_fail "key nahi dikhi"
grep -q "settings/keys/new" /tmp/syncsim/run-5.out && t_ok "GitHub add-key link dikha" || t_fail "link nahi"
grep -q "key add nahi hui" /tmp/syncsim/run-5.out && t_ok "GitHub ne key reject ki (expected) -> saaf message" || { t_fail "ssh flow"; tail -15 /tmp/syncsim/run-5.out; }
[[ "$(stat -c %a /tmp/syncsim/conf/github_deploy_key)" == 600 ]] && t_ok "private key 0600" || t_fail "key perms"
grep -q "REMOTE_URL=" /tmp/syncsim/conf/sync.conf && t_ok "remote config: $(cat /tmp/syncsim/conf/sync.conf)" || t_fail "sync.conf"

echo; echo "=== Run 6: user key add karta hai (script wait karti hai) + port 22 band -> 443 ==="
rm -f /tmp/syncsim/key-added; touch /tmp/syncsim/port22-blocked; rm -f /tmp/syncsim/conf/sync.conf
( sleep 12; touch /tmp/syncsim/key-added ) &
( cd /root && PATH="/tmp/syncsim/sshbin:${PATH}" SYNC_CONF_DIR=/tmp/syncsim/conf SYNC_WORK_DIR=/tmp/syncsim/work SYNC_WAIT_SECS=60 SYNC_NO_TIMER=1 \
    script -qec "bash ${SYNC}" /dev/null ) > /tmp/syncsim/run-6.out 2>&1
wait
grep -q "ssh-ed25519" /tmp/syncsim/run-6.out && grep -q "GitHub access OK" /tmp/syncsim/run-6.out && t_ok "key add hone ke baad script khud aage badhi" || { t_fail "wait->OK flow"; tail -12 /tmp/syncsim/run-6.out; }
grep -q "REMOTE_URL=ssh://git@ssh.github.com:443/abhay751218-hue/AlphaCP.git" /tmp/syncsim/conf/sync.conf && t_ok "port 22 band -> 443 fallback" || t_fail "443 fallback"
echo; echo "=== Run 7: timer mode (no terminal) + key hat gayi -> turant saaf error, 30 min wait nahi ==="
rm -f /tmp/syncsim/key-added
( cd /root && PATH="/tmp/syncsim/sshbin:${PATH}" SYNC_CONF_DIR=/tmp/syncsim/conf SYNC_WORK_DIR=/tmp/syncsim/work SYNC_NO_TIMER=1 \
    timeout 60 bash "${SYNC}" </dev/null ) > /tmp/syncsim/run-7.out 2>&1
grep -q "timer mode" /tmp/syncsim/run-7.out && t_ok "timer mode: fast fail" || { t_fail "timer mode"; tail -5 /tmp/syncsim/run-7.out; }

echo; echo "=== Run 8 (v1.2): alphacp-sync get — deploy key se file (private repo me bhi) ==="
# FIX: `git rev-parse <missing-ref>` exit 128 ke SAATH ref ka naam stdout par bhi print karta
# hai, isliye `... 2>/dev/null || rev-parse main` me GC = "<ref-naam>\n<main-sha>" ban jaata tha
# aur Run 8 ke saare `get` tests isi wajah se fail hote the. --verify --quiet se ye saaf hota hai.
GC="$(git -C "${REMOTE}" rev-parse --verify --quiet "refs/heads/arena/01a0ea3e-alphacp^{commit}" 2>/dev/null || true)"
[[ "${GC}" =~ ^[0-9a-f]{40}$ ]] || GC="$(git -C "${REMOTE}" rev-parse main)"
GSHA="$(git -C "${REMOTE}" show "${GC}:START-HERE.md" | sha256sum | cut -d' ' -f1)"
rm -rf /tmp/syncsim/getwork /tmp/syncsim/got*
run_get() { ( cd /root && SYNC_CONF_DIR=/tmp/syncsim/conf SYNC_WORK_DIR=/tmp/syncsim/getwork SYNC_REPO_URL="file://${REMOTE}" bash "${SYNC}" get "$@" ) 2>&1; }
o="$(run_get "${GC}" START-HERE.md /tmp/syncsim/got1 "${GSHA}")"; rc=$?
[[ $rc == 0 ]] && cmp -s /tmp/syncsim/got1 <(git -C "${REMOTE}" show "${GC}:START-HERE.md") && grep -q "verified" <<<"$o" \
  && t_ok "get: sahi file + sha256 verified" || { t_fail "get basic rc=$rc"; echo "$o"; }
grep -q "SERVER → GITHUB SYNC" <<<"$o" && t_fail "get me bada banner (output saaf nahi)" || t_ok "get: output chhota/saaf"
o="$(run_get "${GC}" START-HERE.md /tmp/syncsim/got2 "$(printf '0%.0s' {1..64})")"; rc=$?
[[ $rc != 0 && ! -e /tmp/syncsim/got2 ]] && grep -q "sha256 mismatch" <<<"$o" && t_ok "get: galat sha256 -> fail, file NAHI likhi" || { t_fail "get sha mismatch"; echo "$o"; }
o="$(run_get "${GC}" no/such/file.sh /tmp/syncsim/got3)"; rc=$?
[[ $rc != 0 ]] && grep -q "me nahi hai" <<<"$o" && t_ok "get: missing path -> saaf error" || { t_fail "get missing path"; echo "$o"; }
o="$(run_get "${GC:0:7}" START-HERE.md /tmp/syncsim/got4)"; rc=$?
[[ $rc != 0 ]] && grep -q "40-char" <<<"$o" && t_ok "get: short SHA reject" || { t_fail "get short sha"; echo "$o"; }
o="$(run_get "${GC}" ../../etc/passwd /tmp/syncsim/got5)"; rc=$?
[[ $rc != 0 && ! -e /tmp/syncsim/got5 ]] && t_ok "get: path traversal reject" || { t_fail "get traversal"; echo "$o"; }
o="$(run_get "$(printf 'a%.0s' {1..40})" START-HERE.md /tmp/syncsim/got6)"; rc=$?
[[ $rc != 0 ]] && grep -q "nahi mila" <<<"$o" && t_ok "get: non-existent commit -> saaf error" || { t_fail "get bad commit"; echo "$o"; }
# squash-merge ke baad: commit sirf refs/pull/N/head se reachable
PRT="$(git -C "${REMOTE}" mktree < /dev/null)"; PRBLOB="$(echo 'pr-only file' | git -C "${REMOTE}" hash-object -w --stdin)"
PRT="$(printf '100644 blob %s\tpr.txt\n' "${PRBLOB}" | git -C "${REMOTE}" mktree)"
PRC="$(GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@t git -C "${REMOTE}" commit-tree "${PRT}" -m pr-only)"
git -C "${REMOTE}" update-ref refs/pull/1/head "${PRC}"
o="$(run_get "${PRC}" pr.txt /tmp/syncsim/got7)"; rc=$?
[[ $rc == 0 ]] && grep -q "pr-only file" /tmp/syncsim/got7 && t_ok "get: sirf PR-ref wala commit bhi mila (squash-merge safe)" || { t_fail "get pr ref"; echo "$o"; }
o="$( cd /root && SYNC_CONF_DIR=/tmp/syncsim/noconf SYNC_WORK_DIR=/tmp/syncsim/getwork SYNC_REPO_URL="file://${REMOTE}" bash "${SYNC}" get "${GC}" START-HERE.md /tmp/syncsim/got8 2>&1 )"; rc=$?
[[ $rc != 0 ]] && grep -q "pehle setup" <<<"$o" && t_ok "get: deploy key na ho -> setup ka message" || { t_fail "get no key"; echo "$o"; }

# cleanup nakli files (fake server)
rm -f ${PANEL}/app/Leak.php ${PANEL}/config/leak2.php
rm -f ${PANEL}/resources/views/backup/index.blade.php ${PANEL}/resources/views/backup/destinations.blade.php \
      ${PANEL}/resources/views/backup/leaky.conf ${PANEL}/resources/views/ssl/index.blade.php \
      ${PANEL}/bootstrap/cache/zz-sync-sim.php ${ACP}/logs/verify-report.txt ${ACP}/backups/locks/xyz.lock
rmdir ${PANEL}/bootstrap/cache ${ACP}/logs ${ACP}/backups/locks ${ACP}/backups 2>/dev/null || true
rmdir ${PANEL}/resources/views/backup ${PANEL}/resources/views/ssl 2>/dev/null || true
echo; echo "=== RESULT: ${PASS} pass, ${FAIL} fail ==="
[[ ${FAIL} -eq 0 ]]
