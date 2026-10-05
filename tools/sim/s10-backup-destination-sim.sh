#!/usr/bin/env bash
# =============================================================================
# AlphaCP S10 — OFFLINE simulator for tools/verify/s10-backup-destination-check.sh
#
# Root ki zaroorat NAHI. Ye verify karta hai ki live-check script sahi cheezein
# maangti hai:
#   run 1: sab theek (fake paneld agent jaisa behave kare) -> script 0 fail de
#   run 2: fake paneld host-key pin IGNORE kare -> script ko FAIL dena hi chahiye
#          (warna live check "sab theek" ka jhootha bharosa dega)
#   run 3: door ke server par checksum mismatch ho -> push fail hona chahiye aur
#          .part file wahan nahi rehni chahiye
#   run 4: ssh-keyscan ka order badal jaye to bhi 0 fail (LIVE bug ka regression)
#
# Chalane ka tarika:  bash tools/sim/s10-backup-destination-sim.sh
# =============================================================================
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
W="$(mktemp -d /tmp/acp-destsim-XXXXXX)"
echo "=== S10-BACKUP-DESTINATION-SIM (work dir: ${W}) ==="

mkdir -p "${W}/bin" "${W}/home/agent/bin" "${W}/home/agent/config" "${W}/home/incoming" "${W}/ssh"

# ------------------------------------------------------------- fake paneld ---
cat > "${W}/fake_paneld.py" <<'PY'
#!/usr/bin/env python3
"""Fake paneld: agent ke backup.destination ke niyam mirror karta hai (bina network)."""
import hashlib, json, os, re, sys

HOME = os.environ['ACP_FAKE_HOME']
CFG_DIR = os.path.join(HOME, 'etc', 'backup-destinations')
KEY_DIR = os.path.join(HOME, 'etc', 'backup-keys')
BACKUP_ROOT = os.path.join(HOME, 'backups')
FAKE_FP = 'SHA256:8Ph7mQ0FakeFingerprintAAAAAAAAAAAAAAAAAAAAAAA'
BREAK = os.environ.get('ACP_FAKE_BREAK', '')   # nopin | sshfail | pushsha

NAME_RE = re.compile(r'^[a-z0-9][a-z0-9-]{0,31}$')
HOST_RE = re.compile(r'^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$')
USER_RE = re.compile(r'^[a-z_][a-z0-9_-]{0,31}$')
PATH_RE = re.compile(r'^/[A-Za-z0-9._/-]+$')

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

if type_ != 'backup.destination':
    fail("fake paneld doesn't know task '%s'" % type_)
if payload.get('_confirm') != 'backup.destination':
    fail("destructive task 'backup.destination' requires _confirm='backup.destination'")

action = payload.get('action', '')
if action not in ('list', 'save', 'test', 'push', 'browse', 'remove'):
    fail("backup.destination action '%s' nahi chalega (list/save/test/push/browse/remove)" % action)

def cfg_path(name):
    return os.path.join(CFG_DIR, name + '.json')

def load(name):
    p = cfg_path(name)
    if not os.path.isfile(p):
        return None
    return json.load(open(p))

def shred(p):
    if os.path.isfile(p):
        os.remove(p)

if action == 'list':
    names = sorted(f[:-5] for f in os.listdir(CFG_DIR)) if os.path.isdir(CFG_DIR) else []
    emit('success', destinations=[load(n) for n in names], count=len(names))

name = payload.get('name', '')
if not name or not NAME_RE.match(name):
    fail('destination name galat hai (sirf a-z, 0-9 aur -)')

if action == 'save':
    host, user, path = payload.get('host', ''), payload.get('user', ''), payload.get('path', '')
    if not host or host.startswith('-') or re.search(r'\s', host):
        fail('remote host is missing or not a valid hostname/IP')
    if not HOST_RE.match(host) and not re.match(r'^[0-9.]+$', host):
        fail('remote host is not a valid hostname or IP')
    if not USER_RE.match(user or ''):
        fail('remote user is not a valid SSH user name')
    if not PATH_RE.match(path or '') or '..' in path:
        fail("remote path must be absolute and free of '..'")
    fp = payload.get('host_fingerprint', '')
    if fp and not re.match(r'^SHA256:[A-Za-z0-9+/]+={0,2}$', fp):
        fail('host_fingerprint galat hai')
    if not fp and payload.get('accept_host_key') is True:
        fp = FAKE_FP
    if not fp:
        old = load(name)
        fp = (old or {}).get('host_fingerprint', '')
    if not fp:
        fail("destination '%s' ke liye host key pin chahiye" % name)

    auth = payload.get('auth', 'key')
    os.makedirs(CFG_DIR, exist_ok=True)
    os.makedirs(KEY_DIR, exist_ok=True)
    public_key = None
    if auth == 'password':
        if not payload.get('password'):
            fail('password auth chuna gaya par password hi nahi diya')
        open(os.path.join(KEY_DIR, name + '.password'), 'w').write(payload['password'])
        os.chmod(os.path.join(KEY_DIR, name + '.password'), 0o600)
    else:
        pem = payload.get('private_key', '')
        if pem:
            if 'PRIVATE KEY' not in pem:
                fail('private_key PEM jaisa nahi lagta')
            open(os.path.join(KEY_DIR, name), 'w').write(pem)
            os.chmod(os.path.join(KEY_DIR, name), 0o600)
        else:
            open(os.path.join(KEY_DIR, name), 'w').write('-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n')
            os.chmod(os.path.join(KEY_DIR, name), 0o600)
            open(os.path.join(KEY_DIR, name) + '.pub', 'w').write('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFAKE alphacp-backup')
            public_key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFAKE alphacp-backup'
    cfg = {
        'name': name, 'host': host, 'port': int(payload.get('port', 22)), 'user': user,
        'path': path, 'auth': auth, 'retention_days': int(payload.get('retention_days', 30)),
        'host_fingerprint': fp, 'enabled': payload.get('enabled', True) is not False,
        'has_key': auth == 'key', 'has_password': auth == 'password',
    }
    open(cfg_path(name), 'w').write(json.dumps(cfg))
    if public_key:
        cfg['public_key'] = public_key
    emit('success', destination=cfg, saved=True)

cfg = load(name)
if cfg is None:
    fail("destination '%s' nahi mili — pehle save karo" % name)

if action == 'remove':
    shred(cfg_path(name))
    shred(os.path.join(KEY_DIR, name))
    shred(os.path.join(KEY_DIR, name) + '.pub')
    shred(os.path.join(KEY_DIR, name) + '.password')
    emit('success', name=name, removed=True, host=cfg.get('host', ''))

# --- har network action se pehle host key pin check -------------------------
if cfg.get('host_fingerprint'):
    if cfg['host_fingerprint'] != FAKE_FP and BREAK != 'nopin':
        fail('host key MISMATCH for %s: pinned %s, server ne ye diye: %s'
             % (cfg.get('host', ''), cfg['host_fingerprint'], FAKE_FP))

if action == 'test':
    if BREAK == 'sshfail':
        fail('ssh: connect to host %s port 22: Connection refused' % cfg.get('host', ''))
    d = cfg['path']
    os.makedirs(d, exist_ok=True)
    probe = os.path.join(d, '.acp-probe-sim')
    open(probe, 'w').write('token')
    os.remove(probe)                      # probe file wapas saaf
    emit('success', ok=True, name=name, host=cfg['host'], user=cfg['user'],
         path=d, fingerprint=FAKE_FP, key_type='ED25519', auth=cfg['auth'], duration_ms=1)

if action == 'browse':
    d = cfg['path']
    files = sorted(f for f in (os.listdir(d) if os.path.isdir(d) else [])
                  if re.search(r'\.(tar|tar\.gz|tgz)$', f, re.I))
    emit('success', name=name, host=cfg['host'], path=d, files=files, count=len(files))

if action == 'push':
    if cfg.get('enabled') is False:
        fail("destination '%s' disabled hai" % name)
    src = payload.get('archive_path', '')
    if not src.startswith('/') or '..' in src or not PATH_RE.match(src):
        fail("archive_path galat hai")
    if not re.match(r'^[A-Za-z0-9][A-Za-z0-9._-]*$', os.path.basename(src)) \
       or not re.search(r'\.(tar|tar\.gz|tgz)$', src, re.I):
        fail('sirf .tar / .tar.gz / .tgz archive push ho sakti hai')
    real = os.path.realpath(src)
    if not real.startswith(os.path.realpath(BACKUP_ROOT) + os.sep) or not os.path.isfile(real):
        fail('archive %s backup store (%s) ke andar nahi hai' % (src, BACKUP_ROOT))
    data = open(real, 'rb').read()
    d = cfg['path']
    os.makedirs(d, exist_ok=True)
    final = os.path.join(d, os.path.basename(real))
    part = final + '.part'
    open(part, 'wb').write(data)           # atomic: pehle .part, phir rename
    sha = hashlib.sha256(data).hexdigest()
    if BREAK == 'pushsha':
        os.remove(part)                    # remote check fail -> dono hata do
        if os.path.isfile(final):
            os.remove(final)
        fail("destination '%s' par checksum mismatch (local %s, remote %s) — remote file hata di"
             % (name, sha, 'f' * 64))
    os.replace(part, final)
    emit('success', ok=True, name=name, host=cfg['host'], user=cfg['user'], path=d,
         file=os.path.basename(real), remote_path=final, bytes=len(data),
         sha256=sha, verified=True, duration_ms=1)

fail("unhandled action '%s'" % action)
PY

printf "<?php\nreturn ['backup.destination' => []];\n" > "${W}/home/agent/config/tasks.php"

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
cat > "${W}/bin/ssh-keygen" <<'EOF'
#!/usr/bin/env bash
if [[ " $* " == *" -t "* ]]; then
  out=""; while [[ $# -gt 0 ]]; do [[ "$1" == "-f" ]] && out="$2"; shift; done
  echo "-----BEGIN OPENSSH PRIVATE KEY-----" > "${out}"
  echo "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFAKE acp-s10-dest-check" > "${out}.pub"
  exit 0
fi
file=""; while [[ $# -gt 0 ]]; do [[ "$1" == "-f" ]] && file="$2"; shift; done
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
export ACP_FAKE_HOME="${W}/home" ACP_FAKE_DROP="${W}/home/incoming"
export PATH="${W}/bin:${PATH}"

PASS=0; FAIL=0
chk() { if eval "$2" >/dev/null 2>&1; then PASS=$((PASS+1)); printf '  ok   %s\n' "$1"; else FAIL=$((FAIL+1)); printf '  FAIL %s\n' "$1"; fi; }

echo
echo "-- run 1: sab theek (script 0 fail dena chahiye)"
mkdir -p "${W}/ssh" && : > "${W}/ssh/authorized_keys"
set +e
OUT1="$(bash "${REPO}/tools/verify/s10-backup-destination-check.sh" 2>&1)"; RC1=$?
set -e
echo "${OUT1}" | grep -E 'ok |FAIL|skip|BACKUP DESTINATION LIVE CHECK' | sed 's/^/     /' | head -30 || true
chk "run 1 exit 0" "[[ ${RC1} -eq 0 ]]"
chk "run 1 validation refuse proof" "grep -q 'host smuggling' <<<\"${OUT1}\""
chk "run 1 save hua" "grep -q 'destination save ho gayi' <<<\"${OUT1}\""
chk "run 1 key 0600 store hui" "grep -q 'private key 0600' <<<\"${OUT1}\""
chk "run 1 test chala" "grep -q 'test success' <<<\"${OUT1}\""
chk "run 1 push success" "grep -q 'push task success' <<<\"${OUT1}\""
chk "run 1 remote sha256 match" "grep -q 'remote sha256 bilkul match' <<<\"${OUT1}\""
chk "run 1 koi .part nahi chhoda" "grep -q 'koi adhoori .part file' <<<\"${OUT1}\""
chk "run 1 browse chala" "grep -q 'browse success' <<<\"${OUT1}\""
chk "run 1 MITM refuse" "grep -q 'galat host key pin (MITM jaisa)' <<<\"${OUT1}\""
chk "run 1 remove ne key hata di" "grep -q 'destination config + key dono hat gaye' <<<\"${OUT1}\""
chk "run 1 authorized_keys wapas saaf" "[[ ! -s ${W}/ssh/authorized_keys ]]"
chk "run 1 me koi destination config nahi reh gaya" "[[ -z \"\$(ls -A ${W}/home/etc/backup-destinations 2>/dev/null)\" ]]"

echo
echo "-- run 2: fake paneld host-key pin IGNORE kare (script ko FAIL dena hi hoga)"
mkdir -p "${W}/ssh" && : > "${W}/ssh/authorized_keys"
set +e
OUT2="$(ACP_FAKE_BREAK=nopin bash "${REPO}/tools/verify/s10-backup-destination-check.sh" 2>&1)"; RC2=$?
set -e
echo "${OUT2}" | grep -E 'ok |FAIL|BACKUP DESTINATION LIVE CHECK' | sed 's/^/     /' | head -8 || true
chk "run 2 exit != 0" "[[ ${RC2} -ne 0 ]]"
chk "run 2 me 'MISMATCH' wala check fail hua" "grep -q 'MISMATCH' <<<\"${OUT2}\""
chk "run 2 me fail count > 0" "! grep -qE '[0-9]+ pass, 0 fail' <<<\"${OUT2}\""

echo
echo "-- run 3: door ke server par checksum mismatch (push fail + .part saaf)"
mkdir -p "${W}/ssh" && : > "${W}/ssh/authorized_keys"
set +e
OUT3="$(ACP_FAKE_BREAK=pushsha bash "${REPO}/tools/verify/s10-backup-destination-check.sh" 2>&1)"; RC3=$?
set -e
echo "${OUT3}" | grep -E 'FAIL|BACKUP DESTINATION LIVE CHECK' | sed 's/^/     /' | head -8 || true
chk "run 3 exit != 0" "[[ ${RC3} -ne 0 ]]"
chk "run 3 me push fail hua" "grep -q 'push fail' <<<\"${OUT3}\""
chk "run 3 me checksum mismatch dikha" "grep -q 'checksum mismatch' <<<\"${OUT3}\""

echo
echo "-- run 4: ssh-keyscan ka order badle (RSA pehle) to bhi 0 fail"
mkdir -p "${W}/ssh" && : > "${W}/ssh/authorized_keys"
set +e
OUT4="$(ACP_SIM_KEY_ORDER=2 bash "${REPO}/tools/verify/s10-backup-destination-check.sh" 2>&1)"; RC4=$?
set -e
echo "${OUT4}" | grep -E 'FAIL|BACKUP DESTINATION LIVE CHECK' | sed 's/^/     /' | head -8 || true
chk "run 4 exit 0 (order badla)" "[[ ${RC4} -eq 0 ]]"
chk "run 4 me push hua" "grep -q 'push task success' <<<\"${OUT4}\""

rm -rf "${W}"

echo
echo "=== S10-BACKUP-DESTINATION-SIM: ${PASS} pass, ${FAIL} fail ==="
[[ ${FAIL} -eq 0 ]]
