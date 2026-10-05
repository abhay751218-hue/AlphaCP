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
#   * SpamAssassin: spamd-down fail-open + asla score/header/reject via SPAMD protocol
#   * greylistd: socket fail-open, true=>451, false=>accepted, null sender bypass
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

PASS=0; FAIL=0; SKIP=0
ok()   { PASS=$((PASS+1)); printf '  ok   %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL %s\n' "$1"; }
skip() { SKIP=$((SKIP+1)); printf '  SKIP %s\n' "$1"; }

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
         "$SB/home/info/etc/mail/filter.d" "$SB/alphacp/etc/mail" \
         "$SB/etc/spamassassin" "$SB/run/greylistd" \
         /tmp/eximspool/input /tmp/eximspool/msglog /tmp/eximlog
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
cat > "$SB/smtp-data.txt" <<'EOF'
EHLO sender.example
MAIL FROM:<sender@outside.test>
RCPT TO:<info@acp-sandbox.test>
DATA
From: sender@outside.test
To: info@acp-sandbox.test
Subject: ACL integration test

body
.
QUIT
EOF

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
DELIVERY_OUT=""; DELIVERY_RC=0
deliver_verbose() {
  clean_boxes
  DELIVERY_OUT="$("$ACP_EXIM" -odf -f sender@outside.test -v info@acp-sandbox.test < "$1" 2>&1)"
  DELIVERY_RC=$?
}
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

# Reproduce the live 0750 root-owned ~/etc parent that blocked euid=mailbox uid.
ETC_PARENT="$SB/home/info/etc"
chown root:root "$ETC_PARENT" 2>/dev/null || true
chmod 0750 "$ETC_PARENT"
deliver_verbose "$SB/msg-filter"
BLOCKED_FILTERS=$(count "$SB/home/info/Maildir/.filtered/new"); BLOCKED_INBOX=$(count "$SB/home/info/Maildir/new")
BLOCKED_ID="$(sed -n 's/.*delivering \([^[:space:]]*\).*/\1/p' <<<"$DELIVERY_OUT" | head -1)"
if [[ "$DELIVERY_RC" == "0" && "$BLOCKED_FILTERS" == "0" && "$BLOCKED_INBOX" == "0" && "$DELIVERY_OUT" == *"Permission denied"* && -n "$BLOCKED_ID" ]]; then
  ok "permission regression: root-owned ~/etc 0750 blocks mailbox uid exactly as live (queue $BLOCKED_ID)"
else
  bad "permission regression: expected Exim EACCES/defer; rc=$DELIVERY_RC filtered=$BLOCKED_FILTERS inbox=$BLOCKED_INBOX id=${BLOCKED_ID:-NAHI}"
fi
if [[ -n "$BLOCKED_ID" ]] && "$ACP_EXIM" -Mrm "$BLOCKED_ID" >/dev/null 2>&1; then
  ok "permission regression: deferred synthetic message removed from sandbox queue"
else
  bad "permission regression: deferred synthetic message queue cleanup failed"
fi
if chgrp "$MY_GID" "$ETC_PARENT" 2>/dev/null; then
  chmod 0710 "$ETC_PARENT"
else
  chmod 0751 "$ETC_PARENT"
fi

deliver_verbose "$SB/msg-filter"
F=$(count "$SB/home/info/Maildir/.filtered/new"); I=$(count "$SB/home/info/Maildir/new")
if [[ "$DELIVERY_RC" == "0" && "$F" == "1" && "$I" == "0" && "$DELIVERY_OUT" == *"$SB/home/info/Maildir/.filtered/"* && "$DELIVERY_OUT" == *"Completed"* ]]; then
  ok "filter folder: actual Exim -v + Maildir counts confirm .filtered delivery"
else
  bad "filter folder: rc=$DELIVERY_RC .filtered/new=$F inbox/new=$I; exim=$(tr '\n' ' ' <<<"$DELIVERY_OUT" | cut -c1-180)"
fi

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
deliver_verbose "$SB/msg-filter"
F=$(count "$SB/home/info/Maildir/.filtered/new"); I=$(count "$SB/home/info/Maildir/new")
if [[ "$DELIVERY_RC" == "0" && "$F" == "0" && "$I" == "0" && "$DELIVERY_OUT" == *"=> discarded"* && "$DELIVERY_OUT" == *"Completed"* ]]; then
  ok "filter discard: Exim -v confirms userfilter discard; neither Maildir received it"
else
  bad "filter discard: rc=$DELIVERY_RC .filtered/new=$F inbox/new=$I; exim=$(tr '\n' ' ' <<<"$DELIVERY_OUT" | cut -c1-180)"
fi

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

# ---------------------------------------- 10. SpamAssassin fail-open/tag-only --
# `spam =` sirf Content_Scanning Exim me hota hai. Renderer isi binary capability
# check karta hai; system par light daemon ho to is part ko SKIP (mail config safe).
SPAMD_BIN="/usr/sbin/exim4"
if [[ -x "$SPAMD_BIN" ]] && "$SPAMD_BIN" -bV 2>&1 | grep -q 'Content_Scanning'; then
  printf '{"spam_enabled":"yes","greylisting":"no","spam_score_limit":"0"}\n' > "$SB/alphacp/etc/mail/exim-options.json"
  SOCK="$SB/run/greylistd/socket"
  ACP_RENDER_ROOT="$SB" ACP_MAIL_EXIM="$SPAMD_BIN" ACP_MAIL_SPAMD=1 \
    ACP_MAIL_GREYLISTD_SOCKET="$SOCK" $PHP_BIN tools/dev/render-exim-template.php > "$SB/exim.spam.conf" 2>"$SB/render-spam.err"
  {
    echo "spool_directory = /tmp/eximspool"
    echo "log_file_path = /tmp/eximlog/%slog"
    echo "spamd_address = 127.0.0.1 65534" # closed port: prove /defer_ok fail-open
    cat "$SB/exim.spam.conf"
  } > "$SB/exim.spam.test"
  install_cfg "$SB/exim.spam.test"
  if grep -q 'warn spam = nobody:true/defer_ok' "$SB/exim.spam.conf" \
     && ! grep -q 'rejected as spam' "$SB/exim.spam.conf" \
     && "$ACP_EXIM" -bV 2>"$SB/spam-bv.err" | grep -q 'Exim version' \
     && ! grep -qi 'configuration error' "$SB/spam-bv.err"; then
    ok "SpamAssassin ACL: Content_Scanning config valid, reject_score=0 tag-only"
  else
    bad "SpamAssassin ACL config/tag-only gate: $(tr '\n' ' ' < "$SB/spam-bv.err" 2>/dev/null | cut -c1-160)"
  fi
  SPAM_OUT="$SB/spam-bh.out"; SPAM_ERR="$SB/spam-bh.err"
  "$ACP_EXIM" -bh 198.51.100.7 < "$SB/smtp-data.txt" >"$SPAM_OUT" 2>"$SPAM_ERR"
  if grep -q '250 OK id=' "$SPAM_OUT" && grep -qi 'spamd.*failed\|all spamd servers failed' "$SPAM_ERR"; then
    ok "spamd down: DATA accepted (defer_ok fail-open, message not stuck)"
  else
    bad "spamd down: expected accepted DATA + fail-open log; smtp=$(grep -E '250 OK id=|451|550' "$SPAM_OUT" | tail -1)"
  fi

  printf '{"spam_enabled":"yes","greylisting":"no","spam_score_limit":"80"}\n' > "$SB/alphacp/etc/mail/exim-options.json"
  ACP_RENDER_ROOT="$SB" ACP_MAIL_EXIM="$SPAMD_BIN" ACP_MAIL_SPAMD=1 \
    ACP_MAIL_GREYLISTD_SOCKET="$SOCK" $PHP_BIN tools/dev/render-exim-template.php > "$SB/exim.spam-reject.conf" 2>"$SB/render-spam-reject.err"
  {
    echo "spool_directory = /tmp/eximspool"
    echo "log_file_path = /tmp/eximlog/%slog"
    echo "spamd_address = 127.0.0.1 65534"
    cat "$SB/exim.spam-reject.conf"
  } > "$SB/exim.spam-reject.test"
  install_cfg "$SB/exim.spam-reject.test"
  if grep -q 'rejected as spam' "$SB/exim.spam-reject.conf" \
     && "$ACP_EXIM" -bV 2>"$SB/spam-reject-bv.err" | grep -q 'Exim version' \
     && ! grep -qi 'configuration error' "$SB/spam-reject-bv.err"; then
    ok "SpamAssassin reject_score=8.0: real Exim config valid + threshold emitted"
  else
    bad "SpamAssassin rejection threshold config invalid"
  fi

  # A protocol-accurate tiny spamd answers the REPORT request with Exim's
  # supported SPAMD/1.1 Content-length + score/threshold response. This proves
  # the live score variables, X-Spam-Score header, and reject threshold—not only -bV.
  SPAM_PORT="$(python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1]);s.close()')"
  {
    echo "spool_directory = /tmp/eximspool"
    echo "log_file_path = /tmp/eximlog/%slog"
    echo "spamd_address = 127.0.0.1 ${SPAM_PORT}"
    cat "$SB/exim.spam-reject.conf"
  } > "$SB/exim.spam-active.test"
  install_cfg "$SB/exim.spam-active.test"
  FAKE_SPAMD_PID=""
  start_fake_spamd() {
    local score="$1"
    rm -f "$SB/fake-spamd.ready"
    python3 - "$SPAM_PORT" "$score" "$SB/fake-spamd.ready" >"$SB/fake-spamd.log" 2>&1 <<'PY_SPAMD' &
import socket, sys
port, score, ready = int(sys.argv[1]), sys.argv[2], sys.argv[3]
server = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
server.bind(('127.0.0.1', port))
server.listen(1)
open(ready, 'w').write('ready')
client, _ = server.accept()
request = b''
while True:
    data = client.recv(8192)
    if not data:
        break
    request += data
body = (score + '/5.0\r\n').encode()
reply = (f'SPAMD/1.1 0 EX_OK\r\nContent-length: {len(body)}\r\n\r\n').encode() + body
client.sendall(reply)
client.close()
server.close()
PY_SPAMD
    FAKE_SPAMD_PID=$!
    for _ in 1 2 3 4 5 6 7 8 9 10; do
      [[ -f "$SB/fake-spamd.ready" ]] && break
      sleep 0.1
    done
  }
  stop_fake_spamd() {
    if [[ -n "$FAKE_SPAMD_PID" ]]; then
      kill "$FAKE_SPAMD_PID" 2>/dev/null || true
      wait "$FAKE_SPAMD_PID" 2>/dev/null || true
      FAKE_SPAMD_PID=""
    fi
  }

  start_fake_spamd 9.5
  "$ACP_EXIM" -bh 198.51.100.7 < "$SB/smtp-data.txt" >"$SB/spam-high.out" 2>"$SB/spam-high.err"
  stop_fake_spamd
  if grep -q '^550 Message scored 9.5 spam points (limit 8.0)' "$SB/spam-high.out" \
     && grep -q 'X-Spam-Score: 9.5' "$SB/spam-high.err"; then
    ok "spamd score 9.5: X-Spam-Score header + Exim reject threshold produce 550"
  else
    bad "spamd score 9.5: expected score header and 550 rejection"
  fi

  start_fake_spamd 4.2
  "$ACP_EXIM" -bh 198.51.100.7 < "$SB/smtp-data.txt" >"$SB/spam-low.out" 2>"$SB/spam-low.err"
  stop_fake_spamd
  if grep -q '250 OK id=' "$SB/spam-low.out" && grep -q 'X-Spam-Score: 4.2' "$SB/spam-low.err"; then
    ok "spamd score 4.2: X-Spam-Score header added and normal message delivered"
  else
    bad "spamd score 4.2: expected X-Spam-Score header + accepted DATA"
  fi
else
  skip "SpamAssassin live ACL needs /usr/sbin/exim4 built with Content_Scanning"
fi

# ----------------------------------------- 11. greylistd real ACL over socket --
SOCK="$SB/run/greylistd/socket"
printf '{"spam_enabled":"no","greylisting":"yes","spam_score_limit":"80"}\n' > "$SB/alphacp/etc/mail/exim-options.json"
ACP_RENDER_ROOT="$SB" ACP_MAIL_EXIM="${SPAMD_BIN:-/usr/sbin/exim4}" ACP_MAIL_SPAMD= \
  ACP_MAIL_GREYLISTD_SOCKET="$SOCK" $PHP_BIN tools/dev/render-exim-template.php > "$SB/exim.grey.conf" 2>"$SB/render-grey.err"
{
  echo "spool_directory = /tmp/eximspool"
  echo "log_file_path = /tmp/eximlog/%slog"
  cat "$SB/exim.grey.conf"
} > "$SB/exim.grey.test"
install_cfg "$SB/exim.grey.test"
if "$ACP_EXIM" -bV 2>"$SB/grey-bv.err" | grep -q 'Exim version' \
   && ! grep -qi 'configuration error' "$SB/grey-bv.err"; then
  ok "greylistd ACL: real Exim config valid"
else
  bad "greylistd ACL: real Exim rejected config"
fi

GREY_PID=""
start_greylistd() {
  local reply="$1"
  rm -f "$SOCK"
  python3 - "$SOCK" "$reply" >"$SB/fake-greylistd.log" 2>&1 <<'PY_GREYLISTD' &
import os, socket, sys
path, reply = sys.argv[1], sys.argv[2]
server = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
server.bind(path)
os.chmod(path, 0o666)
server.listen(8)
while True:
    client, _ = server.accept()
    try:
        client.recv(4096)
        client.sendall(reply.encode())
    finally:
        client.close()
PY_GREYLISTD
  GREY_PID=$!
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    [[ -S "$SOCK" ]] && break
    sleep 0.1
  done
}
stop_greylistd() {
  if [[ -n "$GREY_PID" ]]; then
    kill "$GREY_PID" 2>/dev/null || true
    wait "$GREY_PID" 2>/dev/null || true
    GREY_PID=""
  fi
  rm -f "$SOCK"
}

# Missing socket is an explicit false fallback: mail still gets accepted.
stop_greylistd
"$ACP_EXIM" -bh 198.51.100.7 < "$SB/smtp-data.txt" >"$SB/grey-missing.out" 2>"$SB/grey-missing.err"
if grep -q '250 OK id=' "$SB/grey-missing.out"; then
  ok "greylistd socket missing: mail fails open, no queue/defer"
else
  bad "greylistd socket missing: expected accepted DATA"
fi

start_greylistd true
"$ACP_EXIM" -bh 198.51.100.7 < "$SB/smtp-data.txt" >"$SB/grey-true.out" 2>"$SB/grey-true.err"
if grep -q '^451 ' "$SB/grey-true.out"; then
  ok "greylistd --grey true: remote RCPT gets temporary 451"
else
  bad "greylistd --grey true: expected 451 greylist defer"
fi
stop_greylistd

start_greylistd false
"$ACP_EXIM" -bh 198.51.100.7 < "$SB/smtp-data.txt" >"$SB/grey-false.out" 2>"$SB/grey-false.err"
if grep -q '250 OK id=' "$SB/grey-false.out"; then
  ok "greylistd --grey false: retried/whitelisted sender passes"
else
  bad "greylistd --grey false: expected accepted DATA"
fi
stop_greylistd

start_greylistd true
cat > "$SB/smtp-null.txt" <<'EOF'
EHLO sender.example
MAIL FROM:<>
RCPT TO:<info@acp-sandbox.test>
QUIT
EOF
"$ACP_EXIM" -bh 198.51.100.7 < "$SB/smtp-null.txt" >"$SB/grey-null.out" 2>"$SB/grey-null.err"
if grep -q '250 Accepted' "$SB/grey-null.out" && ! grep -q 'check condition = .*readsocket' "$SB/grey-null.err"; then
  ok "greylistd null sender: DSN/callout bypasses socket check"
else
  bad "greylistd null sender: must bypass greylisting"
fi
stop_greylistd

echo
echo "=== ASLI EXIM SANDBOX TEST: ${PASS} pass, ${FAIL} fail, ${SKIP} skip ==="
[[ "$FAIL" == "0" ]]
