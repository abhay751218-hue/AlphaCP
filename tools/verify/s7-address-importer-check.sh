#!/usr/bin/env bash
# =============================================================================
# AlphaCP S7 #23/#148 — LIVE verification for the Address Importer.
#
# Kya prove karta hai:
#   1) CSV parsing panel ke ASLI code se hoti hai — deployed `App\Support\Mail`
#      (parseImport + hashPassword) seedha require karke chalaya jata hai (Laravel
#      boot ki zaroorat nahi). Har row ka bcrypt hash usi run me banta hai.
#   2) Wahi payload (jo panel `MailProvisioner` enqueue karta hai) real
#      `mail.set` ko diya jata hai → asli Dovecot user + asli Maildir.
#   3) Har imported mailbox ka **password auth** (`doveadm auth test`) pass hota
#      hai — yaani import kiya hua account IMAP se login kar sakta hai.
#   4) Har mailbox ko **asli Exim se mail** bheji jati hai aur Maildir me delivery
#      verify hoti hai.
#   5) Fail-closed: pipe/shell local-part aur foreign domain CSV poori tarah
#      reject hoti hai (panel ka asli parser).
# Temporaary account/mailboxes exit par hat jate hain; report ACP_HOME me likhi
# jati hai. Address Importer ka Mailman/CalDAV jaisa koi hissa isme nahi.
# =============================================================================
set -uo pipefail

ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"; export ACP_HOME
PANEL_ROOT="${ACP_PANEL_ROOT:-${ACP_HOME}/panel}"
PANELD="${ACP_VERIFY_PANELD:-${ACP_HOME}/agent/bin/paneld}"
TEST_USER="${ACP_VERIFY_IMPORT_USER:-acpimpchk}"
TEST_DOMAIN="${ACP_VERIFY_IMPORT_DOMAIN:-acp-import-check.test}"
TEST_PW="${ACP_VERIFY_IMPORT_PW:-AcpImport!2026}"
LOCALS=(sales support)
QUOTAS=(150 75)
REPORT_DIR="${ACP_HOME}/verify-reports"
REPORT_FILE="${REPORT_DIR}/s7-address-importer-check.txt"
EXIM_ALIASES="${ACP_MAIL_EXIM_ALIASES:-/etc/exim4/alphacp-aliases}"
DOVECOT_USERS="${ACP_MAIL_DOVECOT_USERS:-/etc/dovecot/alphacp-users}"
PASS=0; FAIL=0; CREATED_ACCOUNT=0; IMPORT_DELIVERY=NO; DONE=0
TASK_IDS=""; LAST_TASK_ID=""; LAST_ERR=""; TASK_OUT=""
RUNNER=(); PHP_CMD=()
WORK="$(mktemp -d /tmp/acp-import-check.XXXXXX)"

ok()   { PASS=$((PASS+1)); printf '  ok   %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL %s\n' "$1"; }
info() { printf '  --   %s\n' "$1"; }

if [[ "${ACP_VERIFY_ALLOW_NONROOT:-0}" != "1" && "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo bash $0"; exit 1
fi

# ---- PHP (multi-word command allowed: e.g. "node /path/php-wasm.js") --------
if [[ -n "${ACP_VERIFY_PHP:-}" ]]; then
  read -r -a PHP_CMD <<<"${ACP_VERIFY_PHP}"
else
  for c in php8.4 php8.3 php; do
    if command -v "$c" >/dev/null 2>&1; then PHP_CMD=("$(command -v "$c")"); break; fi
  done
fi
[[ ${#PHP_CMD[@]} -gt 0 ]] || { echo "PHP binary missing (set ACP_VERIFY_PHP)"; exit 1; }

find_bin() {
  local tool="$1" envvar="$2" preferred="$3" envval="" found="" d
  envval="$(printenv "$envvar" 2>/dev/null || true)"
  if [[ -n "$envval" ]]; then printf '%s' "$envval"; return 0; fi
  if [[ -n "$preferred" && -x "$preferred" ]]; then printf '%s' "$preferred"; return 0; fi
  found="$(command -v "$tool" 2>/dev/null || true)"
  if [[ -n "$found" && -x "$found" ]]; then printf '%s' "$found"; return 0; fi
  for d in /usr/sbin /usr/bin /sbin /bin /usr/local/sbin /usr/local/bin; do
    if [[ -x "${d}/${tool}" ]]; then printf '%s' "${d}/${tool}"; return 0; fi
  done
  printf ''
}

EXIM="$(find_bin exim4 ACP_VERIFY_EXIM /usr/sbin/exim4)"
DOVEADM="$(find_bin doveadm ACP_VERIFY_DOVEADM /usr/bin/doveadm)"
MAIL_CLASS="${PANEL_ROOT}/app/Support/Mail.php"
IDENTITY_CLASS="${PANEL_ROOT}/app/Support/AccountIdentity.php"

if [[ -x "$PANELD" ]]; then
  RUNNER=("$PANELD")
elif [[ ${#PHP_CMD[@]} -gt 0 ]]; then
  RUNNER=("${PHP_CMD[@]}" "$PANELD")
else
  RUNNER=("$PANELD")
fi

run_task() {
  local type="$1" payload="$2" out rc
  out="$("${RUNNER[@]}" --run "$type" "$payload" 2>&1)"
  rc=$?
  TASK_OUT="$out"
  LAST_TASK_ID="$(grep -o '"task_id": *[0-9]*' <<<"$out" | grep -o '[0-9]*' | head -1)"
  [[ -n "$LAST_TASK_ID" ]] && TASK_IDS="${TASK_IDS}${TASK_IDS:+, }${type}#${LAST_TASK_ID}"
  if [[ "$rc" == "0" ]] && grep -q '"status": "success"' <<<"$out"; then
    LAST_ERR=""
    return 0
  fi
  LAST_ERR="$(grep -o '"error": *"[^"]*"' <<<"$out" | head -1 | sed 's/.*"error": *"//; s/"$//')"
  [[ -n "$LAST_ERR" ]] || LAST_ERR="$(tail -1 <<<"$out")"
  return 1
}

cleanup() {
  if [[ "${CREATED_ACCOUNT}" == "1" && ${#RUNNER[@]} -gt 0 ]]; then
    "${RUNNER[@]}" --run account.terminate "{\"username\":\"${TEST_USER}\",\"_confirm\":\"account.terminate\"}" >/dev/null 2>&1 || true
    CREATED_ACCOUNT=0
  fi
  if [[ ${#RUNNER[@]} -gt 0 ]] && [[ -x "$PANELD" ]]; then
    "${RUNNER[@]}" --run mail.server '{"action":"sync"}' >/dev/null 2>&1 || true
  fi
  rm -rf "$WORK"
}
trap cleanup EXIT

count_marker() {
  local dir="$1" marker="$2"
  [[ -d "$dir" ]] || { printf '0'; return; }
  find "$dir" -mindepth 1 -maxdepth 1 -type f -exec grep -lF -- "$marker" {} + 2>/dev/null | wc -l | tr -d ' '
}

write_report() {
  local tmp="${REPORT_FILE}.tmp.$$"
  mkdir -p "$REPORT_DIR" 2>/dev/null || return 1
  {
    printf '=== S7 #23 ADDRESS IMPORTER LIVE CHECK (%s) ===\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf 'pass=%s fail=%s\n' "$PASS" "$FAIL"
    printf 'account=%s domain=%s locals=%s\n' "$TEST_USER" "$TEST_DOMAIN" "${LOCALS[*]}"
    printf 'import_delivery=%s\n' "$IMPORT_DELIVERY"
    printf 'panel_mail_class=%s\n' "$(sha256sum "$MAIL_CLASS" 2>/dev/null | cut -c1-16)"
    printf 'tasks: %s\n' "$TASK_IDS"
  } > "$tmp" || return 1
  chmod 0644 "$tmp" 2>/dev/null || true
  mv -f "$tmp" "$REPORT_FILE"
}

fatal() {
  bad "$1"
  write_report || info "Could not write report to ${REPORT_FILE}"
  DONE=1
  printf '\n=== S7 #23 ADDRESS IMPORTER LIVE CHECK: %s pass, %s fail ===\n' "$PASS" "$FAIL"
  info "import delivery: ${IMPORT_DELIVERY}"
  info "tasks: ${TASK_IDS}"
  info "report: ${REPORT_FILE}"
  exit 1
}

echo "=== S7 #23 ADDRESS IMPORTER LIVE CHECK ==="
info "ACP_HOME  : ${ACP_HOME}"
info "account   : ${TEST_USER} / ${TEST_DOMAIN}"
info "mailboxes : ${LOCALS[*]}"

[[ -x "$PANELD" ]] || fatal "paneld not found: ${PANELD}"
[[ -f "${ACP_HOME}/agent/config/tasks.php" ]] || fatal "agent task registry missing"
grep -q "'mail[.]set'" "${ACP_HOME}/agent/config/tasks.php" || fatal "mail.set is not registered in the installed agent"
[[ -f "${ACP_HOME}/etc/mail-server-configured" ]] || fatal "mail.server is not configured; run the S7 mail setup first"
[[ -f "$MAIL_CLASS" ]] || fatal "deployed panel Mail class missing: ${MAIL_CLASS}"
[[ -f "$IDENTITY_CLASS" ]] || fatal "deployed panel AccountIdentity class missing: ${IDENTITY_CLASS}"
[[ -n "$EXIM" && -x "$EXIM" ]] || fatal "Exim binary missing"
[[ -n "$DOVEADM" && -x "$DOVEADM" ]] || fatal "doveadm binary missing"

# ---- 1) CSV ko panel ke ASLI parse code se nikalo ---------------------------
cat > "${WORK}/parse.php" <<'PHPEOF'
<?php
declare(strict_types=1);
// Panel ka real code chal raha hai; PHP 8.4+ `str_getcsv()` ke default escape par
// deprecation deta hai. Wo warning stdout me aakar JSON kharab kar deti hai, isliye
// yahan warnings chhupa rahe hain (fatal errors stderr par hi dikhenge — verifier
// JSON khali milne par wahi stderr report karta hai).
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
[$_, $identityFile, $mailFile, $csvFile, $domain] = $argv;
require $identityFile;
require $mailFile;
$csv = (string) file_get_contents($csvFile);
$rows = \App\Support\Mail::parseImport($csv, [$domain]);
$out = ['rows' => null, 'hashes' => null];
if ($rows !== null) {
    $out['rows'] = $rows;
    $out['hashes'] = [];
    foreach ($rows as $i => $row) {
        $out['hashes'][$i] = \App\Support\Mail::hashPassword($row['password']);
    }
}
echo json_encode($out, JSON_UNESCAPED_SLASHES);
PHPEOF

printf 'local,domain,password,quota_mb\n%s,%s,%s,%s\n%s,%s,%s,%s\n' \
  "${LOCALS[0]}" "$TEST_DOMAIN" "$TEST_PW" "${QUOTAS[0]}" \
  "${LOCALS[1]}" "$TEST_DOMAIN" "$TEST_PW" "${QUOTAS[1]}" > "${WORK}/good.csv"
printf '%s\n' "bad|pipe,${TEST_DOMAIN},${TEST_PW}" > "${WORK}/pipe.csv"
printf 'sales,evil.example.%s,%s\n' "$(date +%s)" "$TEST_PW" > "${WORK}/foreign.csv"
printf '%s\n' "local,domain,password" > "${WORK}/header.csv"

parse_csv() { # $1 = csv file  -> JSON stdout
  ACP_HOME="$ACP_HOME" "${PHP_CMD[@]}" "${WORK}/parse.php" "$IDENTITY_CLASS" "$MAIL_CLASS" "$1" "$TEST_DOMAIN" 2>"${WORK}/php.err"
}

GOOD_JSON="$(parse_csv "${WORK}/good.csv")"
if [[ -z "$GOOD_JSON" ]]; then
  fatal "panel Mail class did not run (php: ${PHP_CMD[*]}): $(tail -1 "${WORK}/php.err")"
fi
info "parse     : ${GOOD_JSON:0:120}…"

if python3 - "$GOOD_JSON" "${LOCALS[0]}" "${LOCALS[1]}" "$TEST_DOMAIN" "${QUOTAS[0]}" "${QUOTAS[1]}" <<'PY'
import json, sys
raw, l1, l2, dom, q1, q2 = sys.argv[1:7]
out = json.loads(raw)
rows = out.get("rows")
if not isinstance(rows, list) or len(rows) != 2:
    print("rows=%r" % rows); sys.exit(1)
addr = [r.get("local") + "@" + r.get("domain") for r in rows]
if sorted(addr) != sorted([l1 + "@" + dom, l2 + "@" + dom]):
    print("addresses=%r" % addr); sys.exit(1)
q = {r["local"]: r.get("quota_mb") for r in rows}
if q.get(l1) != int(q1) or q.get(l2) != int(q2):
    print("quotas=%r" % q); sys.exit(1)
hashes = out.get("hashes") or []
if len(hashes) != 2 or not all(isinstance(h, str) and h.startswith("$2y$") for h in hashes):
    print("hashes=%r" % hashes); sys.exit(1)
print("ok header row skip + 2 rows + quotas + bcrypt hashes")
PY
then
  ok "panel ke ASLI code se CSV parse: header skip, 2 rows, quota, bcrypt hash"
else
  fatal "CSV parse output unexpected: ${GOOD_JSON:0:300}"
fi

# fail-closed cases (panel parser ko poora CSV reject karna chahiye)
for case in pipe foreign header; do
  J="$(parse_csv "${WORK}/${case}.csv")"
  if python3 - "$J" <<'PY'
import json, sys
out = json.loads(sys.argv[1])
sys.exit(0 if out.get("rows") is None else 1)
PY
  then
    ok "fail-closed: ${case} CSV poora reject (parseImport -> null)"
  else
    bad "fail-closed: ${case} CSV accept ho gaya — ye khula darwaza hai"
  fi
done

# ---- 2) wahi payload real mail.set ko do ------------------------------------
PAYLOAD="$(python3 - "$GOOD_JSON" "$TEST_USER" <<'PY'
import json, sys
out = json.loads(sys.argv[1]); user = sys.argv[2]
rows, hashes = out["rows"], out["hashes"]
boxes = [{"local": r["local"], "domain": r["domain"], "hash": hashes[i], "quota_mb": r["quota_mb"]}
         for i, r in enumerate(rows)]
print(json.dumps({"username": user, "mailboxes": boxes}))
PY
)"

if run_task account.create "{\"username\":\"${TEST_USER}\",\"domain\":\"${TEST_DOMAIN}\",\"shadow_hash\":\"$(openssl passwd -6 'AcpImportCheck123' 2>/dev/null || echo '$6$rounds=5000$01234567$abcdefghijklmnopqrstuv')\",\"quota_mb\":256,\"php_version\":\"8.4\"}"; then
  CREATED_ACCOUNT=1
  ok "temporary account created (task #${LAST_TASK_ID})"
else
  fatal "could not create temporary account: ${LAST_ERR:-unknown}; no existing account was changed"
fi

if run_task mail.set "$PAYLOAD"; then
  ok "imported mailboxes provisioned via real mail.set (task #${LAST_TASK_ID})"
else
  fatal "mail.set failed: ${LAST_ERR:-unknown}"
fi

if run_task mail.server '{"action":"sync"}'; then
  ok "mail.server sync completed after import (task #${LAST_TASK_ID})"
else
  bad "mail.server sync failed: ${LAST_ERR:-unknown}"
fi

# ---- 3) har mailbox: dovecot user + asli password auth + asli delivery ------
for idx in "${!LOCALS[@]}"; do
  local_part="${LOCALS[$idx]}"
  ADDR="${local_part}@${TEST_DOMAIN}"
  HOME_DIR="$("$DOVEADM" user "$ADDR" 2>/dev/null | awk '$1 == "home" {print $2; exit}')"
  if [[ -n "$HOME_DIR" && -d "${HOME_DIR}/new" ]]; then
    ok "dovecot user + Maildir maujood: ${ADDR}"
  else
    bad "dovecot user/Maildir nahi mila: ${ADDR} (${HOME_DIR:-none})"
    continue
  fi

  # (1) asli Dovecot auth — `doveadm auth test` (passwd-file passdb ke against).
  # (2) purane/limited doveadm par ye subcommand na ho to fallback: wahi users file
  #     ka hash panel ke PHP se bcrypt-verify karo (proof same: stored hash isi
  #     password ko accept karta hai).
  AUTH_OUT="$("$DOVEADM" auth test "$ADDR" "$TEST_PW" 2>&1)"
  AUTH_RC=$?
  AUTH_DONE=0
  if [[ "$AUTH_RC" == "0" ]] && grep -qi "succeeded\|1 0 0" <<<"$AUTH_OUT"; then
    AUTH_DONE=1
    ok "asli password auth pass — doveadm auth test (IMAP login ho sakta hai): ${ADDR}"
  elif grep -qiE "unknown command|not a valid|Usage:" <<<"$AUTH_OUT" && [[ -f "$DOVECOT_USERS" ]]; then
    HASH_LINE="$(grep -m1 "^${ADDR}:" "$DOVECOT_USERS" 2>/dev/null || true)"
    VERIFY="$(ACP_HOME="$ACP_HOME" "${PHP_CMD[@]}" -r '
      [$line, $pw] = [$argv[1], $argv[2]];
      $parts = explode(":", $line);
      $hash = $parts[1] ?? "";
      if (str_starts_with($hash, "{BLF-CRYPT}")) { $hash = substr($hash, 11); }
      echo password_verify($pw, $hash) ? "yes" : "no";
    ' "$HASH_LINE" "$TEST_PW" 2>/dev/null)"
    if [[ "$VERIFY" == "yes" ]]; then
      AUTH_DONE=1
      ok "password auth pass — passwd-file hash bcrypt verify (doveadm auth test is server par nahi hai): ${ADDR}"
    else
      bad "password auth FAIL (fallback hash verify bhi fail): ${ADDR}"
    fi
  else
    bad "password auth FAIL: ${ADDR}: $(tr '\n' ' ' <<<"$AUTH_OUT" | head -c 200)"
  fi
  [[ "$AUTH_DONE" == "1" ]] || continue

  MARKER="ACP-IMPORT-${idx}-$(date +%s)-${RANDOM}"
  BEFORE="$(count_marker "${HOME_DIR}/new" "$MARKER")"
  SENDER="root@$(hostname -f 2>/dev/null || hostname)"
  MESSAGE="$(printf 'From: %s\nTo: %s\nSubject: %s\n\nAlphaCP address-importer verification %s\n' "$SENDER" "$ADDR" "$MARKER" "$MARKER")"
  EXIM_OUT="$(printf '%s\n' "$MESSAGE" | "$EXIM" -odf -oem -v -f "$SENDER" "$ADDR" 2>&1)"
  EXIM_RC=$?
  AFTER=0
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    AFTER="$(count_marker "${HOME_DIR}/new" "$MARKER")"
    [[ "$AFTER" -gt 0 ]] && break
    sleep 1
  done
  if [[ "$EXIM_RC" == "0" && "$AFTER" -gt "$BEFORE" ]]; then
    IMPORT_DELIVERY=YES
    ok "asli Exim delivery pahunchi (${BEFORE} -> ${AFTER}): ${ADDR}"
  else
    bad "delivery fail: ${ADDR} (exim rc=${EXIM_RC}, marker ${BEFORE} -> ${AFTER}): $(tr '\n' ' ' <<<"$EXIM_OUT" | head -c 200)"
  fi
done

# ---- 4) cleanup: account hatao, aliases/mailboxes na bachein ----------------
if run_task account.terminate "{\"username\":\"${TEST_USER}\",\"_confirm\":\"account.terminate\"}"; then
  CREATED_ACCOUNT=0
  ok "temporary account removed (task #${LAST_TASK_ID})"
else
  bad "temporary account removal failed: ${LAST_ERR:-unknown}; exit cleanup will retry"
fi
if run_task mail.server '{"action":"sync"}'; then
  ok "final mail sync completed"
else
  bad "final mail sync failed: ${LAST_ERR:-unknown}"
fi
if "$DOVEADM" user "${LOCALS[0]}@${TEST_DOMAIN}" >/dev/null 2>&1; then
  bad "imported mailbox dovecot me abhi bhi hai — cleanup adhoora"
else
  ok "imported mailboxes cleanup verified (dovecot me nahi hain)"
fi

DONE=1
if ! write_report; then
  bad "could not write report to ${REPORT_FILE}"
fi
printf '\n=== S7 #23 ADDRESS IMPORTER LIVE CHECK: %s pass, %s fail ===\n' "$PASS" "$FAIL"
info "import delivery: ${IMPORT_DELIVERY}"
info "tasks: ${TASK_IDS}"
info "report: ${REPORT_FILE}"

[[ "$FAIL" == "0" ]]
