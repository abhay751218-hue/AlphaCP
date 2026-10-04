#!/usr/bin/env bash
# =============================================================================
# AlphaCP — offline sim for tools/verify/s10-mysql-restore-check.sh  (root nahi chahiye)
#
# Fake paneld ASLI tar se archive padhta hai (listing + dump content) aur ek chhota
# MariaDB state rakhta hai. Sim do run karta hai:
#   run 1 : sab theek      -> script exit 0
#   run 2 : fake paneld db.restore ko "import nahi karta" (chup-chaap) -> script ko FAIL dena hi hoga
# =============================================================================
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1
W="$(mktemp -d /tmp/acp-s10sim-XXXXXX)"
trap 'rm -rf "${W}"' EXIT
echo "=== S10-MYSQL-RESTORE-SIM (work dir: ${W}) ==="

mkdir -p "${W}/bin" "${W}/home/agent/bin" "${W}/home/etc"
printf 'ACP_DB_HOST=127.0.0.1\nACP_DB_PORT=3306\nACP_DB_NAME=alphacp\nACP_DB_USER=alphacp\nACP_DB_PASS=x\n' > "${W}/home/etc/database.env"
export ACP_FAKE_STATE="${W}/state.json"
python3 -c 'import json,os; json.dump({"databases":{"alphacp":{}}, "users":{}, "accounts":{}}, open(os.environ["ACP_FAKE_STATE"],"w"))'

# ------------------------------------------------------------- fake mariadb ---
cat > "${W}/fake_sql.py" <<'PY'
#!/usr/bin/env python3
"""Chhota MariaDB stand-in: sirf wahi queries jo s10-mysql-restore-check.sh poochta hai."""
import json, os, re, sys

state = json.load(open(os.environ['ACP_FAKE_STATE']))
dbs = state.setdefault('databases', {})
users = state.setdefault('users', {})

sql = ''
args = sys.argv[1:]
for i, a in enumerate(args):
    if a == '-e' and i + 1 < len(args):
        sql = args[i + 1]

def meta(name):
    return {'schema': 'utf8mb4', 'tables': {}} if name in dbs else None

m = re.search(r"SHOW DATABASES LIKE '([^']+)'", sql)
if m: print(m.group(1) if m.group(1) in dbs else ''); sys.exit(0)
m = re.search(r"SCHEMA_NAME FROM information_schema\.SCHEMATA WHERE SCHEMA_NAME='([^']+)'", sql)
if m: print(m.group(1) if m.group(1) in dbs else ''); sys.exit(0)
m = re.search(r"SHOW TABLES FROM (\w+)", sql)
if m: print('\n'.join(sorted(dbs.get(m.group(1), {}).get('tables', {}).keys()))); sys.exit(0)
m = re.search(r"SELECT COUNT\(\*\) FROM (\w+)\.(\w+)", sql)
if m:
    t = dbs.get(m.group(1), {}).get('tables', {}).get(m.group(2))
    print(len(t['rows']) if t else 0); sys.exit(0)
m = re.search(r"SELECT (\w+) FROM (\w+)\.(\w+) WHERE (\w+)=(\d+)", sql)
if m:
    col, db, tbl, where_col, where_val = m.groups()
    t = dbs.get(db, {}).get('tables', {}).get(tbl)
    if not t: print(''); sys.exit(0)
    idx = t['cols'].index(where_col)
    for row in t['rows']:
        if str(row[idx]) == where_val:
            print(row[t['cols'].index(col)]); sys.exit(0)
    print(''); sys.exit(0)
m = re.search(r"SELECT User FROM mysql\.user WHERE User='([^']+)'", sql)
if m: print(m.group(1) if m.group(1) in users else ''); sys.exit(0)
if re.search(r'FROM alphacp\.accounts', sql):
    print('\n'.join(state.get('accounts', {}).keys())); sys.exit(0)
print('')
PY

cat > "${W}/bin/mariadb" <<EOF
#!/usr/bin/env bash
exec python3 "${W}/fake_sql.py" "\$@"
EOF

# -------------------------------------------------------------- fake paneld ---
cat > "${W}/fake_paneld.py" <<'PY'
#!/usr/bin/env python3
"""Fake paneld: REAL tar se archive padhta hai; db.restore ko sach me import karta hai."""
import json, os, re, subprocess, sys, tempfile

state_path = os.environ['ACP_FAKE_STATE']
state = json.load(open(state_path))
state.setdefault('databases', {}); state.setdefault('users', {}); state.setdefault('accounts', {})
args = sys.argv[1:]
type_, payload = args[1], (json.loads(args[2]) if len(args) > 2 else {})
brk = os.environ.get('ACP_FAKE_BREAK', '')

def save(): json.dump(state, open(state_path, 'w'))

def emit(status, error=None, code=0):
    save()
    print(json.dumps({'task_id': 1000 + state.get('_n', 0), 'status': status, 'duration_ms': 7,
                      'result': {'ok': True} if status == 'success' else None, 'error': error},
                     indent=4, separators=(',', ': ')))
    sys.exit(code)

state['_n'] = state.get('_n', 0) + 1

acct = payload.get('username', 'alicehost')

if type_ == 'account.create':
    state['accounts'][acct] = 'active'; emit('success')
if type_ == 'account.terminate':
    state['accounts'].pop(acct, None); emit('success')

if type_ == 'db.drop':
    if payload.get('_confirm') != 'db.drop': emit('failed', error="destructive task 'db.drop' requires _confirm='db.drop'", code=1)
    state['databases'].pop(f"{acct}_{payload.get('name')}", None); emit('success')
if type_ == 'db.restore':
    if payload.get('_confirm') != 'db.restore': emit('failed', error="destructive task 'db.restore' requires _confirm='db.restore'", code=1)
    archive = payload['archive_path']
    if not os.path.isfile(archive): emit('failed', error='archive missing', code=1)
    import hashlib
    if payload.get('sha256') and hashlib.sha256(open(archive, 'rb').read()).hexdigest() != payload['sha256']:
        emit('failed', error='cPanel archive checksum mismatch; refusing to import', code=1)
    if brk == 'restore': emit('success')          # chup-chaap kuch import nahi karta
    listing = subprocess.run(['tar', 'tzf', archive], capture_output=True, text=True).stdout
    dumps = [m for m in listing.split('\n') if m.endswith('.sql')]
    if not dumps: emit('failed', error='no dumps', code=1)
    # pehle sab dumps padho (two-phase) — koi hostile ho to kuch bhi apply na ho
    parsed = []
    for member in dumps:
        content = subprocess.run(['tar', 'xzOf', archive, member], capture_output=True, text=True).stdout
        stem = os.path.basename(member)[:-4]
        db = stem if stem.startswith(acct + '_') else f"{acct}_{stem}"
        for line in content.splitlines():
            s = line.strip()
            for other in re.findall(r'(?:DROP|CREATE)\s+DATABASE\s+(?:IF\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?', s, re.I):
                if other.lower() != db.lower():
                    emit('failed', error=f'cPanel MySQL dump refused: it names another database ({other})', code=1)
        parsed.append((db, content))
    for db, content in parsed:
        meta = {'schema': 'utf8mb4', 'tables': {}}
        for tname, cols in re.findall(r'CREATE TABLE `(\w+)` \(([^;]*)\)', content, re.S):
            colnames = re.findall(r'`(\w+)`\s+(?:int|varchar|text)', cols)
            meta['tables'][tname] = {'cols': colnames, 'rows': []}
        for tname, vals in re.findall(r'INSERT INTO `(\w+)` VALUES \(([^)]*)\)', content):
            row = [v.strip().strip("'") for v in vals.split(',')]
            meta['tables'].setdefault(tname, {'cols': [], 'rows': []})['rows'].append(row)
        state['databases'][db] = meta
    emit('success')

emit('failed', error=f"fake paneld doesn't know task '{type_}'", code=1)
PY

cat > "${W}/home/agent/bin/paneld" <<EOF
#!/usr/bin/env bash
exec python3 "${W}/fake_paneld.py" "\$@"
EOF
chmod 0755 "${W}/bin/mariadb" "${W}/home/agent/bin/paneld"

export ACP_HOME="${W}/home" ACP_PHP=/bin/bash ACP_VERIFY_ALLOW_NONROOT=1
export ACP_VERIFY_PANELD="${W}/home/agent/bin/paneld" ACP_VERIFY_CLIENT="${W}/bin/mariadb"
export ACP_VERIFY_WORK="${W}/check"
unset ACP_VERIFY_ACCOUNT ACP_VERIFY_NO_CREATE 2>/dev/null || true

PASS=0; FAIL=0
chk() { if eval "$2" >/dev/null 2>&1; then PASS=$((PASS+1)); printf '  ok   %s\n' "$1"; else FAIL=$((FAIL+1)); printf '  FAIL %s\n' "$1"; fi; }
reset_state() { python3 -c 'import json,os; json.dump({"databases":{"alphacp":{}}, "users":{}, "accounts":{}}, open(os.environ["ACP_FAKE_STATE"],"w"))'; }

# ------------------------------------------------------------------ run 1 -----
echo
echo "-- run 1: sab theek (script 0 fail dena chahiye)"
reset_state
set +e
OUT1="$(bash tools/verify/s10-mysql-restore-check.sh 2>&1)"; RC1=$?
set -e
echo "${OUT1}" | sed 's/^/     /'
chk "run 1 exit 0" "[[ ${RC1} -eq 0 ]]"
chk "run 1 me import proof (rows)" "grep -qE '3 rows import hue' <<<\"\${OUT1}\""
chk "run 1 me hostile refuse proof" "grep -q 'hostile dump refuse hua' <<<\"\${OUT1}\""
chk "run 1 me panel DB salamat" "grep -q 'salamat hai' <<<\"\${OUT1}\""
chk "run 1 end me sab saaf" "[[ \$(python3 -c 'import json,os; s=json.load(open(os.environ[\"ACP_FAKE_STATE\"])); print(len(s[\"databases\"]), len(s[\"accounts\"]))') == '1 0' ]]"

# ------------------------------------------------------------------ run 2 -----
echo
echo "-- run 2: db.restore chup-chaap kuch import nahi karta (script ko FAIL dena hi hoga)"
reset_state
set +e
OUT2="$(ACP_FAKE_BREAK=restore bash tools/verify/s10-mysql-restore-check.sh 2>&1)"; RC2=$?
set -e
echo "${OUT2}" | grep -E 'ok |FAIL|LIVE CHECK' | sed 's/^/     /' | head -20
chk "run 2 exit != 0" "[[ ${RC2} -ne 0 ]]"
chk "run 2 me 'nahi bana' FAIL" "grep -q 'nahi bana' <<<\"\${OUT2}\""
chk "run 2 me fail count > 0" "! grep -q '0 fail' <<<\"\${OUT2}\""

echo
echo "=== S10-MYSQL-RESTORE-SIM: ${PASS} pass, ${FAIL} fail ==="
[[ ${FAIL} -eq 0 ]]
