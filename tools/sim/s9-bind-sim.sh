#!/usr/bin/env bash
# =============================================================================
# AlphaCP S9 — OFFLINE simulator for tools/verify/s9-bind-check.sh
#
# Root ki zaroorat NAHI, bind9 ki zaroorat NAHI. Ye verify karta hai ki LIVE
# CHECK sahi cheezein maangta hai:
#   run 1: sab theek (fake paneld + fake named tools agent jaisa behave kare)
#          -> script 0 fail de
#   run 2: fake paneld `named-checkzone` gate IGNORE kare (kharaab zone bhi
#          likh de) -> script ko FAIL dena hi chahiye
#   run 3: zone likhi jaye par `dig` khamosh rahe -> script ko FAIL dena hi
#          chahiye ("likh diya" "chal raha hai" nahi hota)
#
# Chalane ka tarika:  bash tools/sim/s9-bind-sim.sh
# =============================================================================
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
W="$(mktemp -d /tmp/acp-bindsim-XXXXXX)"
export ACP_SIM_ROOT="${W}"

echo "=== S9-BIND-SIM (work dir: ${W}) ==="

mkdir -p "${W}/bin" "${W}/etc/bind/zones" "${W}/home/alicehost/etc/dns" "${W}/state"

# ------------------------------------------------------------ fake named tools --
# named-checkconf: config file padho, managed block theek hai to ok
cat > "${W}/bin/named-checkconf" <<'SH'
#!/usr/bin/env bash
# Asli named-checkconf includes ko follow karta hai — yahan bhi wahi: named.conf
# me include line honi chahiye, aur include ki gayi AlphaCP zones file me managed
# block hona chahiye (kharaab zone clause turant pakda jaye).
conf="$1"
[[ -f "$conf" ]] || { echo "named-checkconf: $conf not found" >&2; exit 1; }
dir="$(dirname "$conf")"
grep -q '^include' "$conf" || { echo "$conf:1: no include directives" >&2; exit 1; }
while read -r inc; do
  f="${inc#include \"}"; f="${f%\";}"
  [[ -f "$f" ]] || { echo "$conf: missing include $f" >&2; exit 1; }
  if grep -q 'BEGIN AlphaCP managed' "$f" && ! grep -q 'END AlphaCP managed' "$f"; then
    echo "$f: unterminated managed block" >&2; exit 1
  fi
done < <(grep '^include' "$conf")
zf="${ACP_VERIFY_BIND_ZONES_FILE:-$dir/named.conf.alphacp}"
if [[ -f "$zf" ]]; then
  grep -q 'BEGIN AlphaCP managed' "$zf" || { echo "$zf: not managed by AlphaCP" >&2; exit 1; }
  while read -r line; do
    zf_path="$(sed -E 's/.*file "([^"]*)".*/\1/' <<<"$line")"
    [[ -f "$zf_path" ]] || { echo "$zf: zone file missing: $zf_path" >&2; exit 1; }
  done < <(grep '^zone ' "$zf")
fi
echo "named-checkconf: ok"
exit 0
SH

# named-checkzone <domain> <file>: asli named ki tarah kharaab record reject
cat > "${W}/bin/named-checkzone" <<'SH'
#!/usr/bin/env bash
dom="$1"; file="$2"
[[ -f "$file" ]] || { echo "zone $dom/IN: file not found" >&2; exit 1; }
grep -q 'IN SOA' "$file" || { echo "zone $dom/IN: no SOA" >&2; exit 1; }
if grep -qE '\||/bin/sh|\$\(|\.\.' "$file"; then
  echo "zone $dom/IN: bad record (shell metachar) at line $(grep -nE '\||/bin/sh' "$file" | head -1 | cut -d: -f1)" >&2
  exit 1
fi
echo "zone $dom/IN: loaded serial $(grep -o 'IN SOA [^ ]* [^ ]* ( [0-9]*' "$file" | grep -o '[0-9]*$' | head -1)"
echo "OK"
exit 0
SH

cat > "${W}/bin/rndc" <<'SH'
#!/usr/bin/env bash
echo "server reload successful"
exit 0
SH

# dig: jo zone file MOJOOD hai wahi jawab (file hat te hi khamosh)
cat > "${W}/bin/dig" <<'SH'
#!/usr/bin/env bash
if [[ "${ACP_SIM_BREAK:-}" == "silentdig" ]]; then exit 0; fi
zd="${ACP_SIM_ROOT}/etc/bind/zones"
name=""; qtype=""; for a in "$@"; do
  case "$a" in
    @*|+*) ;;
    A|a|SOA|soa|MX|mx|NS|ns|TXT|txt) qtype="$(echo "$a" | tr 'a-z' 'A-Z')" ;;
    *) name="$a" ;;
  esac
done
[[ -z "$name" || -z "$qtype" ]] && exit 0
# zone dhoondo: www.alice.test -> alice.test
zone="$name"
while [[ ! -f "${zd}/db.${zone}" && "$zone" == *.* ]]; do zone="${zone#*.}"; done
[[ -f "${zd}/db.${zone}" ]] || exit 0
body="$(cat "${zd}/db.${zone}")"
label="@"; [[ "$name" != "$zone" ]] && label="${name%.${zone}}"
case "$qtype" in
  SOA) grep -o 'IN SOA .*' <<<"$body" | head -1 | sed 's/IN SOA //; s/ ( / /; s/ )//' ;;
  NS)  grep -oE '^@ IN NS .*' <<<"$body" | sed 's/@ IN NS //' ;;
  MX)  grep -E "^${label} IN MX " <<<"$body" | sed 's/ IN MX //' ;;
  A)   grep -E "^${label} IN A " <<<"$body" | head -1 | sed 's/.*IN A //' ;;
  TXT) grep -E "^${label} IN TXT " <<<"$body" | sed 's/.*IN TXT //' ;;
esac
exit 0
SH

chmod +x "${W}/bin/"*

# ------------------------------------------------------------- fake paneld -----
cat > "${W}/fake_paneld" <<'PY'
#!/usr/bin/env python3
"""Fake paneld: agent ke dns.bind ke niyam mirror karta hai (bina bind9, bina root)."""
import glob, json, os, re, subprocess, sys

ROOT = os.environ['ACP_SIM_ROOT']
ZD = os.path.join(ROOT, 'etc/bind/zones')
CONF = os.environ.get('ACP_VERIFY_BIND_NAMED_CONF', '/etc/bind/named.conf')
OPTIONS = os.environ.get('ACP_VERIFY_BIND_OPTIONS', '/etc/bind/named.conf.options')
ZONES = os.environ.get('ACP_VERIFY_BIND_ZONES_FILE', '/etc/bind/named.conf.alphacp')
CHECKZONE = os.environ.get('ACP_VERIFY_NAMED_CHECKZONE', '/usr/sbin/named-checkzone')
DIG = os.environ.get('ACP_VERIFY_DIG', '/usr/bin/dig')
BREAK = os.environ.get('ACP_SIM_BREAK', '')
STATE = os.path.join(ROOT, 'state')
TASK = os.path.join(STATE, 'task_id')
BEGIN = '// BEGIN AlphaCP managed (dns.bind)'
END = '// END AlphaCP managed (dns.bind)'


def next_id():
    n = 1
    if os.path.exists(TASK):
        n = int(open(TASK).read().strip() or '0') + 1
    open(TASK, 'w').write(str(n))
    return n


def emit(status, **extra):
    out = {'status': status, 'task_id': next_id()}
    out.update(extra)
    print(json.dumps(out))
    sys.exit(0 if status == 'success' else 1)


def fail(msg):
    emit('failed', error=msg)


def zone_names():
    out = []
    for f in glob.glob(os.path.join(ZD, 'db.*')):
        out.append(os.path.basename(f)[3:])
    return sorted(out)


def write_zones_file():
    lines = [BEGIN]
    for z in zone_names():
        lines.append('zone "%s" { type master; file "%s/db.%s"; allow-update { none; }; allow-transfer { none; }; };' % (z, ZD, z))
    lines.append(END)
    open(ZONES, 'w').write('\n'.join(lines) + '\n')


def dig(domain, qtype):
    r = subprocess.run([DIG, '@127.0.0.1', '+short', '+tries=1', '+time=2', domain, qtype],
                       capture_output=True, text=True)
    return r.stdout.strip()


def bad_domain(d):
    return not re.match(r'^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$', d or '')


def bad_name(n):
    return not re.match(r'^(@|\*|[a-z0-9]([a-z0-9._-]{0,61}[a-z0-9])?)$', n or '')


def render(domain, rows, ttl=300):
    out = ['$TTL %d' % ttl]
    out.append('@ IN SOA ns1.%s. hostmaster.%s. ( %s 3600 600 1209600 %d )' % (domain, domain, '2025100501', max(60, ttl)))
    out.append('@ IN NS ns1.%s.' % domain)
    out.append('@ IN NS ns2.%s.' % domain)
    out.append('ns1 IN A 203.0.113.5')
    out.append('ns2 IN A 203.0.113.5')
    for r in rows:
        nm = r.get('name') or '@'
        ty = (r.get('type') or '').upper()
        val = (r.get('value') or '').strip()
        if ty == 'TXT':
            out.append('%s IN TXT "%s"' % (nm, val.replace('\\', '\\\\').replace('"', '\\"')))
        elif ty == 'MX':
            out.append('%s IN MX 10 %s.' % (nm, val.rstrip('.')))
        elif ty == 'CNAME':
            out.append('%s IN CNAME %s.' % (nm, val.rstrip('.')))
        else:
            out.append('%s IN A %s' % (nm, val))
    return '\n'.join(out) + '\n'


def setup(payload):
    os.makedirs(ZD, exist_ok=True)
    if not os.path.exists(OPTIONS + '.acp-orig') and os.path.exists(OPTIONS):
        open(OPTIONS + '.acp-orig', 'w').write(open(OPTIONS).read())
    open(OPTIONS, 'w').write('\n'.join([
        BEGIN, 'options {', '    directory "/var/cache/bind";',
        '    listen-on { 127.0.0.1; 203.0.113.5; };', '    listen-on-v6 { none; };',
        '    allow-query { any; };', '    allow-transfer { none; };',
        '    recursion no;', '    dnssec-validation no;', '};', END, '']))
    write_zones_file()
    inc = 'include "%s";' % ZONES
    cur = open(CONF).read() if os.path.exists(CONF) else ''
    if not cur:
        fail('named.conf empty')
    if inc not in cur:
        open(CONF, 'w').write(cur.rstrip('\n') + '\n' + inc + '\n')
    emit('success', result={'ok': True, 'zones': len(zone_names())})


def write(payload):
    domain = (payload.get('domain') or '').lower().strip()
    if bad_domain(domain):
        fail('invalid domain')
    rows = payload.get('records')
    if rows is None:
        user = (payload.get('username') or '').lower().strip()
        if not user:
            fail('records ya username chahiye')
        f = os.path.join(ROOT, 'home', user, 'etc/dns/zone.json')
        if not os.path.isfile(f):
            fail('account zone.json nahi mila')
        rows = json.load(open(f))
    if BREAK != 'nogate':
        # agent ki apni validation (Dns::sanitize)
        for r in rows:
            if bad_name(r.get('name')):
                fail('invalid dns name')
            if bad_domain(r.get('domain')):
                fail('invalid dns domain')
    if BREAK == 'nogate':
        # GATE tod diya: na validation, na named-checkzone — seedha likh do.
        # Live check ko isi ko pakadna hai (kharaab zone live nahi hona chahiye).
        open(os.path.join(ZD, 'db.' + domain), 'w').write(render(domain, rows, payload.get('ttl', 300)))
        write_zones_file()
        emit('success', result={'ok': True, 'domain': domain, 'verified': True})
    tmp = os.path.join(ZD, '.db.%s.tmp' % domain)
    open(tmp, 'w').write(render(domain, rows, payload.get('ttl', 300)))
    ck = subprocess.run([CHECKZONE, domain, tmp], capture_output=True, text=True)
    if ck.returncode != 0:
        if os.path.exists(tmp):
            os.unlink(tmp)
        fail('named-checkzone ne zone %s reject kar diya: %s' % (domain, ck.stderr.strip()))
    os.rename(tmp, os.path.join(ZD, 'db.' + domain))
    write_zones_file()
    inc = 'include "%s";' % ZONES
    cur = open(CONF).read() if os.path.exists(CONF) else ''
    if inc not in cur:
        open(CONF, 'w').write(cur.rstrip('\n') + '\n' + inc + '\n')
    soa = dig(domain, 'SOA')
    emit('success', result={'ok': True, 'domain': domain, 'records': len(rows),
                            'dig_soa': soa, 'verified': soa != ''})


def remove(payload):
    domain = (payload.get('domain') or '').lower().strip()
    if bad_domain(domain):
        fail('invalid domain')
    f = os.path.join(ZD, 'db.' + domain)
    existed = os.path.isfile(f)
    if existed:
        os.unlink(f)
    write_zones_file()
    emit('success', result={'ok': True, 'domain': domain, 'removed': existed})


def verify(payload):
    domain = (payload.get('domain') or '').lower().strip()
    if bad_domain(domain):
        fail('invalid domain')
    emit('success', result={'domain': domain, 'soa': dig(domain, 'SOA'),
                            'a': dig(domain, 'A'), 'ns': dig(domain, 'NS')})


def sync(payload):
    home = os.path.join(ROOT, 'home')
    written = 0
    if os.path.isdir(home):
        for user in sorted(os.listdir(home)):
            f = os.path.join(home, user, 'etc/dns/zone.json')
            if not os.path.isfile(f):
                continue
            rows = json.load(open(f))
            for r in rows:
                d = (r.get('domain') or '').lower().strip()
                if bad_domain(d) or os.path.exists(os.path.join(ZD, 'db.' + d)):
                    continue
                tmp = os.path.join(ZD, '.db.%s.tmp' % d)
                open(tmp, 'w').write(render(d, rows))
                ck = subprocess.run([CHECKZONE, d, tmp], capture_output=True, text=True)
                if ck.returncode != 0:
                    os.unlink(tmp)
                    continue
                os.rename(tmp, os.path.join(ZD, 'db.' + d))
                written += 1
    write_zones_file()
    emit('success', result={'ok': True, 'count': written, 'failed': []})


def list_zones(payload):
    emit('success', result={'zones': zone_names(), 'count': len(zone_names())})


def status(payload):
    emit('success', result={'installed': True, 'checkconf': 'ok',
                            'rndc': 'server is up and running',
                            'zones': len(zone_names())})


def main():
    if len(sys.argv) < 4 or sys.argv[1] != '--run':
        fail('usage: paneld --run <type> <payload>')
    type_, raw = sys.argv[2], sys.argv[3]
    if type_ != 'dns.bind':
        fail('unknown task ' + type_)
    try:
        payload = json.loads(raw)
    except Exception:
        fail('bad json')
    action = (payload.get('action') or '').strip()
    handlers = {'setup': setup, 'write': write, 'remove': remove,
                'verify': verify, 'sync': sync, 'list': list_zones,
                'status': status}
    if action not in handlers:
        fail("dns.bind action '%s' nahi chalega" % action)
    handlers[action](payload)


main()
PY
chmod +x "${W}/fake_paneld"

# ------------------------------------------------------------------ fixtures ---
printf 'include "%s";\ninclude "%s";\n' "${W}/etc/bind/named.conf.options" "${W}/etc/bind/named.conf.local" > "${W}/etc/bind/named.conf"
printf 'options {\n    directory "/var/cache/bind";\n};\n' > "${W}/etc/bind/named.conf.options"
printf '// sim: local zones\n' > "${W}/etc/bind/named.conf.local"
cat > "${W}/home/alicehost/etc/dns/zone.json" <<'JSON'
[{"domain":"alice.test","name":"@","type":"A","value":"203.0.113.20"},
 {"domain":"alice.test","name":"www","type":"A","value":"203.0.113.20"},
 {"domain":"alice.test","name":"@","type":"MX","value":"mail.alice.test"}]
JSON

# live check precondition: agent registry me dns.bind hona chahiye
mkdir -p "${W}/acp-home/agent/config"
printf '%s\n' "<?php return ['dns.bind' => ['safety' => 'mutating']];" > "${W}/acp-home/agent/config/tasks.php"

PASS=0; FAIL=0
sim_pass() { PASS=$((PASS+1)); printf '  \033[32mok\033[0m   %s\n' "$1"; }
sim_fail() { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }

run_live() {  # $1 = label, $2 = expected result (pass|fail)
  local label="$1" expect="$2" out rc
  out="$(ACP_VERIFY_ALLOW_NONROOT=1 \
         ACP_HOME="${W}/acp-home" \
         ACP_VERIFY_PANELD="${W}/fake_paneld" \
         ACP_VERIFY_DOMAIN="acp-bind-check.test" \
         ACP_VERIFY_NAMED_CHECKCONF="${W}/bin/named-checkconf" \
         ACP_VERIFY_NAMED_CHECKZONE="${W}/bin/named-checkzone" \
         ACP_VERIFY_RNDC="${W}/bin/rndc" \
         ACP_VERIFY_DIG="${W}/bin/dig" \
         ACP_VERIFY_BIND_ZONE_DIR="${W}/etc/bind/zones" \
         ACP_VERIFY_BIND_ZONES_FILE="${W}/etc/bind/named.conf.alphacp" \
         ACP_VERIFY_BIND_NAMED_CONF="${W}/etc/bind/named.conf" \
         ACP_VERIFY_BIND_OPTIONS="${W}/etc/bind/named.conf.options" \
         bash "${REPO}/tools/verify/s9-bind-check.sh" 2>&1)"
  rc=$?
  local summary; summary="$(grep -E '=== S9 BIND9 LIVE CHECK: [0-9]+ pass' <<<"${out}" | tail -1)"
  local f; f="$(sed -E 's/.*, ([0-9]+) fail.*/\1/' <<<"${summary}")"
  if [[ "${expect}" == "pass" ]]; then
    if [[ ${rc} -eq 0 && "${f}" == "0" ]]; then
      sim_pass "${label} — live check hara (0 fail): ${summary}"
    else
      sim_fail "${label} — live check fail ho gaya (rc=${rc}): ${summary}"
      sed -e 's/\x1b\[[0-9;]*m//g' <<<"${out}" | grep -E 'FAIL|^===' | head -12
    fi
  else
    if [[ ${rc} -ne 0 && "${f}" != "0" ]]; then
      sim_pass "${label} — live check ne sahi pakda (${f} fail)"
    else
      sim_fail "${label} — live check ko FAIL dena chahiye tha, par 0 fail diya"
    fi
  fi
}

# ---------------------------------------------------------------- run 1: sab theek
echo
echo "--- run 1: sab theek (fake named + fake paneld agent jaisa) ---"
rm -f "${W}/state/task_id"
rm -f "${W}/etc/bind/zones/db."* 2>/dev/null || true
printf 'include "%s";\ninclude "%s";\n' "${W}/etc/bind/named.conf.options" "${W}/etc/bind/named.conf.local" > "${W}/etc/bind/named.conf"
run_live "run1" "pass"

# ------------------------------------------- run 2: named-checkzone gate toda hua
echo
echo "--- run 2: paneld named-checkzone gate ignore kare (live check ko pakadna chahiye) ---"
rm -f "${W}/state/task_id"
rm -f "${W}/etc/bind/zones/db."* 2>/dev/null || true
printf 'include "%s";\ninclude "%s";\n' "${W}/etc/bind/named.conf.options" "${W}/etc/bind/named.conf.local" > "${W}/etc/bind/named.conf"
export ACP_SIM_BREAK="nogate"
run_live "run2" "fail"
unset ACP_SIM_BREAK

# --------------------------------------------- run 3: zone likhi par dig khamosh
echo
echo "--- run 3: zone likhi jaye par dig khamosh (live check ko pakadna chahiye) ---"
rm -f "${W}/state/task_id"
rm -f "${W}/etc/bind/zones/db."* 2>/dev/null || true
printf 'include "%s";\ninclude "%s";\n' "${W}/etc/bind/named.conf.options" "${W}/etc/bind/named.conf.local" > "${W}/etc/bind/named.conf"
export ACP_SIM_BREAK="silentdig"
run_live "run3" "fail"
unset ACP_SIM_BREAK

rm -rf "${W}"
echo
echo "=== S9-BIND-SIM: ${PASS} pass, ${FAIL} fail ==="
[[ ${FAIL} -eq 0 ]]
