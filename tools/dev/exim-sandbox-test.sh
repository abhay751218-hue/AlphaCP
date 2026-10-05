#!/usr/bin/env bash
# =============================================================================
# AlphaCP — ASLI EXIM SANDBOX TEST (dev tool; server par deploy nahi hota)
#
# Ye script AlphaCP ka exim template render karke use **asli exim binary** se
# validate karta hai — bilkul waisi galtiyan pakadne ke liye jo `exim4 -bV`
# (sirf syntax) nahi pakadta:
#
#   * router/transport option galat (config-time reject)
#   * lookup file missing hone par PANIC/defer
#   * filter ka `save` sach-much folder me jata hai ya nahi (ASLI delivery)
#
# Chalane ka tarika (sandbox me):
#   bash tools/dev/exim-sandbox-test.sh
#
# Zaroori:
#   PHP_BIN   — php CLI (default: $HOME/.tools/phpw/.../php-wasm.js)
#   ACP_EXIM  — asli exim binary (default: $HOME/.tools/exim/bin/exim)
# Delivery test ke liye root chahiye (setuid) — sudo -n ho to khud re-exec.
# =============================================================================
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1

PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); printf '  ok   %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL %s\n' "$1"; }

PHP_BIN="${PHP_BIN:-node $HOME/.tools/phpw/node_modules/@php-wasm/cli/php-wasm.js -d memory_limit=1G}"
ACP_EXIM="${ACP_EXIM:-$HOME/.tools/exim/bin/exim}"

if [[ ! -x "${ACP_EXIM}" ]]; then
  echo "SKIP: asli exim nahi mila (${ACP_EXIM}) — sandbox me build karein, ya ACP_EXIM dein"
  exit 0
fi
if [[ ! -x "${ACP_EXIM%% *}" ]] && ! command -v "${ACP_EXIM%% *}" >/dev/null 2>&1; then
  echo "SKIP: exim binary nahi chala sakta: ${ACP_EXIM}"
  exit 0
fi

# delivery test ko root chahiye (setuid) — sudo ho to re-exec; ASLI uid/gid sath le jao
if [[ "$(id -u)" != "0" ]] && sudo -n true >/dev/null 2>&1; then
  exec sudo -n env PHP_BIN="${PHP_BIN}" ACP_EXIM="${ACP_EXIM}" HOME="${HOME}" \
       ACP_REAL_UID="$(id -u)" ACP_REAL_GID="$(id -g)" \
       bash "$0" "$@"
fi

SB=/tmp/acp-exim-sandbox
CFG_DIR="${ACP_EXIM%/bin/exim}/etc"
rm -rf "$SB"
mkdir -p "$SB/etc/exim4" "$SB/home/info/Maildir/new" "$SB/home/info/Maildir/cur" \
         "$SB/home/info/Maildir/tmp" "$SB/home/info/Maildir/.filtered/new" \
         "$SB/home/info/Maildir/.filtered/cur" "$SB/home/info/Maildir/.filtered/tmp" \
         "$SB/home/info/etc/mail/filter.d" /tmp/eximspool/input /tmp/eximspool/msglog /tmp/eximlog
chmod -R 777 /tmp/eximspool /tmp/eximlog 2>/dev/null

MY_UID="${ACP_REAL_UID:-1001}"; MY_GID="${ACP_REAL_GID:-1001}"   # root (0) never_users me hai
chown -R "$MY_UID:$MY_GID" "$SB" 2>/dev/null || true
echo "acp-sandbox.test" > "$SB/etc/exim4/alphacp-domains"
echo "info@acp-sandbox.test $SB/home/info/Maildir $MY_UID $MY_GID" > "$SB/etc/exim4/alphacp-recipients"
: > "$SB/etc/exim4/alphacp-aliases"
echo "*@acp-sandbox.test: info@acp-sandbox.test" > "$SB/etc/exim4/alphacp-catchall"
FILTER_FILE="$SB/home/info/etc/mail/filter.d/info@acp-sandbox.test.filter"
echo "info@acp-sandbox.test: $FILTER_FILE" > "$SB/etc/exim4/alphacp-filters"

printf 'From: sender@outside.test\nTo: info@acp-sandbox.test\nSubject: acpfilter test\n\nbody\n' > "$SB/msg-filter"
printf 'From: sender@outside.test\nTo: info@acp-sandbox.test\nSubject: normal mail\n\nbody\n'    > "$SB/msg-normal"

# ------------------------------------------------------------------ render ---
ACP_RENDER_ROOT="$SB" $PHP_BIN tools/dev/render-exim-template.php > "$SB/exim.conf" 2>"$SB/render.err"
if [[ ! -s "$SB/exim.conf" ]]; then
  echo "FATAL: template render nahi hua"; sed -n '1,5p' "$SB/render.err"; exit 1
fi
{
  echo "spool_directory = /tmp/eximspool"
  echo "log_file_path = /tmp/eximlog/%slog"
  cat "$SB/exim.conf"
} > "$SB/exim.conf.test"

install_cfg() {   # $1 = config file
  mkdir -p "$CFG_DIR"
  cp "$1" "$CFG_DIR/exim.conf"
  chmod 644 "$CFG_DIR/exim.conf"
  chown root:root "$CFG_DIR/exim.conf" 2>/dev/null || true
}
install_cfg "$SB/exim.conf.test"

count() { ls "$1" 2>/dev/null | wc -l | tr -d ' '; }
clean_boxes() { rm -f "$SB/home/info/Maildir/new/"* "$SB/home/info/Maildir/.filtered/new/"* 2>/dev/null; }
deliver() { clean_boxes; "$ACP_EXIM" -odf -f sender@outside.test info@acp-sandbox.test < "$1" >/dev/null 2>&1; }
panics() { wc -l < /tmp/eximlog/paniclog 2>/dev/null | tr -d ' '; }

echo "=== ASLI EXIM SANDBOX TEST (exim $("$ACP_EXIM" -bV 2>/dev/null | head -1 | awk '{print $3}')) ==="

# ------------------------------------------------------------------- 1. -bV ---
if "$ACP_EXIM" -bV 2>"$SB/bv.err" | grep -q "Exim version"; then
  if grep -qi "configuration error" "$SB/bv.err"; then
    bad "exim -bV: config reject -> $(tr '\n' ' ' < "$SB/bv.err" | cut -c1-160)"
  else
    ok "exim -bV: config accept (syntax + options theek)"
  fi
else
  bad "exim -bV fail"
fi

# -------------------------------------------------------- 2. filter folder ---
cat > "$FILTER_FILE" <<'EOF'
# Exim filter  <<== YE LINE HATAANA NAHI (Exim filter file ki pehchaan)
# AlphaCP managed — haath se edit mat karo
if error_message then finish endif
if $header_subject: contains "acpfilter" then
  save "MAILDIR/.filtered/"
  finish
endif
EOF
sed -i "s|MAILDIR|$SB/home/info/Maildir|" "$FILTER_FILE"
deliver "$SB/msg-filter"
F=$(count "$SB/home/info/Maildir/.filtered/new"); I=$(count "$SB/home/info/Maildir/new")
[[ "$F" == "1" && "$I" == "0" ]] \
  && ok "filter folder: 'acpfilter' mail .filtered/new me gayi (inbox khali)" \
  || bad "filter folder: .filtered/new=$F inbox/new=$I (1/0 hone chahiye)"

# ------------------------------------------------------- 3. filter no-match ---
deliver "$SB/msg-normal"
F=$(count "$SB/home/info/Maildir/.filtered/new"); I=$(count "$SB/home/info/Maildir/new")
[[ "$F" == "0" && "$I" == "1" ]] \
  && ok "filter no-match: normal mail inbox me (false positive nahi)" \
  || bad "filter no-match: .filtered/new=$F inbox/new=$I (0/1 hone chahiye)"

# ------------------------------------------------------------ 4. -bf check ---
if "$ACP_EXIM" -bf "$FILTER_FILE" -f info@acp-sandbox.test < "$SB/msg-filter" 2>&1 | grep -q "Save message to"; then
  ok "exim -bf: filter file valid aur save trigger hota hai"
else
  bad "exim -bf: filter reject ya save nahi hua"
fi
# galat header wala filter reject hona chahiye
cat > "$SB/bad.filter" <<'EOF'
# AlphaCP managed filter — GALAT header (pehli line '# Exim filter' nahi)
if $header_subject: contains "acpfilter" then
  save "MAILDIR/.filtered/"
  finish
endif
EOF
sed -i "s|MAILDIR|$SB/home/info/Maildir|" "$SB/bad.filter"
if "$ACP_EXIM" -bf "$SB/bad.filter" -f info@acp-sandbox.test < "$SB/msg-filter" 2>&1 | grep -q "Save message to"; then
  bad "exim -bf: galat header ke bawajood filter chal gaya (folder me save)"
else
  ok "exim -bf: '# Exim filter' header ke bina filter ke rules lagu nahi hote"
fi

# ------------------------------------------------------------- 5. discard ----
cat > "$FILTER_FILE" <<'EOF'
# Exim filter  <<== YE LINE HATAANA NAHI (Exim filter file ki pehchaan)
if error_message then finish endif
if $header_subject: contains "acpfilter" then
  seen finish
endif
EOF
deliver "$SB/msg-filter"
F=$(count "$SB/home/info/Maildir/.filtered/new"); I=$(count "$SB/home/info/Maildir/new")
[[ "$F" == "0" && "$I" == "0" ]] \
  && ok "filter discard: 'acpfilter' mail kahin nahi pahunchi" \
  || bad "filter discard: .filtered/new=$F inbox/new=$I (0/0 hone chahiye)"

# ------------------------------------------- 6. filter lookup file khali -----
: > "$SB/etc/exim4/alphacp-filters"
P0=$(panics); deliver "$SB/msg-normal"; P1=$(panics)
I=$(count "$SB/home/info/Maildir/new")
[[ "$I" == "1" && "$P1" == "$P0" ]] \
  && ok "filters khali: mail inbox me, koi PANIC nahi" \
  || bad "filters khali: inbox/new=$I, panic $P0 -> $P1"

# ------------------------------------- 7. filter lookup file MISSING (defer?) -
rm -f "$SB/etc/exim4/alphacp-filters"
P0=$(panics); deliver "$SB/msg-normal"; P1=$(panics)
I=$(count "$SB/home/info/Maildir/new")
[[ "$I" == "1" && "$P1" == "$P0" ]] \
  && ok "filters file missing: mail phir bhi inbox me, koi PANIC/defer nahi" \
  || bad "filters file missing: inbox/new=$I, panic $P0 -> $P1"

# ---------------------------------------------------------- 8. catch-all -----
OUT=$("$ACP_EXIM" -bt nobody@acp-sandbox.test 2>&1)
if grep -qi "PANIC\|cannot be resolved\|undeliverable" <<<"$OUT"; then
  bad "catch-all -bt: $(tr '\n' ' ' <<<"$OUT" | cut -c1-160)"
elif grep -q "alphacp_" <<<"$OUT"; then
  ok "catch-all -bt: alphacp router se route hua (bina PANIC)"
else
  bad "catch-all -bt: koi alphacp router nahi: $(tr '\n' ' ' <<<"$OUT" | cut -c1-120)"
fi

# ------------------------------------------------------- 9. mailbox routing --
OUT=$("$ACP_EXIM" -bt info@acp-sandbox.test 2>&1)
grep -q "alphacp_maildir" <<<"$OUT" \
  && ok "mailbox -bt: alphacp_maildir transport tak pahuncha" \
  || bad "mailbox -bt: $(tr '\n' ' ' <<<"$OUT" | cut -c1-160)"

echo
echo "=== ASLI EXIM SANDBOX TEST: ${PASS} pass, ${FAIL} fail ==="
[[ "$FAIL" == "0" ]]
