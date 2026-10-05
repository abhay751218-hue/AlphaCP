#!/usr/bin/env bash
# =============================================================================
# AlphaCP S10 — OFFLINE simulator for tools/verify/s10-remote-pull-check.sh
#
# Root ki zaroorat NAHI. Ye verify karta hai ki live-check script sahi cheezein
# maangti hai:
#   run 1: sab theek (fake paneld agent jaisa behave kare) -> script 0 fail de
#   run 2: fake paneld host-key pin IGNORE kare -> script ko FAIL dena hi chahiye
#          (warna live check "sab theek" ka jhootha bharosa dega)
#
# Chalane ka tarika:  bash tools/sim/s10-remote-pull-sim.sh
# =============================================================================
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
W="$(mktemp -d /tmp/acp-pullsim-XXXXXX)"
export ACP_FAKE_STATE="${W}/state.json"
echo "=== S10-REMOTE-PULL-SIM (work dir: ${W}) ==="

python3 -c 'import json,os; json.dump({}, open(os.environ["ACP_FAKE_STATE"],"w"))'

mkdir -p "${W}/bin" "${W}/home/agent/bin" "${W}/home/agent/config" "${W}/home/incoming" "${W}/ssh"

# ------------------------------------------------------------- fake paneld ---
cat > "${W}/fake_paneld.py" <<'PY'
#!/usr/bin/env python3
"""Fake paneld: agent ke backup.pull ke niyam mirror karta hai (bina network)."""
import json, os, re, shutil, sys

STATE = os.environ['ACP_FAKE_STATE']
DROP = os.environ['ACP_FAKE_DROP']
FAKE_FP = 'SHA256:8Ph7mQ0FakeFingerprintAAAAAAAAAAAAAAAAAAAAAAA'
BREAK = os.environ.get('ACP_FAKE_BREAK', '')      # 'nopull' = pin ignore kare

def emit(status, **extra):
    out = {'status': status}
    out.update(extra)
    print(json.dumps(out))
    sys.exit(0 if status == 'success' else 1)

def fail(msg):
    print(json.dumps({'status': 'failed', 'error': msg}))
    sys.exit(1)

args = sys.argv[1:]
if len(args) < 3 or args[0] != '--run':
    fail('usage: paneld --run <type> <payload>')
type_ = args[1]
payload = json.loads(args[2])

if type_ != 'backup.pull':
    fail("fake paneld doesn't know task '%s'" % type_)

if payload.get('_confirm') != 'backup.pull':
    fail("destructive task 'backup.pull' requires _confirm='backup.pull'")

host = payload.get('host', '')
user = payload.get('user', '')
path = payload.get('remote_path', '')

# host: FQDN/IP; koi smuggling nahi (leading dash = scp option injection)
if not host or host.startswith('-') or re.search(r'\s', host):
    fail('remote host is missing or not a valid hostname/IP')
if not re.match(r'^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$', host) \
   and not re.match(r'^[0-9.]+$', host):
    fail('remote host is not a valid hostname or IP')
if not re.match(r'^[a-z_][a-z0-9_-]{0,31}$', user or ''):
    fail('remote user is not a valid SSH user name')
if not re.match(r'^/[A-Za-z0-9._/-]+$', path or '') or '..' in path:
    fail("remote path must be absolute and free of '..'")

if payload.get('probe'):
    emit('success', probe=True, host=host, key_type='ED25519', fingerprint=FAKE_FP)

dest_name = payload.get('dest_name') or os.path.basename(path)
if not re.match(r'^[A-Za-z0-9][A-Za-z0-9._-]*$', dest_name) \
   or not re.search(r'\.(tar|tar\.gz|tgz)$', dest_name, re.I):
    fail('destination file name must end in .tar, .tar.gz or .tgz')

auth = payload.get('auth', 'key')
if auth == 'key' and not payload.get('private_key'):
    fail('key auth ke liye private_key (PEM) ya key_path do')
if auth == 'password' and not payload.get('password'):
    fail('password auth chuna gaya par password hi nahi diya')

fp = payload.get('host_fingerprint', '')
if fp and fp != FAKE_FP and BREAK != 'nopull':
    fail('host key MISMATCH for %s: expected %s, server presented %s' % (host, fp, FAKE_FP))
if not fp and not payload.get('accept_host_key'):
    fail('host key of %s is not pinned (%s)' % (host, FAKE_FP))

src = path
if not os.path.isfile(src):
    fail('remote file not found: %s' % src)
data = open(src, 'rb').read()
want = payload.get('sha256') or ''
if want:
    import hashlib
    got = hashlib.sha256(data).hexdigest()
    if got != want:
        fail('checksum mismatch: remote file %s hai, admin ne %s bola' % (got, want))

os.makedirs(DROP, exist_ok=True)
part = os.path.join(DROP, '.acp-pull-sim.part')
open(part, 'wb').write(data)          # atomic: pehle .part, phir rename
final = os.path.join(DROP, dest_name)
os.replace(part, final)
emit('success', path=final, name=dest_name, bytes=len(data),
     sha256=__import__('hashlib').sha256(data).hexdigest(), host=host,
     user=user, fingerprint=FAKE_FP, key_type='ED25519', auth=auth, duration_ms=1)
PY

printf "<?php\nreturn ['backup.pull' => []];\n" > "${W}/home/agent/config/tasks.php"

cat > "${W}/home/agent/bin/paneld" <<EOF
#!/usr/bin/env bash
exec python3 "${W}/fake_paneld.py" "\$@"
EOF
chmod 0755 "${W}/home/agent/bin/paneld"

# --------------------------------------------------- fake ssh tooling (PATH) ---
cat > "${W}/bin/systemctl" <<'EOF'
#!/usr/bin/env bash
[[ "$1" == "is-active" && ( "$2" == "ssh" || "$2" == "sshd" ) ]] && { echo active; exit 0; }
echo inactive
EOF
cat > "${W}/bin/sshd" <<'EOF'
#!/usr/bin/env bash
[[ "$1" == "-T" ]] && echo "permitrootlogin yes"
exit 0
EOF
# Asli server ki tarah KAI host keys (ed25519 + ecdsa + rsa), aur har call par
# unka order badalta hai (ssh-keyscan order stable nahi hota) — isi wajah se
# "pehli line ka fingerprint" wala bug liva par nikla tha.
cat > "${W}/bin/ssh-keygen" <<'EOF'
#!/usr/bin/env bash
if [[ " $* " == *" -t "* ]]; then
  out=""; while [[ $# -gt 0 ]]; do [[ "$1" == "-f" ]] && out="$2"; shift; done
  echo "-----BEGIN OPENSSH PRIVATE KEY-----" > "${out}"
  echo "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFAKE acp-s10-pull-check" > "${out}.pub"
  exit 0
fi
file=""; while [[ $# -gt 0 ]]; do [[ "$1" == "-f" ]] && file="$2"; shift; done
# order: baari-baari ulta (1st call: ed,ec,rsa — 2nd: rsa,ed,ec — 3rd: ec,rsa,ed ...)
case "${ACP_SIM_KEY_ORDER:-1}" in
  1) order="ED25519 ECDSA RSA" ;;
  2) order="RSA ED25519 ECDSA" ;;
  *) order="ECDSA RSA ED25519" ;;
esac
: > "${file}.out"
for t in ${order}; do
  case "${t}" in
    ED25519) echo "256 SHA256:8Ph7mQ0FakeFingerprintAAAAAAAAAAAAAAAAAAAAAAA 127.0.0.1 (ED25519)" >> "${file}.out" ;;
    ECDSA)   echo "256 SHA256:ECDSAfakeFingerprintAAAAAAAAAAAAAAAAAAAAAAAAAA 127.0.0.1 (ECDSA)" >> "${file}.out" ;;
    RSA)     echo "256 SHA256:RSAfakeFingerprintAAAAAAAAAAAAAAAAAAAAAAAAAAAA 127.0.0.1 (RSA)" >> "${file}.out" ;;
  esac
done
cat "${file}.out"
exit 0
EOF
cat > "${W}/bin/ssh-keyscan" <<'EOF'
#!/usr/bin/env bash
echo "127.0.0.1 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFAKE"
echo "127.0.0.1 ecdsa-sha2-nistp256 AAAAE2VjZHNhLQAAAAAAAAAAAAAA"
echo "127.0.0.1 ssh-rsa AAAAB3NzaC1yc2EAAAAAAAAAAAAAAAA"
EOF
chmod 0755 "${W}/bin/systemctl" "${W}/bin/sshd" "${W}/bin/ssh-keygen" "${W}/bin/ssh-keyscan"

export ACP_HOME="${W}/home" ACP_PHP=/bin/bash ACP_VERIFY_ALLOW_NONROOT=1
export ACP_VERIFY_PANELD="${W}/home/agent/bin/paneld" ACP_VERIFY_SSH_DIR="${W}/ssh"
export ACP_IMPORT_DIR="${W}/home/incoming" ACP_FAKE_DROP="${W}/home/incoming"
export PATH="${W}/bin:${PATH}"

PASS=0; FAIL=0
chk() { if eval "$2" >/dev/null 2>&1; then PASS=$((PASS+1)); printf '  ok   %s\n' "$1"; else FAIL=$((FAIL+1)); printf '  FAIL %s\n' "$1"; fi; }

# ------------------------------------------------------------------ run 1 -----
echo
echo "-- run 1: sab theek (script 0 fail dena chahiye)"
mkdir -p "${W}/ssh" && : > "${W}/ssh/authorized_keys"
set +e
OUT1="$(bash "${REPO}/tools/verify/s10-remote-pull-check.sh" 2>&1)"; RC1=$?
set -e
echo "${OUT1}" | sed 's/^/     /'
chk "run 1 exit 0" "[[ ${RC1} -eq 0 ]]"
chk "run 1 validation refuse proof" "grep -q 'host smuggling' <<<\"${OUT1}\""
chk "run 1 me asli pull hua" "grep -q 'pull task success' <<<\"${OUT1}\""
chk "run 1 sha256 match" "grep -q 'sha256 bilkul match' <<<\"${OUT1}\""
chk "run 1 koi .part nahi chhoda" "grep -q 'koi adhoori .part file nahi' <<<\"${OUT1}\""
chk "run 1 galat sha256 refuse" "grep -q 'galat sha256' <<<\"${OUT1}\""
chk "run 1 authorized_keys wapas saaf" "[[ ! -s ${W}/ssh/authorized_keys ]]"
chk "run 1 drop dir saaf" "[[ -z \"\$(ls -A ${W}/home/incoming 2>/dev/null)\" ]]"

# ------------------------------------------------------------------ run 2 -----
echo
echo "-- run 2: fake paneld host-key pin ignore kare (script ko FAIL dena hi hoga)"
mkdir -p "${W}/ssh" && : > "${W}/ssh/authorized_keys"
set +e
OUT2="$(ACP_FAKE_BREAK=nopull bash "${REPO}/tools/verify/s10-remote-pull-check.sh" 2>&1)"; RC2=$?
set -e
echo "${OUT2}" | grep -E 'ok |FAIL|REMOTE PULL LIVE CHECK' | sed 's/^/     /' | head -8 || true
chk "run 2 exit != 0" "[[ ${RC2} -ne 0 ]]"
chk "run 2 me 'refuse' wala check fail hua" "grep -q 'MISMATCH\\|refuse' <<<\"${OUT2}\""
chk "run 2 me fail count > 0" "! grep -qE '[0-9]+ pass, 0 fail' <<<\"${OUT2}\""


# ------------------------------------------------------------------ run 3 -----
# LIVE bug (5 Oct): ssh-keyscan kai keys laata hai aur unka order stable nahi hota.
# "Pehli line ka fingerprint" lene se probe aur pull alag fingerprint pakadte the ->
# hamesha MISMATCH. Ab agent pin ko kisi bhi key se match karta hai aur verifier
# hamesha ED25519 wala leta hai — isliye har order me 0 fail aana chahiye.
echo
echo "-- run 3: host keys ka order badalne par bhi 0 fail (LIVE bug ka regression test)"
mkdir -p "${W}/ssh" && : > "${W}/ssh/authorized_keys"
set +e
OUT3="$(ACP_SIM_KEY_ORDER=2 bash "${REPO}/tools/verify/s10-remote-pull-check.sh" 2>&1)"; RC3=$?
set -e
echo "${OUT3}" | sed 's/^/     /' | head -25 || true
chk "run 3 exit 0 (order badla)" "[[ ${RC3} -eq 0 ]]"
chk "run 3 me pull hua" "grep -q 'pull task success' <<<\"${OUT3}\""

rm -rf "${W}"

echo
echo "=== S10-REMOTE-PULL-SIM: ${PASS} pass, ${FAIL} fail ==="
[[ ${FAIL} -eq 0 ]]
