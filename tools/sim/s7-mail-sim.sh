#!/usr/bin/env bash
# =============================================================================
# S7 MAIL SERVER SIM — tools/verify/s7-mail-check.sh ko BINA server ke chalata hai.
# Package installs ki zaroorat nahi (sab kuch fake bin/ me hai).
#
#   run 1 : sab theek            -> 0 fail hona chahiye
#   run 2 : exim config kharaab  -> live check ko FAIL karna hi chahiye
#   run 3 : delivery hi na ho    -> live check ko FAIL karna hi chahiye
# =============================================================================
set -uo pipefail

WORKROOT="${TMPDIR:-/tmp}/acp-s7sim-$$"
mkdir -p "$WORKROOT"
ACP_HOME="$WORKROOT/alphacp"; export ACP_HOME
mkdir -p "$ACP_HOME/agent/config" "$ACP_HOME/etc" "$ACP_HOME/agent/bin"
BIN="$WORKROOT/bin"; mkdir -p "$BIN"
export PATH="$BIN:$PATH"

BREAK_EXIM=0
BREAK_DELIVERY=0
HAVE_SSL=0
command -v openssl >/dev/null 2>&1 && HAVE_SSL=1

# ----------------------------- nano -----------------------------------------
cat > "$BIN/nano" <<'EOF'
#!/usr/bin/env bash
printf 'SIMULATED: interactive editor\n'
exit 0
EOF
cat > "$BIN/journalctl" <<'EOF'
#!/usr/bin/env bash
printf 'SIMULATED journalctl %s\n' "$*"
exit 0
EOF
cat > "$BIN/systemctl" <<'EOF'
#!/usr/bin/env bash
if [[ "${1:-}" == "is-active" ]]; then
  echo "active"; exit 0
fi
printf 'SIMULATED systemctl %s\n' "$*"
exit 0
EOF
cat > "$BIN/ss" <<'EOF'
#!/usr/bin/env bash
printf 'LISTEN 0 100 0.0.0.0:25 0.0.0.0:*\n'
printf 'LISTEN 0 100 0.0.0.0:143 0.0.0.0:*\n'
exit 0
EOF
cat > "$BIN/dovecot" <<'EOF'
#!/usr/bin/env bash
if [[ "${1:-}" == "--version" ]]; then
  echo "2.3.21 (47377e0c2f)"; exit 0
fi
printf 'SIMULATED dovecot %s\n' "$*"; exit 0
EOF
cat > "$BIN/doveconf" <<'EOF'
#!/usr/bin/env bash
printf 'mail_location = maildir:%%s/mail/%%d/%%n\n' "/home/sim"
exit 0
EOF
cat > "$BIN/update-exim4.conf" <<'EOF'
#!/usr/bin/env bash
printf 'SIMULATED update-exim4.conf %s\n' "$*"
exit 0
EOF

# exim4: -bV (config check), -bt (routing), -odf (delivery)
cat > "$BIN/exim4" <<'EXIMEOF'
#!/usr/bin/env bash
RECIPIENTS="${ACP_MAIL_EXIM_RECIPIENTS:-/etc/exim4/alphacp-recipients}"
case "${1:-}" in
  -bV)
    if [[ "${SIM_BREAK_EXIM:-0}" == "1" ]]; then
      echo "Exim configuration error: unknown option in line 42" >&2; exit 1
    fi
    echo "Exim version 4.97 sim (S7)"; exit 0 ;;
  -bP) echo "exim_user = Debian-exim"; exit 0 ;;
  -bt)
    addr="${2:-}"
    if [[ "${SIM_BREAK_EXIM:-0}" == "1" ]]; then echo "Unrouteable address"; exit 1; fi
    if grep -q "^${addr}:" "$RECIPIENTS" 2>/dev/null; then
      echo "$addr"; echo "  router = alphacp_mailbox, transport = alphacp_maildir"
    else
      echo "Unrouteable address"
    fi
    exit 0 ;;
  -odf|-odq|-oi)
    # asli delivery: recipients file se maildir nikaalo aur file daalo
    addr=""
    for a in "$@"; do [[ "$a" == *@* ]] && addr="$a"; done
    if [[ "${SIM_BREAK_DELIVERY:-0}" == "1" ]]; then
      echo "sim: delivery disabled" >&2; exit 1
    fi
    line="$(grep "^${addr}:" "$RECIPIENTS" 2>/dev/null | head -1)"
    dir="$(echo "$line" | awk -F': ' '{print $2}' | awk '{print $1}' | sed 's#~##')"
    if [[ -z "$dir" || ! -d "$dir/new" ]]; then
      echo "sim: mailbox directory nahi mila ($addr -> $dir)" >&2; exit 1
    fi
    body="$(cat)"
    printf '%s\n' "$body" > "$dir/new/msg.$RANDOM.$RANDOM"
    exit 0 ;;
esac
printf 'SIMULATED exim4 %s\n' "$*"; exit 0
EXIMEOF

# doveadm user <addr> -> dovecot users file se home nikaal kar
cat > "$BIN/doveadm" <<'EOF'
#!/usr/bin/env bash
USERS="${ACP_MAIL_DOVECOT_USERS:-/etc/dovecot/alphacp-users}"
if [[ "${1:-}" == "user" ]]; then
  line="$(grep "^${2:-}:" "$USERS" 2>/dev/null | head -1)"
  if [[ -z "$line" ]]; then echo "user not found" >&2; exit 1; fi
  home="$(echo "$line" | awk -F: '{print $6}')"
  uid="$(echo "$line" | awk -F: '{print $3}')"
  printf 'field\tvalue\nuid\t%s\ngid\t%s\nhome\t%s\nmail\tmaildir:%s\n' "$uid" "$uid" "$home" "$home"
  exit 0
fi
printf 'SIMULATED doveadm %s\n' "$*"; exit 0
EOF

# php (sirf password_hash ke liye) — asli php ho to wahi, nahi to fake
FAKEPHP="$BIN/php"
if command -v php >/dev/null 2>&1; then :; else
  cat > "$FAKEPHP" <<'EOF'
#!/usr/bin/env bash
# fake php: sirf password_hash("x", PASSWORD_BCRYPT) chalta hai,
# baaki sab python paneld ko delegate (asli server par php paneld chalata hai)
if [[ "${1:-}" == "-r" && "${2:-}" == *password_hash* ]]; then
  if [[ -n "${ACP_MAIL_TEST_HASH:-}" ]]; then
    printf '%s' "${ACP_MAIL_TEST_HASH}"; exit 0
  fi
  printf '%s' '$2y$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234'
  exit 0
fi
if [[ -n "${1:-}" && -f "${1}" ]]; then
  exec python3 "$@"
fi
exit 1
EOF
fi
chmod 755 "$BIN"/*

# ----------------------------- fake paneld -----------------------------------
cat > "$ACP_HOME/agent/bin/paneld" <<PYEOF
#!/usr/bin/env python3
# SIM fake paneld — S7. Asli agent ke behaviour ko mirror karta hai
# (naam hi nahi: sync aggregate karta hai, setup config likhta hai).
import json, os, re, sys

HOME = os.environ["SIM_ACCOUNTS_ROOT"]
ETC = os.environ["SIM_ETC_ROOT"]
STATE = os.environ["SIM_STATE_ROOT"]
RECIP = os.path.join(ETC, "exim4", "alphacp-recipients")
RECIP = os.environ.get("ACP_MAIL_EXIM_RECIPIENTS", RECIP)
DOM = os.path.join(ETC, "exim4", "alphacp-domains")
DOM = os.environ.get("ACP_MAIL_EXIM_DOMAINS", DOM)
ALI = os.path.join(ETC, "exim4", "alphacp-aliases")
ALI = os.environ.get("ACP_MAIL_EXIM_ALIASES", ALI)
TPL = os.environ.get("ACP_MAIL_EXIM_TEMPLATE", os.path.join(ETC, "exim4", "exim4.conf.template"))
USERS = os.environ.get("ACP_MAIL_DOVECOT_USERS", os.path.join(ETC, "dovecot", "alphacp-users"))
DC = os.environ.get("ACP_MAIL_DOVECOT_CONF", os.path.join(ETC, "dovecot", "conf.d", "99-alphacp.conf"))
TASKN = [0]

RE_OK = re.compile(r"^[a-z][a-z0-9]{2,15}\$")
RE_ADDR = re.compile(r"^[a-z0-9._-]+@[a-z0-9.-]+\$")

def emit(status, **extra):
    out = {"status": status}
    if status == "success":
        out["task_id"] = TASKN[0]
    out.update(extra)
    print(json.dumps(out, indent=2, sort_keys=True))
    sys.exit(0 if status == "success" else 1)

def write(path, text):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w") as f:
        f.write(text)
    os.chmod(path, 0o644 if not path.endswith(".sh") else 0o755)

def accounts():
    if not os.path.isdir(HOME):
        return []
    return sorted(d for d in os.listdir(HOME) if os.path.isdir(os.path.join(HOME, d)))

def aggregate():
    domains, recipients, aliases, users, boxes = set(), [], [], [], []
    for user in accounts():
        home = os.path.join(HOME, user)
        pfile = os.path.join(home, "etc", "mail", "passwd")
        if os.path.isfile(pfile):
            for line in open(pfile):
                line = line.strip()
                if not line or ":" not in line:
                    continue
                f = line.split(":")
                if len(f) < 7 or not f[1].startswith("{BLF-CRYPT}"):
                    continue
                addr, uid, gid, mdir = f[0], f[2], f[3], f[5]
                if not RE_ADDR.match(addr) or not mdir.startswith(home + "/"):
                    continue
                domains.add(addr.split("@", 1)[1])
                recipients.append("%s: %s %s %s" % (addr, mdir, uid, gid))
                users.append(line)
                boxes.append(addr)
        afile = os.path.join(home, "etc", "mail", "aliases")
        if os.path.isfile(afile):
            for line in open(afile):
                line = line.strip()
                if not line or ":" not in line or not RE_ADDR.match(line.split(":", 1)[0]):
                    continue
                aliases.append(line)
    write(DOM, "".join(d + "\n" for d in sorted(domains)))
    write(RECIP, "".join(r + "\n" for r in sorted(recipients)))
    write(ALI, "".join(a + "\n" for a in sorted(aliases)))
    write(USERS, "".join(u + "\n" for u in sorted(users)))
    return {
        "ok": True,
        "domains": len(domains),
        "mailboxes": len(boxes),
        "aliases": len(aliases),
    }

def setup():
    res = aggregate()
    if not os.path.isfile(TPL):
        write(TPL, "# distro template\n")
    else:
        backup = TPL + ".acp-orig"
        if not os.path.isfile(backup):
            with open(TPL) as f:
                write(backup, f.read())
    write(TPL, "# AlphaCP managed exim template\nalphacp_mailbox:\nalphacp_maildir:\ndeny message = relay not permitted\n")
    write(DC, "passdb { driver = passwd-file }\nmail_location = maildir:%h/mail/%d/%n\n")
    os.makedirs(os.path.join(STATE, "etc"), exist_ok=True)
    write(os.path.join(STATE, "etc", "mail-server-configured"), "ok\n")
    res["exim_config"] = "ok"
    res["dovecot_config"] = "ok"
    return res

def verify(addr):
    if not RE_ADDR.match(addr):
        emit("failed", error="invalid email address")
    routed = os.path.isfile(RECIP) and any(l.startswith(addr + ":") for l in open(RECIP))
    has = os.path.isfile(USERS) and any(l.startswith(addr + ":") for l in open(USERS))
    return {"address": addr, "routed": routed, "has_mailbox": has}

def main():
    args = sys.argv[1:]
    kind = payload = None
    i = 0
    while i < len(args):
        if args[i] == "--run":
            kind = args[i + 1]; payload = args[i + 2]; i += 3
        elif args[i] == "run":
            kind = args[i + 1]; payload = args[i + 2]; i += 3
        else:
            i += 1
    if kind is None:
        print(json.dumps({"status": "failed", "error": "usage: paneld run <type> <json>"}, indent=2))
        sys.exit(1)
    try:
        p = json.loads(payload)
    except Exception as e:
        emit("failed", error="invalid json: %s" % e)
    TASKN[0] += 1
    if kind == "account.create":
        u = p.get("username", "")
        if not RE_OK.match(u):
            emit("failed", error="invalid username")
        home = os.path.join(HOME, u)
        os.makedirs(os.path.join(home, "etc", "mail"), exist_ok=True)
        write(os.path.join(home, "etc", "account.json"), json.dumps(p, sort_keys=True))
        emit("success", username=u, home=home)
    if kind == "account.terminate":
        u = p.get("username", "")
        if p.get("_confirm") != "account.terminate":
            emit("failed", error="account.terminate needs double confirmation")
        import shutil
        shutil.rmtree(os.path.join(HOME, u), ignore_errors=True)
        emit("success", username=u)
    if kind == "mail.set":
        u = p.get("username", "")
        home = os.path.join(HOME, u)
        if u not in accounts():
            emit("failed", error="account does not exist: %s" % u)
        lines = []
        for mbox in p.get("mailboxes", []):
            addr = "%s@%s" % (mbox["local"], mbox["domain"])
            mdir = os.path.join(home, "mail", mbox["domain"], mbox["local"])
            os.makedirs(os.path.join(mdir, "cur"), exist_ok=True)
            os.makedirs(os.path.join(mdir, "new"), exist_ok=True)
            os.makedirs(os.path.join(mdir, "tmp"), exist_ok=True)
            lines.append("%s:{BLF-CRYPT}%s:1001:1001::%s::" % (addr, mbox["hash"], mdir))
        pfile = os.path.join(home, "etc", "mail", "passwd")
        existing = ""
        if os.path.isfile(pfile):
            existing = open(pfile).read()
        write(pfile, existing + "".join(l + "\n" for l in lines))
        emit("success", mailboxes_written=len(lines))
    if kind == "mail.server":
        a = p.get("action", "")
        if a == "setup":
            emit("success", **setup())
        if a == "sync":
            emit("success", **aggregate())
        if a == "status":
            st = {
                "installed": True,
                "exim_config": "ok",
                "dovecot_config": "ok",
                "services": {"exim4": True, "dovecot": True},
                "domains": 0, "mailboxes": 0, "aliases": 0,
                "accounts_with_mail": 0,
            }
            boxes = set()
            if os.path.isfile(USERS):
                for l in open(USERS):
                    l = l.strip()
                    if l and ":" in l:
                        addr = l.split(":", 1)[0]
                        boxes.add(addr)
                        st["domains"] += 0
            st["mailboxes"] = len(boxes)
            if os.path.isfile(DOM):
                st["domains"] = len([x for x in open(DOM) if x.strip()])
            emit("success", **st)
        if a == "list":
            boxes = []
            if os.path.isfile(USERS):
                boxes = sorted(l.split(":", 1)[0] for l in open(USERS) if l.strip())
            emit("success", mailboxes=boxes, count=len(boxes))
        if a == "verify":
            emit("success", **verify(p.get("address", "")))
        emit("failed", error="mail.server action '%s' nahi chalega" % a)
    emit("failed", error="unknown task type: %s" % kind)

main()
PYEOF
chmod 755 "$ACP_HOME/agent/bin/paneld"

# mail.server registry (live script check karta hai)
cat > "$ACP_HOME/agent/config/tasks.php" <<'EOF'
<?php
// SIM: mail.server registered
return ['mail.server' => ['handler' => 'Tasks\MailServerSetup', 'actions' => ['status', 'setup', 'sync', 'list', 'verify']]];
EOF

# ----------------------------- runner ----------------------------------------
run_mode() {  # $1 mode, $2 expected fail or 0
  local mode="$1" efail="$2" out="" rc=0
  local SIMROOT="$WORKROOT/run-${mode}"
  rm -rf "$SIMROOT"; mkdir -p "$SIMROOT/home" "$SIMROOT/etc/exim4" "$SIMROOT/etc/dovecot/conf.d" "$SIMROOT/state"
  # exim4 package jaisa distro template (backup lene ke liye)
  cat > "$SIMROOT/etc/exim4/exim4.conf.template" <<'TPLEOF'
# distro exim4 template (sim)
# (asli server par ye /etc/exim4/exim4.conf.template package se aata hai)
begin routers
TPLEOF
  export SIM_ACCOUNTS_ROOT="$SIMROOT/home"
  export SIM_ETC_ROOT="$SIMROOT/etc"
  export SIM_STATE_ROOT="$ACP_HOME"
  export ACP_MAIL_EXIM_TEMPLATE="$SIMROOT/etc/exim4/exim4.conf.template"
  export ACP_MAIL_EXIM_DOMAINS="$SIMROOT/etc/exim4/alphacp-domains"
  export ACP_MAIL_EXIM_RECIPIENTS="$SIMROOT/etc/exim4/alphacp-recipients"
  export ACP_MAIL_EXIM_ALIASES="$SIMROOT/etc/exim4/alphacp-aliases"
  export ACP_MAIL_DOVECOT_USERS="$SIMROOT/etc/dovecot/alphacp-users"
  export ACP_MAIL_DOVECOT_CONF="$SIMROOT/etc/dovecot/conf.d/99-alphacp.conf"
  export ACP_MAIL_TEST_HASH='$2y$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234'
  export ACP_VERIFY_MAIL_USER="acpmailchk"
  export ACP_VERIFY_MAIL_DOMAIN="acp-mail-check.test"
  export ACP_VERIFY_PANELD="$ACP_HOME/agent/bin/paneld"
  export ACP_VERIFY_EXIM="$BIN/exim4"
  export ACP_VERIFY_DOVEADM="$BIN/doveadm"
  export ACP_VERIFY_DOVECOT="$BIN/dovecot"
  export ACP_VERIFY_DOVECONF="$BIN/doveconf"
  export ACP_VERIFY_ALLOW_NONROOT=1
  export SIM_BREAK_EXIM=0
  export SIM_BREAK_DELIVERY=0
  case "$mode" in
    breakexim)     SIM_BREAK_EXIM=1; export SIM_BREAK_EXIM ;;
    breakdelivery) SIM_BREAK_DELIVERY=1; export SIM_BREAK_DELIVERY ;;
  esac

  out="$(ACP_HOME="$ACP_HOME" bash "$(dirname "$0")/../verify/s7-mail-check.sh" 2>&1)"
  rc=$?
  if [[ "${SIM_DEBUG:-0}" == "1" ]]; then printf '%s\n' "$out"; fi
  # local vars must not leak into later modes
  unset SIM_BREAK_EXIM SIM_BREAK_DELIVERY
  echo "$out"
  if grep -q "pass=${PASS}" <<<"out"; then :; fi
  return $rc
}

PASS=0; FAIL=0
check() { # description, expected fail count (0 = bilkul zero, warna >=), actual fail count
  local desc="$1" expect="$2" got="$3"
  if [[ "$expect" == "0" && "$got" == "0" ]]; then
    PASS=$((PASS+1)); echo "[ok]   $desc (fail=$got, expected=$expect)"
  elif [[ "$expect" != "0" && "$got" =~ ^[0-9]+$ && "$got" -ge "$expect" ]]; then
    PASS=$((PASS+1)); echo "[ok]   $desc (fail=$got, expected >= $expect)"
  else
    FAIL=$((FAIL+1)); echo "[FAIL] $desc (fail=$got, expected=$expect)"
  fi
}

echo "== S7 mail server SIM =="
for mode in good breakexim breakdelivery; do
  want=0
  [[ "$mode" != "good" ]] && want=1
  OUT="$(run_mode "$mode" "$want")"
  F="$(grep -o '[0-9]* pass, [0-9]* fail, [0-9]* skip' <<<"$OUT" | tail -1 | sed 's/.*, \([0-9]*\) fail.*/\1/')"
  [[ -z "$F" ]] && F="?"
  if [[ "${SIM_DEBUG:-0}" == "1" ]]; then printf '%s\n' "$OUT"; fi
  check "mode=$mode live-check" "$want" "$F"
  if [[ "$want" == "1" ]]; then
    grep -q "FAIL" <<<"$OUT" || { FAIL=$((FAIL+1)); echo "[FAIL] mode=$mode: koi FAIL line hi nahi aayi"; }
  fi
  grep -q "ASLI MAIL DELIVERY:VERIFIED" <<<"$OUT" && echo "       -> live mail delivery: VERIFIED" || echo "       -> live mail delivery: NOT verified"
  grep -q "cleanup" <<<"$OUT" && echo "       -> cleanup chal gaya"
done

echo
echo "=== S7 MAIL SERVER SIM: $PASS pass, $FAIL fail ==="
[[ $FAIL -eq 0 ]]
