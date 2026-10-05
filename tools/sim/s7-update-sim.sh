#!/usr/bin/env bash
# =============================================================================
# S7 UPDATER SIM — installer/panel-update.sh ke "S7 Email" block ko bina server
# ke chalata hai (koi package install nahi, /etc ko haath nahi lagta).
#
# Block installer/panel-update.sh se hi nikaala jata hai (copy nahi) — isliye
# script badle to ye simulation bhi usi ko test karega.
#
#   case 1 : purana agent (mail.server nahi)  -> services BAND + info
#   case 2 : naya agent + setup OK            -> setup + dono services active
#   case 3 : setup FAIL                       -> stop/disable + warn (update chalu)
#   case 4 : packages missing -> apt install  -> install OK
#   case 5 : apt install FAIL                 -> warn, update aage badhe
#   case 6 : install ke baad binaries mil gaye-> setup + services active
# =============================================================================
set -uo pipefail

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
UPDATER="${REPO}/installer/panel-update.sh"
W="${TMPDIR:-/tmp}/acp-s7upd-$$"
BIN="$W/bin"; ACP="$W/acp"; EMPTY="$W/empty"
mkdir -p "$BIN" "$ACP/agent/bin" "$ACP/agent/config" "$ACP/etc" "$EMPTY"
export PATH="$BIN:$PATH"

# ---------------------------------------------------------------- stubs ------
cat > "$BIN/systemctl" <<'EOF'
#!/usr/bin/env bash
[[ "${SIM_CALLS:-}" ]] && echo "systemctl $*"
exit 0
EOF
cat > "$BIN/debconf-set-selections" <<'EOF'
#!/usr/bin/env bash
cat >/dev/null; exit 0
EOF
cat > "$BIN/hostname" <<'EOF'
#!/usr/bin/env bash
echo sim.example.test
EOF
cat > "$BIN/apt-get" <<'EOF'
#!/usr/bin/env bash
if [[ "${SIM_APT_FAIL:-0}" == "1" ]]; then echo "apt-get FAIL $*" >&2; exit 1; fi
mkdir -p "${SIM_SBIN}"
for b in exim4 dovecot doveadm doveconf; do
  cat > "${SIM_SBIN}/${b}" <<'BINEOF'
#!/usr/bin/env bash
[[ "${1:-}" == "-bV" ]] && { echo "Exim version 4.97 (sim)"; exit 0; }
[[ "${1:-}" == "--version" ]] && { echo "2.3.21 (sim)"; exit 0; }
exit 0
BINEOF
  chmod 755 "${SIM_SBIN}/${b}"
done
exit 0
EOF
cat > "$BIN/php" <<'EOF'
#!/usr/bin/env bash
if [[ "${SIM_MAIL_SETUP_FAIL:-0}" == "1" ]]; then
  echo '{"status":"failed","error":"sim setup fail"}'; exit 1
fi
[[ "${3:-}" == "mail.server" ]] && touch "${ACP_HOME}/etc/mail-server-configured"
echo '{"status":"success","task_id":1}'
exit 0
EOF
for b in exim4 dovecot doveadm doveconf; do
  cat > "$BIN/$b" <<'EOF'
#!/usr/bin/env bash
[[ "${1:-}" == "-bV" ]] && { echo "Exim version 4.97 (sim)"; exit 0; }
[[ "${1:-}" == "--version" ]] && { echo "2.3.21 (sim)"; exit 0; }
exit 0
EOF
  chmod 755 "$BIN/$b"
done
chmod 755 "$BIN"/*

# ------------------------------------------------- updater se block nikaalo ---
BIN="$BIN" ACP="$ACP" W="$W" python3 - "$UPDATER" "$W/block.sh" <<'PY'
import os, pathlib, sys
src = pathlib.Path(sys.argv[1]).read_text().splitlines(keepends=True)
start = next(i for i, l in enumerate(src) if 'MAIL_MISSING=""' in l) - 1
end = next(j for j in range(start, len(src))
           if src[j].strip() == 'fi' and src[j + 2].startswith('NEW_PANEL='))
block = ''.join(src[start:end + 1])
for p in ('/usr/sbin/exim4', '/usr/sbin/dovecot', '/usr/bin/doveadm', '/usr/sbin/doveconf'):
    block = block.replace(p, '${SIM_SBIN}/' + p.rsplit('/', 1)[1])
head = '''#!/usr/bin/env bash
set -uo pipefail
export SIM_SBIN="${SIM_SBIN:-%(bin)s}"
export PATH="%(bin)s:$PATH"
export ACP_HOME="%(acp)s"
LOG_FILE="%(log)s"; PHP_BIN="%(php)s"
: > "$LOG_FILE"
say() { echo "SAY  $*"; }
info() { echo "INFO $*"; }
ok() { echo "OK   $*"; }
warn() { echo "WARN $*"; }
''' % {'bin': os.environ['BIN'], 'acp': os.environ['ACP'],
       'log': os.path.join(os.environ['W'], 'log.txt'),
       'php': os.path.join(os.environ['BIN'], 'php')}
pathlib.Path(sys.argv[2]).write_text(head + block)
PY
[[ -s "$W/block.sh" ]] || { echo "block extract fail"; exit 1; }
bash -n "$W/block.sh" || { echo "block syntax fail"; exit 1; }

PASS=0; FAIL=0
chk() { # desc, expected-marker, pattern
  local desc="$1" marker="$2" pattern="$3" out="$4"
  if grep -q -- "$pattern" <<<"$out" && { [[ "$marker" == "-" ]] || [[ "$marker" == "$( [[ -f "$ACP/etc/mail-server-configured" ]] && echo present || echo absent)" ]]; }; then
    PASS=$((PASS + 1)); echo "  ok   $desc"
  else
    FAIL=$((FAIL + 1)); echo "  FAIL $desc"
    echo "$out" | sed 's/^/       | /'
  fi
}
run_case() { # $1 = sbin dir (binaries kahan hain) ; baaki = env overrides
  local sbin="$1"; shift
  rm -rf "$EMPTY"; mkdir -p "$EMPTY"   # har case saaf slate se
  env SIM_SBIN="$sbin" "$@" bash "$W/block.sh" 2>&1
}

echo "== S7 updater block SIM =="

# case 1: purana agent -> mail.server nahi -> band
printf '<?php\nreturn [];\n' > "$ACP/agent/config/tasks.php"
rm -f "$ACP/etc/mail-server-configured"
out="$(run_case "$BIN" SIM_CASE=1)"
chk "purane agent par services BAND rehte hain" absent "mail services abhi band hain" "$out"

# case 2: naya agent + setup OK
printf "<?php\nreturn ['mail.server' => []];\n" > "$ACP/agent/config/tasks.php"
rm -f "$ACP/etc/mail-server-configured"
out="$(run_case "$BIN" SIM_CASE=2)"
chk "naye agent par mail.server setup chalta hai" present "mail.server setup ho gaya" "$out"
chk "exim4 service active dikhta hai" present "exim4 service active" "$out"
chk "dovecot service active dikhta hai" present "dovecot service active" "$out"

# case 3: setup fail
rm -f "$ACP/etc/mail-server-configured"
out="$(run_case "$BIN" SIM_CASE=3 SIM_MAIL_SETUP_FAIL=1)"
chk "setup fail par services band + warn" absent "mail.server setup fail" "$out"

# case 4: packages missing -> apt install
rm -f "$ACP/etc/mail-server-configured"
out="$(run_case "$EMPTY" SIM_CASE=4)"
# install ke baad binaries mil gaye to setup bhi chalega (isliye marker = present)
chk "packages missing par apt install + setup hota hai" present "exim4 + dovecot install ho rahe hain" "$out"

# case 5: apt fail
rm -f "$ACP/etc/mail-server-configured"
out="$(run_case "$EMPTY" SIM_CASE=5 SIM_APT_FAIL=1)"
chk "apt fail par warn, update nahi rukta" absent "mail packages install fail" "$out"

# case 6: install ke baad binaries mil gaye -> setup
rm -f "$ACP/etc/mail-server-configured"
out="$(run_case "$EMPTY" SIM_CASE=6)"
chk "install ke baad setup + services active" present "mail.server setup ho gaya" "$out"

rm -rf "$W"
echo
echo "=== S7 UPDATER BLOCK SIM: $PASS pass, $FAIL fail ==="
[[ $FAIL -eq 0 ]]
