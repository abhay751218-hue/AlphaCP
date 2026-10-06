#!/usr/bin/env bash
# =============================================================================
# S7 MAIL SERVER SIM — S7 mail + #18 mailing-list verifiers ko BINA server ke chalata hai.
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

# #23 Address Importer verifier deployed panel ke ASLI classes chalata hai
# (App\Support\Mail + AccountIdentity) — sim me wahi files repo se stage karo,
# aur PHP ke liye php-wasm use karo (sandbox me system php nahi hota).
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
mkdir -p "$ACP_HOME/panel/app/Support"
for f in Mail.php AccountIdentity.php; do
  [[ -f "$REPO/refs/panel-2b-bundle/app/Support/$f" ]] \
    && cp "$REPO/refs/panel-2b-bundle/app/Support/$f" "$ACP_HOME/panel/app/Support/"
done
IMPORT_PHP=""
PHPWASM_DIR="${PHPWASM_DIR:-{/tmp,$HOME}/.tools/phpw}"
for d in ${PHPWASM_DIR//,/ }; do
  if [[ -f "$d/node_modules/@php-wasm/cli/php-wasm.js" ]]; then
    IMPORT_PHP="node $d/node_modules/@php-wasm/cli/php-wasm.js -d memory_limit=512M"; break
  fi
done
if [[ -z "$IMPORT_PHP" ]] && command -v npm >/dev/null 2>&1; then
  d="/tmp/phpw"; mkdir -p "$d"
  (cd "$d" && npm init -y >/dev/null 2>&1 && npm i @php-wasm/cli >/dev/null 2>&1) || true
  [[ -f "$d/node_modules/@php-wasm/cli/php-wasm.js" ]] \
    && IMPORT_PHP="node $d/node_modules/@php-wasm/cli/php-wasm.js -d memory_limit=512M"
fi
if [[ -z "$IMPORT_PHP" ]] && command -v php8.4 >/dev/null 2>&1; then IMPORT_PHP="$(command -v php8.4)"; fi
if [[ -z "$IMPORT_PHP" ]] && command -v php >/dev/null 2>&1; then IMPORT_PHP="$(command -v php)"; fi

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
ALIASES="${ACP_MAIL_EXIM_ALIASES:-/etc/exim4/alphacp-aliases}"
VERBOSE=0
for arg in "$@"; do [[ "$arg" == "-v" ]] && VERBOSE=1; done
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
    elif grep -q "^${addr}:" "$ALIASES" 2>/dev/null; then
      # ASLI exim 4.97 ka redirect (alias) shape — sim ko bhi faithful rakhna hai,
      # warna verifier sirf sim par pass hokar live par fail hota hai (0.82.0 me hua tha):
      #     <final address>
      #       <-- <original address>
      #       router = <final router>, transport = <final transport>
      target="$(awk -F': ' -v key="$addr" '$1 == key {print $2; exit}' "$ALIASES")"
      first="${target%%,*}"
      echo "${first}"
      echo "  <-- ${addr}"
      if grep -q "^${first}:" "$RECIPIENTS" 2>/dev/null; then
        echo "  router = alphacp_mailbox, transport = alphacp_maildir"
      else
        echo "  router = alphacp_aliases, transport = address_directory"
      fi
    else
      dom="${addr#*@}"
      if grep -q "^\\*@${dom}:" "${ACP_MAIL_CATCHALL:-/dev/null}" 2>/dev/null; then
        echo "$addr"; echo "  router = alphacp_catchall, transport = <none>"
      else
        echo "Unrouteable address"
      fi
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
    body="$(cat)"
    if [[ -z "$dir" ]]; then
      alias_line="$(awk -F': ' -v key="$addr" '$1 == key {print $2; exit}' "$ALIASES" 2>/dev/null)"
      if [[ -z "$alias_line" ]]; then
        echo "sim: mailbox/alias nahi mila ($addr)" >&2; exit 1
      fi
      alias_line="${alias_line//,/ }"
      rc=0
      for target in $alias_line; do
        [[ -z "$target" ]] && continue
        if ! printf '%s\n' "$body" | "$0" -odf "$target"; then rc=1; fi
      done
      exit "$rc"
    fi
    if [[ ! -d "$dir/new" ]]; then
      echo "sim: mailbox directory nahi mila ($addr -> $dir)" >&2; exit 1
    fi
    # S7 email filters (#20/#21): Exim filter file ko SIM me bhi chalao
    # (asli server par ye kaam exim ka `alphacp_userfilter` router karta hai).
    # NOTE: heredoc stdin ko override kar deta hai, isliye message file se padha jata hai.
    FM="${ACP_MAIL_FILTERS:-/etc/exim4/alphacp-filters}"
    if [[ -n "$addr" && -f "$FM" ]]; then
      FP="$(sed -n "s#^${addr}: ##p" "$FM" 2>/dev/null | head -1)"
      if [[ -n "$FP" && -f "$FP" ]]; then
        MSG_TMP="$(mktemp)"
        printf '%s\n' "$body" > "$MSG_TMP"
        RES="$(python3 - "$FP" "$MSG_TMP" <<'PYEOF'
import sys, re
NL = chr(10)
fpath, msgfile = sys.argv[1], sys.argv[2]
msg = open(msgfile).read()
head = msg.split(NL + NL, 1)[0]
fields = {}
for line in head.splitlines():
    m = re.match(r"^([A-Za-z-]+):\s*(.*)$", line)
    if m:
        fields[m.group(1).lower()] = m.group(2)
text = open(fpath).read()
pat = r'if [$]header_([a-z]+): contains "([^"]*)" then' + NL + r'((?:[ \t]+.*' + NL + r')+?)endif'
for m in re.finditer(pat, text):
    field, needle, block = m.group(1), m.group(2).lower(), m.group(3)
    if needle not in fields.get(field, "").lower():
        continue
    if "seen finish" in block:
        print("DISCARD"); break
    sm = re.search(r'save "([^"]+)"', block)
    if sm:
        print("FOLDER " + sm.group(1)); break
    dm = re.search(r'deliver "([^"]+)"', block)
    if dm:
        print("FORWARD " + dm.group(1)); break
PYEOF
)"
        rm -f "$MSG_TMP"
        if [[ "$RES" == FOLDER\ * && "${SIM_BREAK_FILTER:-0}" == "1" ]]; then
          printf '%s\n' "$body" > "$dir/new/msg.$RANDOM.$RANDOM"
          if [[ "$VERBOSE" == "1" ]]; then
            printf 'delivering SIMULATED-ID\n  => %s R=alphacp_mailbox T=alphacp_maildir\n  Completed\n' "$addr"
          fi
          exit 0
        fi
        case "$RES" in
          DISCARD)
            if [[ "$VERBOSE" == "1" ]]; then
              printf 'delivering SIMULATED-ID\n  => discarded <%s> R=alphacp_userfilter\n  Completed\n' "$addr"
            fi
            exit 0 ;;
          FOLDER\ *)
            d="${RES#FOLDER }"; mkdir -p "$d/new" "$d/cur" "$d/tmp"
            printf '%s\n' "$body" > "$d/new/msg.$RANDOM.$RANDOM"
            if [[ "$VERBOSE" == "1" ]]; then
              printf 'delivering SIMULATED-ID\n  => %s <%s> R=alphacp_userfilter T=address_directory\n  Completed\n' "$d" "$addr"
            fi
            exit 0 ;;
          FORWARD\ *) exit 0 ;;
        esac
      fi
    fi
    printf '%s\n' "$body" > "$dir/new/msg.$RANDOM.$RANDOM"
    exit 0 ;;


esac
printf 'SIMULATED exim4 %s\n' "$*"; exit 0
EXIMEOF

# dig @127.0.0.1 <name> TXT +short  (SIM: account ke zone.json se jawab)
cat > "$BIN/dig" <<'DIGEOF'
#!/usr/bin/env bash
name=""; qtype=""
skipnext=0
for a in "$@"; do
  case "$a" in
    TXT) qtype=TXT ;;
    +short|@*) ;;
    *) name="$a" ;;
  esac
done
name="${name%.}"
python3 - "$name" "${qtype}" <<'PY'
import json, os, sys, glob
want = sys.argv[1].lower().rstrip('.')
qtype = sys.argv[2] or 'TXT'
root = os.environ.get('SIM_ACCOUNTS_ROOT', '')
out = []
for z in sorted(glob.glob(os.path.join(root, '*', 'etc', 'dns', 'zone.json'))):
    try:
        rows = json.load(open(z))
    except Exception:
        continue
    for r in rows:
        if not isinstance(r, dict):
            continue
        n = str(r.get('name', '')).lower()
        d = str(r.get('domain', '')).lower()
        fqdn = d if n in ('@', '') else n + '.' + d
        if fqdn == want and str(r.get('type', '')).upper() == qtype:
            out.append('"%s"' % r.get('value', ''))
print('\n'.join(out))
PY
exit 0
DIGEOF

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
# doveadm auth test <user> <password> — passwd-file ({BLF-CRYPT}) ke against asli bcrypt check
# (asli server par Dovecot khud karta hai). SIM_DOVEADM_NO_AUTH_TEST=1 -> purana doveadm
# (verifier ka bcrypt-fallback path test karne ke liye).
if [[ "${1:-}" == "auth" && "${2:-}" == "test" ]]; then
  if [[ "${SIM_DOVEADM_NO_AUTH_TEST:-0}" == "1" ]]; then
    echo "doveadm: unknown command 'auth test'" >&2; exit 64
  fi
  python3 - "$USERS" "${3:-}" "${4:-}" <<'PYAUTH'
import sys
users, want, pw = sys.argv[1], sys.argv[2].lower(), sys.argv[3]
line = ""
for raw in open(users):
    if raw.lower().startswith(want + ":"):
        line = raw.strip(); break
if not line:
    print("auth: user not found"); sys.exit(1)
h = line.split(":")[1]
if h.startswith("{BLF-CRYPT}"):
    h = h[len("{BLF-CRYPT}"):]
ok = False
try:
    import crypt
    ok = crypt.crypt(pw, h) == h
except Exception:
    try:
        import bcrypt
        ok = bcrypt.checkpw(pw.encode(), h.encode())
    except Exception:
        print("auth: no bcrypt backend in sim"); sys.exit(2)
if ok:
    print("passdb: %s auth succeeded" % want)
    sys.exit(0)
print("auth: password mismatch"); sys.exit(1)
PYAUTH
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
    catchalls, vacation, spam = [], [], []
    list_count, list_errors = 0, 0
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
        # static mailing lists -> Exim aliases; this covers the S7 #18 verifier
        lfile = os.path.join(home, "etc", "mail", "lists.json")
        if os.path.isfile(lfile):
            try:
                for row in json.load(open(lfile)):
                    if not isinstance(row, dict):
                        list_errors += 1
                        continue
                    addr = "%s@%s" % (str(row.get("local", "")).lower(), str(row.get("domain", "")).lower())
                    members = row.get("members", [row.get("owner", "")])
                    members = sorted(set(str(m).lower() for m in members if RE_ADDR.match(str(m).lower()))) if isinstance(members, list) else []
                    if not RE_ADDR.match(addr) or not members or addr in members or len(members) > 200:
                        list_errors += 1
                        continue
                    aliases.append("%s: %s" % (addr, ", ".join(members)))
                    domains.add(addr.split("@", 1)[1])
                    list_count += 1
            except Exception:
                list_errors += 1
        # catch-all (*@domain: dest)
        cfile = os.path.join(home, "etc", "mail", "catchall")
        if os.path.isfile(cfile) and os.environ.get("SIM_BREAK_CATCHALL", "0") != "1":
            for line in open(cfile):
                line = line.strip()
                if not line or ":" not in line:
                    continue
                key, dest = line.split(":", 1)
                key, dest = key.strip().lower(), dest.strip()
                if re.match(r"^\*@[a-z0-9.-]+$", key) and dest:
                    catchalls.append("%s: %s" % (key, dest))
                    domains.add(key[2:])
        # autoresponder -> vacation files
        vfile = os.path.join(home, "etc", "mail", "autorespond")
        if os.path.isfile(vfile):
            try:
                for row in json.load(open(vfile)):
                    addr = "%s@%s" % (row.get("local", ""), row.get("domain", ""))
                    if not RE_ADDR.match(addr) or addr not in boxes:
                        continue
                    vdir = os.environ.get("ACP_MAIL_VACATION_DIR")
                    if vdir:
                        os.makedirs(vdir, exist_ok=True)
                        with open(os.path.join(vdir, addr + ".eml"), "w") as f:
                            f.write("Subject: %s\n\n%s\n" % (row.get("subject", ""), row.get("body", "")))
                        with open(os.path.join(vdir, addr + ".repeat"), "w") as f:
                            f.write("%sh\n" % row.get("interval_h", 168))
                    vacation.append(addr)
            except Exception:
                pass
        # spam lists -> har mailbox ke liye .deny/.allow
        sfile = os.path.join(home, "etc", "mail", "spam.json")
        if os.path.isfile(sfile):
            try:
                cfg = json.load(open(sfile))
                sdir = os.environ.get("ACP_MAIL_SPAM_DIR")
                for addr in boxes:
                    for kind, key in (("deny", "blacklist"), ("allow", "whitelist")):
                        vals = [v for v in cfg.get(key, []) if "/" not in str(v)]
                        path = os.path.join(sdir, addr + "." + kind) if sdir else None
                        if not path:
                            continue
                        os.makedirs(sdir, exist_ok=True)
                        if vals:
                            with open(path, "w") as f:
                                f.write("\n".join(vals) + "\n")
                        elif os.path.isfile(path):
                            os.remove(path)
                    spam.append(addr)
            except Exception:
                pass
    write(DOM, "".join(d + "\n" for d in sorted(domains)))
    write(RECIP, "".join(r + "\n" for r in sorted(recipients)))
    write(ALI, "".join(a + "\n" for a in sorted(aliases)))
    write(USERS, "".join(u + "\n" for u in sorted(users)))
    write(os.environ.get("ACP_MAIL_CATCHALL", "/dev/null"), "".join(c + "\n" for c in sorted(catchalls)))
    return {
        "ok": True,
        "domains": len(domains),
        "mailboxes": len(boxes),
        "aliases": len(aliases),
        "lists": list_count,
        "list_errors": list_errors,
        "catchalls": len(catchalls),
        "responders": len(vacation),
        "spam_lists": len(spam),
        "filters": build_filters(),
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
    # #145 server-default: DKIM key pair har mail domain ke liye + Exim me signing lines
    dkimdir = os.environ.get("ACP_MAIL_DKIM_DIR", os.path.join(STATE, "etc", "mail", "dkim"))
    os.makedirs(dkimdir, exist_ok=True)
    dkim_lines = ""
    _doms = set()
    if os.path.isfile(DOM):
        for _l in open(DOM):
            _d = _l.strip().lower()
            if _d:
                _doms.add(_d)
    _vd = os.environ.get("ACP_VERIFY_DELIV_DOMAIN", "").strip().lower()
    if _vd:
        _doms.add(_vd)
    for dom in sorted(_doms):
        key = os.path.join(dkimdir, dom + ".key")
        pub = os.path.join(dkimdir, dom + ".pub")
        if not os.path.isfile(key):
            import subprocess as _sp
            r = _sp.run(["openssl", "genrsa", "-out", key, "2048"], capture_output=True)
            if r.returncode == 0:
                os.chmod(key, 0o640)
                _sp.run(["openssl", "rsa", "-in", key, "-pubout", "-out", pub], capture_output=True)
                if os.path.isfile(pub):
                    os.chmod(pub, 0o644)
        if os.path.isfile(key):
            _D = chr(36)      # '$' — heredoc expansion se bachne ke liye (unquoted heredoc)
            _k = dkimdir + "/" + _D + "sender_address_domain.key"
            dkim_lines += ("  dkim_domain = " + _D + "sender_address_domain\n"
                           "  dkim_selector = default\n"
                           "  dkim_private_key = " + _D + "{if exists{" + _k + "}{" + _k + "}{0}}\n")
    write(TPL, "# AlphaCP managed exim template\n" + dkim_lines
          + "alphacp_mailbox:\nalphacp_maildir:\ndeny message = relay not permitted\n")
    write(DC, "passdb { driver = passwd-file }\nmail_location = maildir:%h/mail/%d/%n\n")
    os.makedirs(os.path.join(STATE, "etc"), exist_ok=True)
    write(os.path.join(STATE, "etc", "mail-server-configured"), "ok\n")
    res["exim_config"] = "ok"
    res["dovecot_config"] = "ok"
    if "filters" not in res:
        res["filters"] = 0
    return res

def verify(addr):
    if not RE_ADDR.match(addr):
        emit("failed", error="invalid email address")
    routed = os.path.isfile(RECIP) and any(l.startswith(addr + ":") for l in open(RECIP))
    has = os.path.isfile(USERS) and any(l.startswith(addr + ":") for l in open(USERS))
    return {"address": addr, "routed": routed, "has_mailbox": has}

def configured():
    return os.path.isfile(os.path.join(STATE, "etc", "mail-server-configured"))


def deliverability(username=None):
    """SPF + DMARC + DKIM records account ki zone me (SIM break: SIM_BREAK_DNS=1 -> kuch nahi)."""
    users = [username] if username else accounts()
    done = []
    for u in users:
        zf = os.path.join(HOME, u, "etc", "dns", "zone.json")
        dfile = os.path.join(HOME, u, "etc", "mail", "deliverability.json")
        if not os.path.isfile(dfile):
            continue
        try:
            rows = json.load(open(dfile))
        except Exception:
            continue
        for row in rows:
            dom = row.get("domain") if isinstance(row, dict) else row
            if not dom:
                continue
            if os.environ.get("SIM_BREAK_DNS", "0") == "1":
                done.append({"domain": dom, "dkim": False, "dns": {"applied": False}})
                continue
            existing = []
            if os.path.isfile(zf):
                try:
                    existing = json.load(open(zf))
                except Exception:
                    existing = []
            keep = [r for r in existing
                    if not (r.get("type") == "TXT"
                            and (r.get("name") == "_dmarc"
                                 or r.get("name") == "default._domainkey"
                                 or (r.get("name") == "@" and str(r.get("value", "")).startswith("v=spf1"))))]
            keep.append({"domain": dom, "name": "@", "type": "TXT", "value": "v=spf1 a mx -all"})
            keep.append({"domain": dom, "name": "_dmarc", "type": "TXT",
                         "value": "v=DMARC1; p=quarantine; adkim=r; aspf=r; rua=mailto:postmaster@%s" % dom})
            _dkimdir = os.environ.get("ACP_MAIL_DKIM_DIR", "/dev/null")
            _key = os.path.join(_dkimdir, dom + ".key")
            if _dkimdir != "/dev/null" and not os.path.isfile(_key):
                import subprocess as _sp
                os.makedirs(_dkimdir, exist_ok=True)
                if _sp.run(["openssl", "genrsa", "-out", _key, "2048"], capture_output=True).returncode == 0:
                    os.chmod(_key, 0o640)
                    _sp.run(["openssl", "rsa", "-in", _key, "-pubout", "-out", os.path.join(_dkimdir, dom + ".pub")],
                            capture_output=True)
            _pub = os.path.join(_dkimdir, dom + ".pub")
            _b64 = ""
            if os.path.isfile(_pub):
                _txt = open(_pub).read()
                _b64 = "".join(_txt.replace("-----BEGIN PUBLIC KEY-----", "").replace("-----END PUBLIC KEY-----", "").split())
            keep.append({"domain": dom, "name": "default._domainkey", "type": "TXT",
                         "value": "v=DKIM1; k=rsa; p=" + (_b64 or "SIMKEY")})
            os.makedirs(os.path.dirname(zf), exist_ok=True)
            write(zf, json.dumps(keep, indent=2) + "\n")
            done.append({"domain": dom, "dkim": True, "dns": {"applied": True, "records": len(keep)}})
    return {"ok": True, "count": len(done), "domains": done, "failed": []}


EXIM_SPEC = {
    "message_size_limit": ("size", "50M"),
    "smtp_banner": ("text", "\$smtp_active_hostname ESMTP AlphaCP"),
    "smtp_accept_max": ("int", "100"),
    "smtp_accept_max_per_host": ("int", "10"),
    "queue_run_max": ("int", "5"),
    "remote_max_parallel": ("int", "2"),
    "timeout_frozen_after": ("duration", "7d"),
    "ignore_bounce_errors_after": ("duration", "2d"),
    "deliver_queue_load_max": ("number", "8.0"),
    "queue_only_load": ("number", "12.0"),
    "spam_score_limit": ("int", "80"),
}
DOVECOT_SPEC = {
    "protocols": ("text", "imap pop3"),
    "mail_max_userip_connections": ("int", "10"),
    "maildir_copy_with_hardlinks": ("bool", "yes"),
    "disable_plaintext_auth": ("bool", "no"),
    "pop3_uidl_format": ("text", "%08Xu%08Xv"),
    "imap_idle_notify_interval": ("int", "24"),
    "mailbox_idle_check_interval": ("int", "30"),
    "login_greeting": ("text", "AlphaCP IMAP/POP3 ready."),
}
PAT = {
    "size": re.compile(r"^\d{1,8}[KMGkmg]?\$"),
    "int": re.compile(r"^\d{1,9}\$"),
    "number": re.compile(r"^\d{1,5}(\.\d{1,2})?\$"),
    "duration": re.compile(r"^\d{1,5}[smhdw]\$"),
    "bool": re.compile(r"^(yes|no)\$"),
    "text": re.compile(r"^[ -\x7e]{1,200}\$"),
}
RE_QHEAD = re.compile(
    r"^\s*(?:(\d+[smhdw])\s+)?(\d+(?:\.\d+)?[KMGkmg]?B?)\s+"
    r"([0-9A-Za-z]{6}-[0-9A-Za-z]{6}-[0-9A-Za-z]{2})([A-Za-z-]*)\s*(.*)\$"
)


def load_opts(path, spec):
    out = dict((k, v[1]) for k, v in spec.items())
    if os.path.isfile(path):
        try:
            for k, v in json.load(open(path)).items():
                sv = str(v)
                if k in spec and PAT[spec[k][0]].match(sv) and "#" not in sv:
                    out[k] = sv
        except Exception:
            pass
    return out


def apply_opts(kind, setmap):
    if kind == "exim":
        spec = EXIM_SPEC
        path = os.environ.get("ACP_MAIL_EXIM_OPTIONS") or os.path.join(STATE, "etc", "mail", "exim-options.json")
        target = TPL
    else:
        spec = DOVECOT_SPEC
        path = os.environ.get("ACP_MAIL_DOVECOT_OPTIONS") or os.path.join(STATE, "etc", "mail", "dovecot-options.json")
        target = DC
    if not setmap:
        return {"file": path, "options": load_opts(path, spec),
                "defaults": dict((k, v[1]) for k, v in spec.items()),
                "allowed": sorted(spec), "applied": False}
    errors = []
    merged = load_opts(path, spec)
    for k, v in setmap.items():
        if k not in spec:
            errors.append("'%s' koi mail option nahi hai" % k)
            continue
        sv = ("yes" if v else "no") if isinstance(v, bool) else str(v)
        if spec[k][0] == "bool":
            low = sv.lower()
            if low in ("1", "true", "yes", "on"):
                sv = "yes"
            elif low in ("0", "false", "no", "off"):
                sv = "no"
        if not PAT[spec[k][0]].match(sv) or "#" in sv:
            errors.append("'%s' = '%s' galat hai (%s chahiye)" % (k, sv, spec[k][0]))
            continue
        merged[k] = sv
    if errors:
        emit("failed", error="option reject: " + " | ".join(errors))
    write(path, json.dumps(merged, indent=2, sort_keys=True) + "\n")
    body = open(target).read() if os.path.isfile(target) else ""
    for k in setmap:
        if k not in merged:
            continue
        body2 = re.sub(r"(?m)^%s = .*\$" % re.escape(k), "%s = %s" % (k, merged[k]), body)
        if body2 == body and ("%s = " % k) not in body:
            body2 = body + "\n%s = %s\n" % (k, merged[k])
        body = body2
    write(target, body)
    extra = {"generated": True, "exim_config": "ok"} if kind == "exim" else {"dovecot_config": "ok"}
    out = {"file": path, "options": merged, "allowed": sorted(spec), "applied": True}
    out.update(extra)
    return out


def queue(op="list", mid=""):
    op = (op or "list").lower()
    qfile = os.environ.get("SIM_MAILQ_FILE", "")
    text = open(qfile).read() if qfile and os.path.isfile(qfile) else ""
    items = []
    cur = None
    for line in text.splitlines():
        m = RE_QHEAD.match(line)
        if m:
            if cur is not None:
                items.append(cur)
            sender = ""
            sm = re.search(r"<([^>]*)>", m.group(5))
            if sm:
                sender = sm.group(1)
            elif m.group(5).strip():
                sender = m.group(5).split()[0]
            cur = {"id": m.group(3), "age": m.group(1), "size": m.group(2),
                   "sender": sender.lower(), "recipients": [], "frozen": "frozen" in line}
            continue
        if cur is None:
            continue
        rm = re.match(r"^\s+<?([^\s<>]+)>?", line)
        if rm:
            to = rm.group(1).strip("<>,;").lower()
            if to and to not in cur["recipients"]:
                cur["recipients"].append(to)
    if cur is not None:
        items.append(cur)
    if op in ("deliver", "remove", "freeze", "thaw"):
        if not re.match(r"^[0-9A-Za-z]{6}-[0-9A-Za-z]{6}-[0-9A-Za-z]{2}\$", mid or ""):
            emit("failed", error="queue %s ke liye asli message id chahiye" % op)
        return {"op": op, "id": mid, "ok": True, "output": "", "error": None, "queue": {"count": len(items), "items": items}}
    if op == "flush":
        return {"op": "flush", "ok": True, "error": None, "queue": {"count": len(items), "items": items}}
    if op == "count":
        return {"op": "count", "count": len(items), "ok": True, "error": None}
    if op != "list":
        emit("failed", error="queue op '%s' nahi chalega" % op)
    return {"op": "list", "ok": True, "error": None, "count": len(items), "items": items,
            "note": None if items else "queue khali hai"}


def reports(limit=50, search=""):
    cands = [os.environ.get("ACP_MAIL_MAINLOG", "")] or []
    if not cands:
        cands = ["/var/log/exim4/mainlog", "/var/log/exim/mainlog", "/var/log/maillog", "/var/log/mail.log"]
    path = None
    for c in cands:
        if c and os.path.isfile(c):
            path = c
            break
    if not path:
        return {"ok": False, "error": "exim mainlog nahi mila", "counts": {},
                "entries": [], "top_senders": [], "top_recipients": []}
    counts = {"arrived": 0, "delivered": 0, "redirected": 0, "deferred": 0,
              "failed": 0, "completed": 0, "rejected": 0, "frozen": 0}
    senders, recipients, entries = {}, {}, []
    for line in open(path):
        m = re.match(r"^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.\d+)?\s+(\S+)\s+(.*)\$", line.rstrip("\n"))
        if not m:
            continue
        rest = m.group(3)
        kind, addr = None, None
        mm = re.match(r"^<=\s+(\S+)", rest)
        if mm:
            kind, addr = "arrived", mm.group(1).strip("<>,;").lower()
            senders[addr] = senders.get(addr, 0) + 1
        if kind is None:
            mm = re.match(r"^=>\s+(\S+)", rest)
            if mm:
                kind, addr = "delivered", mm.group(1).strip("<>,;").lower()
                recipients[addr] = recipients.get(addr, 0) + 1
        if kind is None:
            mm = re.match(r"^->\s+(\S+)", rest)
            if mm:
                kind, addr = "redirected", mm.group(1).strip("<>,;").lower()
        if kind is None:
            mm = re.match(r"^==\s+(\S+)", rest)
            if mm:
                kind, addr = "deferred", mm.group(1).strip("<>,;").lower()
        if kind is None:
            mm = re.match(r"^\*\*\s+(\S+)", rest)
            if mm:
                kind, addr = "failed", mm.group(1).strip("<>,;").lower()
        if kind is None:
            if rest.startswith("Completed"):
                kind = "completed"
            elif "frozen" in rest:
                kind = "frozen"
            elif re.search(r"\brejected\b", rest):
                kind = "rejected"
        if kind is None:
            continue
        counts[kind] = counts.get(kind, 0) + 1
        if search and search.lower() not in line.lower():
            continue
        entries.append({"time": m.group(1), "id": m.group(2), "kind": kind,
                        "address": addr, "text": rest[:200]})
    return {"ok": True, "file": path, "lines_scanned": len(open(path).read().splitlines()),
            "counts": counts,
            "top_senders": [{"address": a, "count": c} for a, c in sorted(senders.items(), key=lambda x: -x[1])[:10]],
            "top_recipients": [{"address": a, "count": c} for a, c in sorted(recipients.items(), key=lambda x: -x[1])[:10]],
            "entries": entries[-int(limit or 50):], "entries_total": len(entries)}


def dirbytes(path, depth=0):
    if depth > 8 or not os.path.isdir(path) or os.path.islink(path):
        return 0
    total = 0
    try:
        names = os.listdir(path)
    except Exception:
        return 0
    for name in names:
        fp = os.path.join(path, name)
        if os.path.islink(fp):
            continue
        if os.path.isdir(fp):
            total += dirbytes(fp, depth + 1)
        elif os.path.isfile(fp):
            try:
                total += os.path.getsize(fp)
            except Exception:
                pass
    return total


def diskusage(username=None):
    accs = []
    for u in accounts():
        if username and u != username:
            continue
        mail = os.path.join(HOME, u, "mail")
        if not os.path.isdir(mail):
            continue
        boxes = []
        try:
            doms = sorted(os.listdir(mail))
        except Exception:
            doms = []
        for d in doms:
            dp = os.path.join(mail, d)
            if not os.path.isdir(dp):
                continue
            try:
                locals_ = sorted(os.listdir(dp))
            except Exception:
                locals_ = []
            for l in locals_:
                lp = os.path.join(dp, l)
                if os.path.isdir(lp):
                    boxes.append({"address": "%s@%s" % (l, d), "bytes": dirbytes(lp)})
        boxes.sort(key=lambda r: -r["bytes"])
        accs.append({"username": u, "bytes": dirbytes(mail), "mailboxes": boxes,
                     "mailbox_count": len(boxes)})
    accs.sort(key=lambda r: -r["bytes"])
    return {"ok": True, "accounts_root": HOME, "accounts": accs,
            "account_count": len(accs), "total_bytes": sum(a["bytes"] for a in accs),
            "total_human": "%d B" % sum(a["bytes"] for a in accs)}


def build_filters():
    """Email filters (cPanel #20 account-wide + #21 per-mailbox): panel ke JSON se
    ASLI Exim filter file — wahi syntax jo agent banata hai."""
    NL = chr(10)
    fmap = {}
    for u in accounts():
        home = os.path.join(HOME, u)
        grows, urows = [], []
        gfile = os.path.join(home, "etc", "mail", "global-filters.json")
        ufile = os.path.join(home, "etc", "mail", "filters")
        if os.path.isfile(gfile):
            try:
                grows = json.load(open(gfile))
            except Exception:
                grows = []
        if os.path.isfile(ufile):
            try:
                urows = json.load(open(ufile))
            except Exception:
                urows = []
        if not grows and not urows:
            continue
        boxes = {}
        pfile = os.path.join(home, "etc", "mail", "passwd")
        if os.path.isfile(pfile):
            for line in open(pfile):
                line = line.strip()
                if not line or ":" not in line:
                    continue
                f = line.split(":")
                if len(f) < 7:
                    continue
                if RE_ADDR.match(f[0]):
                    boxes[f[0]] = f[5]
        for addr in sorted(boxes):
            mdir = boxes[addr]
            rules = list(grows) + [r for r in urows
                                   if isinstance(r, dict)
                                   and "%s@%s" % (r.get("local", ""), r.get("domain", "")) == addr]
            if not rules:
                continue
            lines = ["# Exim filter  <<== YE LINE HATAANA NAHI (Exim filter file ki pehchaan)",
                     "# AlphaCP managed (SIM)", "# mailbox: %s" % addr, "",                     "if error_message then finish endif", ""]
            n = 0
            for r in rules:
                if not isinstance(r, dict):
                    continue
                field = str(r.get("field", "")).lower()
                needle = str(r.get("needle", "")).strip()
                action = str(r.get("action", "")).lower()
                if field not in ("from", "subject", "to"):
                    continue
                if not needle or not re.match(r"^[a-zA-Z0-9 .,_@+-]+$", needle):
                    continue
                cond = "if " + chr(36) + 'header_%s: contains "%s" then' % (field, needle)
                if action == "discard":
                    lines += [cond, "  seen finish", "endif", ""]
                    n += 1
                elif action == "folder":
                    folder = str(r.get("folder", "")).strip().lower()
                    if not folder or not re.match(r"^[a-z0-9._-]+$", folder):
                        continue
                    lines += [cond, '  save "%s/.%s/"' % (mdir.rstrip("/"), folder),
                              "  finish", "endif", ""]
                    n += 1
                    os.makedirs(os.path.join(mdir, "." + folder, "new"), exist_ok=True)
                elif action == "forward":
                    dest = str(r.get("dest", "")).strip().lower()
                    if not dest or dest == addr or not RE_ADDR.match(dest):
                        continue
                    lines += [cond, '  deliver "%s"' % dest, "  finish", "endif", ""]
                    n += 1
            if n == 0:
                continue
            path = os.path.join(home, "etc", "mail", "filter.d", addr + ".filter")
            write(path, NL.join(lines) + NL)
            fmap[addr] = path
    write(os.environ.get("ACP_MAIL_FILTERS", "/etc/exim4/alphacp-filters"),
          "".join("%s: %s" % (a, p) + NL for a, p in sorted(fmap.items())))
    return len(fmap)


def track(query):
    """Track Delivery (cPanel #19) — asli exim mainlog me is address ka safar."""
    rep = reports(500, query)
    if not rep.get("ok"):
        return {"ok": False, "address": query, "source": "exim mainlog",
                "error": rep.get("error", "mainlog nahi mila"), "hits": [], "summary": {}}
    summary = {}
    for e in rep["entries"]:
        summary[e["kind"]] = summary.get(e["kind"], 0) + 1
    return {"ok": True, "address": query, "source": "exim mainlog",
            "file": rep.get("file"), "hits": rep["entries"],
            "hits_total": len(rep["entries"]), "summary": summary}


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
        # panel ki tarah: deliverability (SPF/DKIM/DMARC) is domain ke liye chahiye
        write(os.path.join(home, "etc", "mail", "deliverability.json"),
              json.dumps([{"domain": p.get("domain", "")}], sort_keys=True))
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
    if kind == "mail.list":
        u = p.get("username", "")
        if u not in accounts():
            emit("failed", error="account does not exist: %s" % u)
        rows = p.get("lists", [])
        if not isinstance(rows, list) or len(rows) > 50:
            emit("failed", error="invalid lists")
        for row in rows:
            if not isinstance(row, dict):
                emit("failed", error="invalid mailing list")
            addr = "%s@%s" % (str(row.get("local", "")).lower(), str(row.get("domain", "")).lower())
            members = row.get("members", [row.get("owner", "")])
            if not isinstance(members, list) or not members or len(members) > 200 or not RE_ADDR.match(addr):
                emit("failed", error="invalid mailing-list subscribers")
            if any(not isinstance(m, str) or not RE_ADDR.match(m.lower()) or m.lower() == addr for m in members):
                emit("failed", error="invalid mailing-list subscriber address")
        write(os.path.join(HOME, u, "etc", "mail", "lists.json"), json.dumps(rows, sort_keys=True))
        sync = "skipped"
        if configured():
            sync = "ok (%s lists)" % aggregate()["lists"]
        emit("success", lists=len(rows), subscribers=sum(len(r.get("members", [r.get("owner", "")])) for r in rows), mail_sync=sync)
    if kind == "mail.catchall":
        u = p.get("username", "")
        if u not in accounts():
            emit("failed", error="account does not exist: %s" % u)
        lines = ["*@%s: %s" % (r["domain"], r["dest"]) for r in p.get("catchalls", [])]
        write(os.path.join(HOME, u, "etc", "mail", "catchall"), "".join(l + "\n" for l in lines))
        sync = "skipped"
        if configured():
            sync = "ok (%s mailboxes)" % aggregate()["mailboxes"]
        emit("success", catchalls=len(lines), mail_sync=sync)
    if kind == "mail.autorespond":
        u = p.get("username", "")
        if u not in accounts():
            emit("failed", error="account does not exist: %s" % u)
        write(os.path.join(HOME, u, "etc", "mail", "autorespond"),
              json.dumps(p.get("responders", []), sort_keys=True))
        sync = "skipped"
        if configured():
            sync = "ok (%s mailboxes)" % aggregate()["mailboxes"]
        emit("success", responders=len(p.get("responders", [])), mail_sync=sync)
    if kind == "mail.spam":
        u = p.get("username", "")
        if u not in accounts():
            emit("failed", error="account does not exist: %s" % u)
        write(os.path.join(HOME, u, "etc", "mail", "spam.json"), json.dumps(p, sort_keys=True))
        sync = "skipped"
        if configured():
            sync = "ok (%s mailboxes)" % aggregate()["mailboxes"]
        emit("success", required_score=p.get("required_score", 5), mail_sync=sync)
    if kind == "mail.filter":
        u = p.get("username", "")
        if u not in accounts():
            emit("failed", error="account does not exist: %s" % u)
        write(os.path.join(HOME, u, "etc", "mail", "filters"),
              json.dumps(p.get("filters", []), sort_keys=True))
        sync = "skipped"
        if configured():
            sync = "ok (%s filters)" % aggregate()["filters"]
        emit("success", filters=len(p.get("filters", [])), mail_sync=sync)
    if kind == "mail.track":
        emit("success", **track(p.get("query", "")))
    if kind == "mail.server":
        a = p.get("action", "")
        if a == "setup":
            emit("success", **setup())
        if a == "sync":
            res = aggregate()
            # ASLI agent jaisa server default: sync har mail domain ke liye
            # SPF/DKIM/DMARC likhta hai (customer ke kisi click ke bina).
            _deliv = deliverability(None)
            # asli agent bhi `changed` bhejta hai (kitne domains naye likhe gaye)
            _deliv["changed"] = len([d for d in _deliv.get("domains", []) if d.get("dns", {}).get("applied")])
            res["deliverability"] = _deliv
            emit("success", **res)
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
        if a == "queue":
            emit("success", **queue(p.get("op", "list"), p.get("id", "")))
        if a == "reports":
            emit("success", **reports(p.get("limit", 50), p.get("search", "")))
        if a == "eximconf":
            emit("success", **apply_opts("exim", p.get("set")))
        if a == "dovecotconf":
            emit("success", **apply_opts("dovecot", p.get("set")))
        if a == "diskusage":
            emit("success", **diskusage(p.get("username")))
        if a == "deliverability":
            emit("success", **deliverability(p.get("username")))
        emit("failed", error="mail.server action '%s' nahi chalega" % a)
    emit("failed", error="unknown task type: %s" % kind)

main()
PYEOF
chmod 755 "$ACP_HOME/agent/bin/paneld"

# mail.server registry (live script check karta hai)
cat > "$ACP_HOME/agent/config/tasks.php" <<'EOF'
<?php
// SIM: mail.server registered
return ['mail.server' => ['handler' => 'Tasks\MailServerSetup', 'actions' => ['status', 'setup', 'sync', 'list', 'verify']], 'mail.list' => ['handler' => 'Tasks\\MailList'], 'mail.set' => ['handler' => 'Tasks\\MailSet']];
EOF

# ----------------------------- runner ----------------------------------------
run_mode() {  # $1 mode, $2 expected fail or 0
  local mode="$1" efail="$2" out="" rc=0 list_out="" list_rc=0
  local SIMROOT="$WORKROOT/run-${mode}"
  rm -rf "$SIMROOT"; mkdir -p "$SIMROOT/home" "$SIMROOT/etc/exim4" "$SIMROOT/etc/dovecot/conf.d" "$SIMROOT/state" "$SIMROOT/etc/exim4/vacation" "$SIMROOT/etc/exim4/spam"
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
  export ACP_MAIL_CATCHALL="$SIMROOT/etc/exim4/alphacp-catchall"
  export ACP_MAIL_VACATION_DIR="$SIMROOT/etc/exim4/vacation"
  export ACP_MAIL_SPAM_DIR="$SIMROOT/etc/exim4/spam"
  export ACP_MAIL_DKIM_DIR="$SIMROOT/state/etc/mail/dkim"
  export ACP_VERIFY_DIG="$BIN/dig"
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
  export ACP_VERIFY_IMPORT_USER="acpimpchk"
  export ACP_VERIFY_IMPORT_DOMAIN="acp-import-check.test"
  export ACP_VERIFY_DELIV_USER="acpdelivchk"
  export ACP_VERIFY_DELIV_DOMAIN="acp-deliverability-check.test"
  [[ -n "$IMPORT_PHP" ]] && export ACP_VERIFY_PHP="$IMPORT_PHP"
  # SIM: exim mainlog (delivery reports) + mail queue (queue manager)
  mkdir -p "$SIMROOT/var/log/exim4"
  cat > "$SIMROOT/var/log/exim4/mainlog" <<'LOGEOF'
2026-10-05 10:00:01 1oAAAA-000001-AA <= root@ip-172-26-4-65 U=root P=local S=500
2026-10-05 10:00:02 1oAAAA-000001-AA => info@acp-mail-check.test R=alphacp_mailbox T=alphacp_maildir
2026-10-05 10:00:02 1oAAAA-000001-AA Completed
2026-10-05 10:01:01 1oBBBB-000002-AB <= sender@acp-mail-check.test P=esmtp S=900
2026-10-05 10:01:05 1oBBBB-000002-AB == gone@nowhere.test R=dnslookup T=remote_smtp defer (-42)
LOGEOF
  cat > "$SIMROOT/etc/exim4/sim-mailq" <<'QEOF'
10m  1.1K 1oABCD-0000xy-1a <root@ip-172-26-4-65>
        info@acp-mail-check.test

25m  3.2K 1oABCE-0000xz-1b <nobody@example.test> *** frozen ***
        unknown@acp-mail-check.test
QEOF
  export ACP_MAIL_MAINLOG="$SIMROOT/var/log/exim4/mainlog"
  export SIM_MAILQ_FILE="$SIMROOT/etc/exim4/sim-mailq"
  export ACP_MAIL_FILTERS="$SIMROOT/etc/exim4/alphacp-filters"
  export SIM_BREAK_EXIM=0
  export SIM_BREAK_DELIVERY=0
  case "$mode" in
    breakexim)     SIM_BREAK_EXIM=1; export SIM_BREAK_EXIM ;;
    breakdelivery) SIM_BREAK_DELIVERY=1; export SIM_BREAK_DELIVERY ;;
    breakdns)      SIM_BREAK_DNS=1; export SIM_BREAK_DNS ;;
    breakcatchall) SIM_BREAK_CATCHALL=1; export SIM_BREAK_CATCHALL ;;
    breakfilter)   SIM_BREAK_FILTER=1; export SIM_BREAK_FILTER ;;
  esac

  out="$(ACP_HOME="$ACP_HOME" bash "$(dirname "$0")/../verify/s7-mail-check.sh" 2>&1)"
  rc=$?
  list_out="$(ACP_HOME="$ACP_HOME" bash "$(dirname "$0")/../verify/s7-mailing-list-check.sh" 2>&1)"
  list_rc=$?
  imp_out="$(ACP_HOME="$ACP_HOME" bash "$(dirname "$0")/../verify/s7-address-importer-check.sh" 2>&1)"
  imp_rc=$?
  deliv_out="$(ACP_HOME="$ACP_HOME" bash "$(dirname "$0")/../verify/s7-deliverability-default-check.sh" 2>&1)"
  deliv_rc=$?
  if [[ "${SIM_DEBUG:-0}" == "1" ]]; then printf '%s\n%s\n%s\n' "$out" "$list_out" "$imp_out"; fi
  # local vars must not leak into later modes
  unset SIM_BREAK_EXIM SIM_BREAK_DELIVERY SIM_BREAK_DNS SIM_BREAK_CATCHALL SIM_BREAK_FILTER
  echo "$out"
  echo "$list_out"
  echo "$imp_out"
  echo "$deliv_out"
  if grep -q "pass=${PASS}" <<<"out"; then :; fi
  (( rc == 0 && list_rc == 0 && imp_rc == 0 && deliv_rc == 0 ))
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
for mode in good breakexim breakdelivery breakdns breakcatchall breakfilter; do
  want=0
  [[ "$mode" != "good" ]] && want=1
  OUT="$(run_mode "$mode" "$want")"
  F="$(grep -o '[0-9]* pass, [0-9]* fail, [0-9]* skip' <<<"$OUT" | tail -1 | sed 's/.*, \([0-9]*\) fail.*/\1/')"
  [[ -z "$F" ]] && F="?"
  if [[ "${SIM_DEBUG:-0}" == "1" ]]; then printf '%s\n' "$OUT"; fi
  check "mode=$mode base mail live-check" "$want" "$F"
  LF="$(grep -o 'S7 #18 MAILING LIST LIVE CHECK: [0-9]* pass, [0-9]* fail' <<<"$OUT" | tail -1 | sed 's/.*pass, //; s/ fail//')"
  [[ -z "$LF" ]] && LF="?"
  list_want=0
  [[ "$mode" == "breakexim" || "$mode" == "breakdelivery" ]] && list_want=1
  check "mode=$mode mailing-list verifier" "$list_want" "$LF"
  IF="$(grep -o 'S7 #23 ADDRESS IMPORTER LIVE CHECK: [0-9]* pass, [0-9]* fail' <<<"$OUT" | tail -1 | sed 's/.*pass, //; s/ fail//')"
  [[ -z "$IF" ]] && IF="?"
  imp_want=0
  [[ "$mode" == "breakdelivery" ]] && imp_want=1
  check "mode=$mode address-importer verifier" "$imp_want" "$IF"
  DF="$(grep -o 'S7 #145 DELIVERABILITY (SERVER DEFAULT): [0-9]* pass, [0-9]* fail' <<<"$OUT" | tail -1 | sed 's/.*pass, //; s/ fail//')"
  [[ -z "$DF" ]] && DF="?"
  deliv_want=0
  [[ "$mode" == "breakdns" || "$mode" == "breakexim" ]] && deliv_want=1
  check "mode=$mode deliverability-default verifier" "$deliv_want" "$DF"
  if [[ "$mode" == "good" ]]; then
    if grep -q 'import delivery: YES' <<<"$OUT"; then
      PASS=$((PASS+1)); echo "[ok]   imported mailboxes ko asli delivery mili (2/2)"
    else
      FAIL=$((FAIL+1)); echo "[FAIL] address-importer delivery marker missing"
    fi
  fi
  if [[ "$mode" == "good" ]]; then
    if grep -q 'list delivery: YES' <<<"$OUT"; then
      PASS=$((PASS+1)); echo "[ok]   mailing-list subscriber received test message"
    else
      FAIL=$((FAIL+1)); echo "[FAIL] mailing-list delivery marker missing"
    fi
  fi
  if [[ "$want" == "1" ]]; then
    grep -q "FAIL" <<<"$OUT" || { FAIL=$((FAIL+1)); echo "[FAIL] mode=$mode: koi FAIL line hi nahi aayi"; }
  fi
  if [[ "$mode" == "breakfilter" ]]; then
    if grep -q "FILTER delivery diagnostics" <<<"$OUT" && grep -q "Exim -v exit status" <<<"$OUT"; then
      PASS=$((PASS+1)); echo "[ok]   breakfilter prints actionable Exim diagnostics"
    else
      FAIL=$((FAIL+1)); echo "[FAIL] breakfilter omitted actionable Exim diagnostics"
    fi
  fi
  grep -q "BASE INBOX DELIVERY: VERIFIED" <<<"$OUT" && echo "       -> base inbox delivery: VERIFIED" || echo "       -> base inbox delivery: NOT verified"
  grep -q "cleanup" <<<"$OUT" && echo "       -> cleanup chal gaya"
done

echo
echo "=== S7 MAIL SERVER SIM: $PASS pass, $FAIL fail ==="
[[ $FAIL -eq 0 ]]
