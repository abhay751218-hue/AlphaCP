#!/usr/bin/env bash
# =============================================================================
# AlphaCP S10 — LIVE verification: remote pull (cpmove archive purane server se
# SSH se lana), server par ROOT ke saath. Sab kuch apne aap saaf ho jata hai.
#
# Do hissa:
#   A) input validation (network ki zaroorat nahi) — host smuggling, '..' path,
#      ghalat dest name, adhoora auth → sab refuse hone chahiye.
#   B) asli pull — localhost ko "purana server" bana kar: ek kacchi (throwaway)
#      ed25519 key, /root/.ssh/authorized_keys me ek line (trap me restore),
#      phir probe → pin → scp → sha256 verify.
#
# Test objects: <WORK>/src/cpmove-<stamp>.tar.gz (source),
#               ${ACP_HOME}/incoming/acp-pull-check-<stamp>.tar.gz (destination)
# =============================================================================
set -uo pipefail

ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"; export ACP_HOME
PHP_BIN="${ACP_PHP:-$(command -v php8.4 || command -v php || true)}"
PANELD="${ACP_VERIFY_PANELD:-${ACP_HOME}/agent/bin/paneld}"
INCOMING="${ACP_IMPORT_DIR:-${ACP_HOME}/incoming}"
WORK="${ACP_VERIFY_WORK:-${ACP_HOME}/var/s10-verify}"
STAMP="$(date -u +%Y%m%d%H%M%S)"
SRC_NAME="cpmove-acppull${STAMP}.tar.gz"
DEST_NAME="acp-pull-check-${STAMP}.tar.gz"

PASS=0; FAIL=0; SKIP=0; TASK_IDS=""; LAST_TASK_ID=""; LAST_ERR=""; DONE=0
SSH_DIR="${ACP_VERIFY_SSH_DIR:-/root/.ssh}"
AK="${SSH_DIR}/authorized_keys"; AK_BACKUP=""; AK_CREATED=0; KEYDIR=""
ok()   { PASS=$((PASS+1)); printf '  \033[32mok\033[0m   %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
skip() { SKIP=$((SKIP+1)); printf '  \033[33mskip\033[0m %s\n' "$1"; }
info() { printf '  --   %s\n' "$1"; }

if [[ "${ACP_VERIFY_ALLOW_NONROOT:-0}" != "1" && "$(id -u)" -ne 0 ]]; then
  echo "root ke saath chalao: sudo bash $0"; exit 1
fi
[[ -n "${PHP_BIN}" && -x "${PHP_BIN}" ]] || { echo "php binary nahi mila (ACP_PHP=... set karo)"; exit 1; }
[[ -x "${PANELD}" ]] || { echo "paneld nahi mila: ${PANELD}"; exit 1; }
grep -q "'backup[.]pull'" "${ACP_HOME}/agent/config/tasks.php" 2>/dev/null \
  || { echo "agent me backup.pull task nahi hai — pehle 0.72.0 update karo"; exit 1; }

cleanup() {
  if [[ "${DONE}" != "1" ]]; then info "cleanup (script beech me ruka)"; fi
  # authorized_keys hamesha waise hi wapas — warna kacchi key server par reh jayegi
  if [[ -n "${AK_BACKUP}" && -f "${AK_BACKUP}" ]]; then
    cat "${AK_BACKUP}" > "${AK}" 2>/dev/null || true
    rm -f "${AK_BACKUP}"
  elif [[ "${AK_CREATED}" == "1" ]]; then
    rm -f "${AK}" 2>/dev/null || true
  fi
  [[ -n "${KEYDIR}" ]] && rm -rf "${KEYDIR}" 2>/dev/null
  rm -rf "${WORK}/src" "${WORK}/pull-src" 2>/dev/null || true
  return 0
}
trap cleanup EXIT

run_task() {  # type payload -> 0/1 ; LAST_TASK_ID + LAST_ERR set
  local type="$1" payload="$2" out
  out="$("${PHP_BIN}" "${PANELD}" --run "${type}" "${payload}" 2>&1)"
  LAST_TASK_ID="$(grep -o '"task_id": *[0-9]*' <<<"${out}" | grep -o '[0-9]*' | head -1)"
  [[ -n "${LAST_TASK_ID}" ]] && TASK_IDS="${TASK_IDS}${TASK_IDS:+, }${type}#${LAST_TASK_ID}"
  if grep -q '"status": "success"' <<<"${out}"; then
    LAST_ERR=""
    return 0
  fi
  LAST_ERR="$(grep -m1 '"error": "' <<<"${out}" | cut -d'"' -f4)"
  [[ -z "${LAST_ERR}" ]] && LAST_ERR="$(tail -1 <<<"${out}")"
  return 1
}

echo "=== S10 REMOTE PULL LIVE CHECK ==="
info "ACP_HOME : ${ACP_HOME}"
info "drop dir : ${INCOMING}"
info "work     : ${WORK}"
echo

# ------------------------------------------------------------------ part A ----
info "A: input validation (network ke bina — sab refuse hona chahiye)"
mkdir -p "${WORK}/src" || { echo "work dir ban nahi paya"; exit 1; }

refuse() {  # $1 description, $2 payload
  if run_task backup.pull "$2"; then
    bad "$1 — task chal gaya (refuse hona chahiye tha)"
  else
    ok "$1 (refuse: ${LAST_ERR:-unknown})"
  fi
}

refuse "host smuggling (-oProxyCommand)" \
  "{\"host\":\"-oProxyCommand=evil.example.com\",\"user\":\"root\",\"remote_path\":\"/home/a.tar.gz\",\"private_key\":\"-----BEGIN OPENSSH PRIVATE KEY-----\",\"_confirm\":\"backup.pull\"}"
refuse "ghalat host (space)" \
  "{\"host\":\"old example.com\",\"user\":\"root\",\"remote_path\":\"/home/a.tar.gz\",\"private_key\":\"-----BEGIN OPENSSH PRIVATE KEY-----\",\"_confirm\":\"backup.pull\"}"
refuse "remote path me .." \
  "{\"host\":\"old.example.com\",\"user\":\"root\",\"remote_path\":\"/home/../etc/passwd.tar.gz\",\"private_key\":\"-----BEGIN OPENSSH PRIVATE KEY-----\",\"_confirm\":\"backup.pull\"}"
refuse "dest_name tarball nahi" \
  "{\"host\":\"old.example.com\",\"user\":\"root\",\"remote_path\":\"/home/a.tar.gz\",\"dest_name\":\"evil.sh\",\"private_key\":\"-----BEGIN OPENSSH PRIVATE KEY-----\",\"_confirm\":\"backup.pull\"}"
refuse "key auth bina key" \
  "{\"host\":\"old.example.com\",\"user\":\"root\",\"remote_path\":\"/home/a.tar.gz\",\"_confirm\":\"backup.pull\"}"
refuse "password auth bina password" \
  "{\"host\":\"old.example.com\",\"user\":\"root\",\"remote_path\":\"/home/a.tar.gz\",\"auth\":\"password\",\"_confirm\":\"backup.pull\"}"

# ------------------------------------------------------------------ part B ----
echo
info "B: asli pull (localhost ko purana server bana kar)"

SSHD_ACTIVE=0
for u in ssh sshd; do [[ "$(systemctl is-active "$u" 2>/dev/null)" == "active" ]] && SSHD_ACTIVE=1; done
ROOT_LOGIN="$(sshd -T 2>/dev/null | grep -i '^permitrootlogin' | awk '{print $2}' | head -1)"
command -v ssh-keygen >/dev/null 2>&1 || ROOT_LOGIN="no-keygen"

if [[ "${SSHD_ACTIVE}" != "1" ]]; then
  skip "sshd active nahi — asli pull test chhoda (validation upar ho gaya)"
elif [[ -f "${AK}" ]] && [[ ! -w "${AK}" ]]; then
  skip "${AK} likhne layak nahi — asli pull test chhoda"
elif [[ "${ROOT_LOGIN}" == "no" ]]; then
  skip "sshd me PermitRootLogin=no — asli pull test chhoda (key auth allowed nahi)"
else
  # source archive (jo "remote" server par hai)
  printf 'fake-cpmove-archive-for-acp-pull-check-%s\n' "${STAMP}" > "${WORK}/src/index.html"
  tar czf "${WORK}/src/${SRC_NAME}" -C "${WORK}/src" index.html \
    || { bad "source archive ban nahi paya"; DONE=1; exit 1; }
  SRC="${WORK}/src/${SRC_NAME}"
  SRC_SHA="$(sha256sum "${SRC}" | cut -d' ' -f1)"
  SRC_BYTES="$(stat -c%s "${SRC}")"
  ok "source archive ban gaya: ${SRC} (${SRC_BYTES} bytes)"

  # kacchi key + authorized_keys me ek line (cleanup me wapas)
  KEYDIR="$(mktemp -d /tmp/acp-pullkey-XXXXXX)"
  ssh-keygen -q -t ed25519 -N '' -C 'acp-s10-pull-check' -f "${KEYDIR}/key" >/dev/null 2>&1 \
    || { bad "ssh-keygen fail"; DONE=1; exit 1; }
  if [[ -f "${AK}" ]]; then
    AK_BACKUP="$(mktemp /tmp/acp-authorized_keys-XXXXXX)"
    cat "${AK}" > "${AK_BACKUP}"
  else
    AK_CREATED=1; mkdir -p "${SSH_DIR}"; chmod 0700 "${SSH_DIR}"; : > "${AK}"; chmod 0600 "${AK}"
  fi
  printf '%s acp-s10-pull-check\n' "$(cat "${KEYDIR}/key.pub")" >> "${AK}"
  ok "kacchi SSH key authorized_keys me daal di (ant me hata di jayegi)"

  KEY_PEM="$(python3 -c 'import json,sys; print(json.dumps(open(sys.argv[1]).read()))' "${KEYDIR}/key")"

  # 1) probe — fingerprint
  if run_task backup.pull "{\"host\":\"127.0.0.1\",\"user\":\"root\",\"remote_path\":\"${SRC}\",\"probe\":true,\"_confirm\":\"backup.pull\"}"; then
    FP="$(printf '%s' "${LAST_TASK_ID}" >/dev/null; true)"
    # probe result task se nahi milta — paneld --run sirf status deta hai, isliye
    # fingerprint doosre tarike se nikalte hain: ssh-keyscan se (agents ke jaisa)
    # Ek server kai keys (ed25519/ecdsa/rsa) dikha sakta hai aur keyscan ka order
    # har baar badal sakta hai — isliye hamesha ED25519 wala lo (agent bhi yahi
    # prefer karta hai), warna "pehli line" ka fingerprint flapping karega.
    ssh-keyscan -t ed25519,ecdsa,rsa 127.0.0.1 2>/dev/null > "${KEYDIR}/kh"
    FP="$(ssh-keygen -l -E sha256 -f "${KEYDIR}/kh" 2>/dev/null | awk '/\(ED25519\)/ {print $2}' | head -1)"
    [[ -z "${FP}" ]] && FP="$(ssh-keygen -l -E sha256 -f "${KEYDIR}/kh" 2>/dev/null | awk '{print $2}' | head -1)"
    if [[ -n "${FP}" ]]; then
      ok "host key fingerprint mila: ${FP} (task #${LAST_TASK_ID})"
    else
      bad "fingerprint nahi mila"
    fi
  else
    bad "probe fail: ${LAST_ERR:-unknown}"
    FP=""
  fi

  if [[ -n "${FP}" ]]; then
    # 2) galat fingerprint -> refuse
    refuse "galat host key pin (MITM jaisa)" \
      "{\"host\":\"127.0.0.1\",\"user\":\"root\",\"remote_path\":\"${SRC}\",\"private_key\":${KEY_PEM},\"host_fingerprint\":\"SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA\",\"_confirm\":\"backup.pull\"}"

    # 3) asli pull — pinned fingerprint + inline key
    if run_task backup.pull "{\"host\":\"127.0.0.1\",\"user\":\"root\",\"remote_path\":\"${SRC}\",\"auth\":\"key\",\"private_key\":${KEY_PEM},\"dest_name\":\"${DEST_NAME}\",\"sha256\":\"${SRC_SHA}\",\"host_fingerprint\":\"${FP}\",\"_confirm\":\"backup.pull\"}"; then
      ok "pull task success (task #${LAST_TASK_ID})"
    else
      bad "pull fail: ${LAST_ERR:-unknown}"
    fi

    DEST="${INCOMING}/${DEST_NAME}"
    if [[ -f "${DEST}" ]]; then
      ok "archive drop dir me aa gaya: ${DEST}"
    else
      bad "archive drop dir me nahi mila (${DEST})"
    fi
    if [[ -f "${DEST}" && "$(sha256sum "${DEST}" | cut -d' ' -f1)" == "${SRC_SHA}" ]]; then
      ok "sha256 bilkul match (${SRC_BYTES} bytes)"
    else
      bad "sha256/size match nahi hua"
    fi
    if [[ -n "$(ls -A "${WORK}" 2>/dev/null | grep -E '^\.acp-pull-')" ]]; then
      bad "koi .part file reh gayi"
    else
      ok "koi adhoori .part file nahi chhodi"
    fi

    # 4) galat sha256 -> refuse, aur file overwrite na ho
    refuse "galat sha256 (download ke baad check fail)" \
      "{\"host\":\"127.0.0.1\",\"user\":\"root\",\"remote_path\":\"${SRC}\",\"private_key\":${KEY_PEM},\"dest_name\":\"${DEST_NAME}\",\"sha256\":\"$(printf 'a%.0s' {1..64})\",\"host_fingerprint\":\"${FP}\",\"overwrite\":true,\"_confirm\":\"backup.pull\"}"
    if [[ -f "${DEST}" && "$(sha256sum "${DEST}" | cut -d' ' -f1)" == "${SRC_SHA}" ]]; then
      ok "galat sha256 wali pull ne purani achhi file bigadi nahi"
    else
      bad "galat sha256 wali pull ne file kharaab kar di"
    fi

    # 5) bina pin ke -> refuse (accept_host_key ke bina)
    refuse "bina pinned host key ke pull" \
      "{\"host\":\"127.0.0.1\",\"user\":\"root\",\"remote_path\":\"${SRC}\",\"private_key\":${KEY_PEM},\"_confirm\":\"backup.pull\"}"

    # saafai: test archive drop dir se hatao
    rm -f "${DEST}" 2>/dev/null
    [[ ! -f "${DEST}" ]] && ok "test archive drop dir se hata diya" || bad "test archive abhi bhi hai"
  fi
fi

DONE=1
echo
echo "=== S10 REMOTE PULL LIVE CHECK: ${PASS} pass, ${FAIL} fail, ${SKIP} skip ==="
info "tasks: ${TASK_IDS}"
[[ ${FAIL} -eq 0 ]]
