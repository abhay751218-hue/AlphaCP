#!/usr/bin/env bash
# =============================================================================
# AlphaCP — offline sim for tools/verify/s8-live-check.sh  (v0.70.0, root nahi chahiye)
#
# Fake paneld + fake MariaDB client se S8 live-check ka POORA flow chalata hai:
#   run 1 : sab sahi  -> 0 fail (script exit 0)
#   run 2 : db.create chup-chaap kuch na kare (break) -> script ko FAIL dena hi hoga (exit != 0)
# Isse pata chalta hai ki live-check ke assertions asli me kuch check karte hain.
# =============================================================================
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1
W="$(mktemp -d /tmp/acp-s8check-XXXXXX)"
trap 'rm -rf "${W}"' EXIT
echo "=== S8-LIVE-CHECK-SIM (work dir: ${W}) ==="

mkdir -p "${W}/bin" "${W}/home/agent/bin" "${W}/home/etc"
printf 'ACP_DB_HOST=127.0.0.1\nACP_DB_PORT=3306\nACP_DB_NAME=alphacp\nACP_DB_USER=alphacp\nACP_DB_PASS=x\n' \
  > "${W}/home/etc/database.env"
export ACP_FAKE_STATE="${W}/state.json"; echo '{}' > "${ACP_FAKE_STATE}"

# ------------------------------------------------------------- fake mariadb ---
cat > "${W}/fake_sql.py" <<'PY'
#!/usr/bin/env python3
"""Minimal MariaDB stand-in: sirf wahi queries jinhe s8-live-check.sh poochta hai."""
import json, os, re, sys

state = json.load(open(os.environ['ACP_FAKE_STATE']))
dbs   = state.get('databases', {})
users = state.get('users', {})
tasks = state.get('tasks', [])

args, user, sql = sys.argv[1:], None, ''
i = 0
while i < len(args):
    if args[i] == '-u' and i + 1 < len(args): user, i = args[i + 1], i + 2; continue
    if args[i] == '-e' and i + 1 < len(args): sql,  i = args[i + 1], i + 2; continue
    i += 1

def q(pat, s=sql): return (re.search(pat, s) or [None, None])[1] if re.search(pat, s) else None

if user:                                  # login test (MYSQL_PWD se)
    entry = users.get(user)
    if not entry or os.environ.get('MYSQL_PWD') != entry['password']:
        print(f"ERROR 1045 (28000): Access denied for user '{user}'@'localhost' (using password: YES)")
        sys.exit(1)
    print('login-ok' if 'login-ok' in sql else ('rotated-ok' if 'rotated-ok' in sql else '1'))
    sys.exit(0)

m = re.search(r"SHOW DATABASES LIKE '([^']+)'", sql)
if m: print(m.group(1) if m.group(1) in dbs else ''); sys.exit(0)
m = re.search(r"SCHEMA_NAME FROM information_schema\.SCHEMATA WHERE SCHEMA_NAME='([^']+)'", sql)
if m: print(m.group(1) if m.group(1) in dbs else ''); sys.exit(0)
m = re.search(r"DEFAULT_CHARACTER_SET_NAME FROM information_schema\.SCHEMATA WHERE SCHEMA_NAME='([^']+)'", sql)
if m: print(dbs.get(m.group(1), {}).get('charset', '')); sys.exit(0)
if 'FROM alphacp.accounts' in sql:
    accs = list(state.get('accounts', {}).items())          # [{name: status}, ...]
    active = [a for a, st in accs if st == 'active']
    pick = (active or [a for a, _ in accs])
    print(pick[0] if pick else ''); sys.exit(0)
m = re.search(r"SELECT User FROM mysql\.user WHERE User='([^']+)'", sql)
if m: print(m.group(1) if m.group(1) in users else ''); sys.exit(0)
m = re.search(r"SELECT Host FROM mysql\.user WHERE User='([^']+)'", sql)
if m: print('\n'.join(users[m.group(1)]['hosts']) if m.group(1) in users else ''); sys.exit(0)
m = re.search(r"SHOW GRANTS FOR '([^']+)'@'([^']+)'", sql)
if m:
    u = users.get(m.group(1))
    if not u: print(f"ERROR 1141 (42000): There is no such grant defined for user '{m.group(1)}' on host '{m.group(2)}'"); sys.exit(1)
    print(f"GRANT USAGE ON *.* TO `{m.group(1)}`@`{m.group(2)}`")
    for db in u.get('grants', []):
        print(f"GRANT ALL PRIVILEGES ON `{db}`.* TO `{m.group(1)}`@`{m.group(2)}`")
    sys.exit(0)
if 'SELECT payload FROM' in sql:
    print(tasks[-1]['payload'] if tasks else ''); sys.exit(0)
m = re.search(r"SELECT Db FROM mysql\.db WHERE Db='([^']+)'", sql)
if m:
    hits = [db for u in users.values() for db in u.get('grants', []) if db == m.group(1)]
    print('\n'.join(sorted(set(hits)))); sys.exit(0)

print(f"ERROR 1064 (42000): fake client doesn't know: {sql}"); sys.exit(1)
PY

cat > "${W}/bin/mariadb" <<EOF
#!/usr/bin/env bash
exec python3 "${W}/fake_sql.py" "\$@"
EOF

# -------------------------------------------------------------- fake paneld ---
cat > "${W}/fake_paneld.py" <<'PY'
#!/usr/bin/env python3
"""Minimal paneld stand-in: real task semantics (including the _confirm guard) ka nakal."""
import json, os, sys

state_path = os.environ['ACP_FAKE_STATE']
state = json.load(open(state_path))
state.setdefault('databases', {}); state.setdefault('users', {}); state.setdefault('tasks', [])
args = sys.argv[1:]
type_, payload = args[1], (json.loads(args[2]) if len(args) > 2 else {})
break_mode = os.environ.get('ACP_FAKE_BREAK', '')

def scrub(p):
    p = dict(p)
    if p.get('password'): p['password'] = '***'
    return p

def emit(status, error=None, code=0):
    tid = len(state['tasks']) + 1
    state['tasks'].append({'type': type_, 'payload': json.dumps(scrub(payload), separators=(',', ':'))})
    json.dump(state, open(state_path, 'w'))
    print(json.dumps({'task_id': tid, 'status': status, 'duration_ms': 5,
                      'result': {'task': type_} if status == 'success' else None,
                      'error': error}, indent=4, separators=(',', ': ')))
    sys.exit(code)

acct, suffix = payload.get('username'), payload.get('name') or payload.get('user')
db, user = f"{acct}_{suffix}", f"{acct}_{suffix}"

if type_ in ('db.drop', 'db.user.drop') and payload.get('_confirm') != type_:
    emit('failed', error=f"destructive task '{type_}' requires _confirm='{type_}'", code=1)

if type_ == 'account.create':
    state.setdefault('accounts', {})[payload['username']] = 'active'; emit('success')
if type_ == 'account.terminate':
    state.get('accounts', {}).pop(payload['username'], None); emit('success')
if type_ == 'db.create':
    if break_mode != 'create': state['databases'][db] = {'charset': 'utf8mb4'}
    emit('success')
if type_ == 'db.user.create':
    state['users'][user] = {'password': payload['password'], 'hosts': ['localhost'],
                            'grants': [f"{acct}_{g}" for g in payload.get('databases', [])]}
    emit('success')
if type_ == 'db.user.grant':
    state['users'].setdefault(user, {'password': '', 'hosts': ['localhost'], 'grants': []})['grants'].append(f"{acct}_{suffix}")
    emit('success')
if type_ == 'db.user.password':
    if user not in state['users']: emit('failed', error=f'MariaDB user {user}@localhost does not exist on this server', code=1)
    state['users'][user]['password'] = payload['password']
    emit('success')
if type_ == 'db.user.drop':
    state['users'].pop(user, None); emit('success')
if type_ == 'db.drop':
    state['databases'].pop(db, None)
    for u in state['users'].values(): u['grants'] = [g for g in u.get('grants', []) if g != db]
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

PASS=0; FAIL=0
chk() { if eval "$2" >/dev/null 2>&1; then PASS=$((PASS+1)); printf '  ok   %s\n' "$1"; else FAIL=$((FAIL+1)); printf '  FAIL %s\n' "$1"; fi; }

# ------------------------------------------------------------------ run 1 -----
echo
echo "-- run 1: sab theek (sab checks pass hone chahiye)"
set +e
OUT1="$(bash tools/verify/s8-live-check.sh 2>&1)"; RC1=$?
set -e
echo "${OUT1}" | sed 's/^/     /'
chk "run 1 exit 0" "[[ ${RC1} -eq 0 ]]"
chk "run 1 me '0 fail'" "grep -q 'S8 LIVE CHECK: .* 0 fail' <<<\"\${OUT1}\""
chk "run 1 me login proof" "grep -q 'ASLI login kar raha hai' <<<\"\${OUT1}\""
chk "run 1 me scrub proof" "grep -q 'password scrub (\\*\\*\\*) ho gaya' <<<\"\${OUT1}\""
chk "run 1 me temp account auto-create" "grep -q 'temp account acpv' <<<\"\${OUT1}\""
chk "run 1 me temp account terminate" "grep -q 'hat gaya (task' <<<\"\${OUT1}\""
chk "run 1 end me objects saaf" "[[ \"\$(python3 -c \"import json,os; s=json.load(open(os.environ['ACP_FAKE_STATE'])); print(len(s['databases']), len(s['users']))\")\" == \"0 0\" ]]"

# ------------------------------------------------------------------ run 2 -----
echo
echo "-- run 2: db.create chup-chaap kuch nahi banata (script ko FAIL dena hi hoga)"
echo '{}' > "${ACP_FAKE_STATE}"
set +e
OUT2="$(ACP_FAKE_BREAK=create bash tools/verify/s8-live-check.sh 2>&1)"; RC2=$?
set -e
echo "${OUT2}" | grep -E 'ok|FAIL|LIVE CHECK' | sed 's/^/     /'
chk "run 2 exit != 0" "[[ ${RC2} -ne 0 ]]"
chk "run 2 me 'database nahi bana' FAIL" "grep -qE 'database acpv.*_acpverify nahi bana' <<<\"\${OUT2}\""
chk "run 2 me fail count > 0" "! grep -q 'S8 LIVE CHECK: .* 0 fail' <<<\"\${OUT2}\""

echo
echo "=== S8-LIVE-CHECK-SIM: ${PASS} pass, ${FAIL} fail ==="
[[ ${FAIL} -eq 0 ]]
