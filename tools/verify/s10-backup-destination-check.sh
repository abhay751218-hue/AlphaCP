#!/usr/bin/env bash
# =============================================================================
# AlphaCP S10 — LIVE verification: remote backup destinations (apne archives
# doosre server par bhejna), server par ROOT ke saath. Sab kuch apne aap saaf
# ho jata hai.
#
# Do hissa:
#   A) input validation (network ki zaroorat nahi) — galat naam, bina pin ke
#      save, host smuggling, '..' wala path, backup store ke bahar archive,
#      unknown action, unknown destination -> sab refuse hone chahiye.
#   B) asli push — localhost ko "backup server" bana kar: ek kacchi (throwaway)
#      ed25519 key, /root/.ssh/authorized_keys me ek line (trap me restore),
#      phir save -> test -> push -> browse -> (galat pin = MISMATCH) -> remove.
#
# Test objects: ${ACP_HOME}/backups/accounts/acp-dest-check/<hex>.tar.gz (source),
#               ${WORK}/dest/ (door wala server ka directory)
# =============================================================================
set -uo pipefail

ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"; export ACP_HOME
PHP_BIN="${ACP_PHP:-$(command -v php8.4 || command -v php || true)}"
PANELD="${ACP_VERIFY_PANELD:-${ACP_HOME}/agent/bin/paneld}"
WORK="${ACP_VERIFY_WORK:-${ACP_HOME}/var/s10-verify}"
STAMP="$(date -u +%Y%m%d%H%M%S)"
NAME="acp-dest-check"
NAME_BAD="acp-dest-bad"
AID="$(head -c 16 /dev/urandom 2>/dev/null | od -An -tx1 | tr -d ' \n')"
[[ "${AID}" =~ ^[a-f0-9]{32}$ ]] || AID="$(printf '%032x' "${STAMP}")"
ARCHIVE_DIR="${ACP_HOME}/backups/accounts/acp-dest-check"
ARCHIVE="${ARCHIVE_DIR}/${AID}.tar.gz"
DEST_DIR="${WORK}/dest"
CFG_DIR="${ACP_HOME}/etc/backup-destinations"
KEY_STORE="${ACP_HOME}/etc/backup-keys"

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
grep -q "'backup[.]destination'" "${ACP_HOME}/agent/config/tasks.php" 2>/dev/null \
  || { echo "agent me backup.destination task nahi hai — pehle 0.73.0 update karo"; exit 1; }

cleanup() {
  if [[ "${DONE}" != "1" ]]; then info "cleanup (script beech me ruka)"; fi
  if [[ -n "${AK_BACKUP}" && -f "${AK_BACKUP}" ]]; then
    cat "${AK_BACKUP}" > "${AK}" 2>/dev/null || true
    rm -f "${AK_BACKUP}"
  elif [[ "${AK_CREATED}" == "1" ]]; then
    rm -f "${AK}" 2>/dev/null || true
  fi
  [[ -n "${KEYDIR}" ]] && rm -rf "${KEYDIR}" 2>/dev/null
  rm -rf "${DEST_DIR}" 2>/dev/null || true
  # test ne banaya hua dummy archive + uski account dir (khali hone par hi)
  rm -f "${ARCHIVE}" 2>/dev/null || true
  [[ -d "${ARCHIVE_DIR}" ]] && rmdir "${ARCHIVE_DIR}" 2>/dev/null || true
  # destination config/key kabhi bhi server par nahi rehne chahiye
  rm -f "${CFG_DIR}/${NAME}.json" "${CFG_DIR}/${NAME_BAD}.json" 2>/dev/null || true
  rm -f "${KEY_STORE}/${NAME}" "${KEY_STORE}/${NAME}.pub" "${KEY_STORE}/${NAME}.password" 2>/dev/null || true
  rm -f "${KEY_STORE}/${NAME_BAD}" "${KEY_STORE}/${NAME_BAD}.pub" "${KEY_STORE}/${NAME_BAD}.password" 2>/dev/null || true
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
  LAST_ERR="$(grep -o '"error": *"[^"]*"' <<<"${out}" | head -1 | sed 's/.*"error": *"//; s/"$//')"
  [[ -z "${LAST_ERR}" ]] && LAST_ERR="$(tail -1 <<<"${out}")"
  return 1
}

echo "=== S10 BACKUP DESTINATION LIVE CHECK ==="
info "ACP_HOME : ${ACP_HOME}"
info "work     : ${WORK}"
info "dest dir : ${DEST_DIR}"
echo

# ------------------------------------------------------------------ part A ----
info "A: input validation (network ke bina — sab refuse hona chahiye)"
mkdir -p "${WORK}" || { echo "work dir ban nahi paya"; exit 1; }

refuse() {  # $1 description, $2 payload
  if run_task backup.destination "$2"; then
    bad "$1 — task chal gaya (refuse hona chahiye tha)"
  else
    ok "$1 (refuse: ${LAST_ERR:-unknown})"
  fi
}

refuse "galat destination naam (../evil)" \
  "{\"action\":\"save\",\"name\":\"../evil\",\"host\":\"backup.example.com\",\"user\":\"backup\",\"path\":\"/srv/backups\",\"host_fingerprint\":\"SHA256:ED25519keyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA\",\"_confirm\":\"backup.destination\"}"
refuse "bina host key pin ke save" \
  "{\"action\":\"save\",\"name\":\"${NAME}\",\"host\":\"backup.example.com\",\"user\":\"backup\",\"path\":\"/srv/backups\",\"_confirm\":\"backup.destination\"}"
refuse "host smuggling (-oProxyCommand)" \
  "{\"action\":\"save\",\"name\":\"${NAME}\",\"host\":\"-oProxyCommand=evil.example.com\",\"user\":\"backup\",\"path\":\"/srv/backups\",\"host_fingerprint\":\"SHA256:ED25519keyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA\",\"_confirm\":\"backup.destination\"}"
refuse "remote path me .." \
  "{\"action\":\"save\",\"name\":\"${NAME}\",\"host\":\"backup.example.com\",\"user\":\"backup\",\"path\":\"/srv/../../etc\",\"host_fingerprint\":\"SHA256:ED25519keyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA\",\"_confirm\":\"backup.destination\"}"
refuse "unknown action" \
  "{\"action\":\"destroy\",\"name\":\"${NAME}\",\"_confirm\":\"backup.destination\"}"
refuse "push: backup store ke bahar wali file" \
  "{\"action\":\"push\",\"name\":\"${NAME}\",\"archive_path\":\"/etc/passwd.tar.gz\",\"_confirm\":\"backup.destination\"}"
refuse "push: jo archive hai hi nahi" \
  "{\"action\":\"push\",\"name\":\"${NAME}\",\"archive_path\":\"${ACP_HOME}/backups/accounts/acp-dest-check/00000000000000000000000000000000.tar.gz\",\"_confirm\":\"backup.destination\"}"
refuse "test: aisi destination jo hai hi nahi" \
  "{\"action\":\"test\",\"name\":\"no-such-destination\",\"_confirm\":\"backup.destination\"}"

# ------------------------------------------------------------------ part B ----
echo
info "B: asli push (localhost ko backup server bana kar)"

SSHD_ACTIVE=0
for u in ssh sshd; do [[ "$(systemctl is-active "$u" 2>/dev/null)" == "active" ]] && SSHD_ACTIVE=1; done
ROOT_LOGIN="$(sshd -T 2>/dev/null | grep -i '^permitrootlogin' | awk '{print $2}' | head -1)"
command -v ssh-keygen >/dev/null 2>&1 || ROOT_LOGIN="no-keygen"
command -v scp >/dev/null 2>&1 || ROOT_LOGIN="${ROOT_LOGIN:-no-scp}-noscp"
command -v ssh >/dev/null 2>&1 || ROOT_LOGIN="${ROOT_LOGIN}-nossh"

if [[ "${SSHD_ACTIVE}" != "1" ]]; then
  skip "sshd active nahi — asli push test chhoda (validation upar ho gaya)"
elif [[ ! -x /usr/bin/scp || ! -x /usr/bin/ssh ]]; then
  skip "scp/ssh nahi mila — asli push test chhoda (openssh-client chahiye)"
elif [[ -f "${AK}" ]] && [[ ! -w "${AK}" ]]; then
  skip "${AK} likhne layak nahi — asli push test chhoda"
elif [[ "${ROOT_LOGIN}" == "no" ]]; then
  skip "sshd me PermitRootLogin=no — asli push test chhoda (key auth allowed nahi)"
else
  # 1) kacchi key + authorized_keys me ek line (cleanup me wapas)
  KEYDIR="$(mktemp -d /tmp/acp-destkey-XXXXXX)"
  ssh-keygen -q -t ed25519 -N '' -C 'acp-s10-dest-check' -f "${KEYDIR}/key" >/dev/null 2>&1 \
    || { bad "ssh-keygen fail"; DONE=1; exit 1; }
  if [[ -f "${AK}" ]]; then
    AK_BACKUP="$(mktemp /tmp/acp-authorized_keys-XXXXXX)"
    cat "${AK}" > "${AK_BACKUP}"
  else
    AK_CREATED=1; mkdir -p "${SSH_DIR}"; chmod 0700 "${SSH_DIR}"; : > "${AK}"; chmod 0600 "${AK}"
  fi
  printf '%s acp-s10-dest-check\n' "$(cat "${KEYDIR}/key.pub")" >> "${AK}"
  ok "kacchi SSH key authorized_keys me daal di (ant me hata di jayegi)"

  # 2) host key fingerprint — hamesha ED25519 wala (keyscan ka order unstable hota hai)
  ssh-keyscan -t ed25519,ecdsa,rsa 127.0.0.1 2>/dev/null > "${KEYDIR}/kh"
  FP="$(ssh-keygen -l -E sha256 -f "${KEYDIR}/kh" 2>/dev/null | awk '/\(ED25519\)/ {print $2}' | head -1)"
  [[ -z "${FP}" ]] && FP="$(ssh-keygen -l -E sha256 -f "${KEYDIR}/kh" 2>/dev/null | awk '{print $2}' | head -1)"
  if [[ -n "${FP}" ]]; then
    ok "host key fingerprint mila: ${FP}"
  else
    bad "fingerprint nahi mila"
    FP=""
  fi

  # 3) source archive — hamare backup store me (push sirf wahin se allowed hai)
  mkdir -p "${ARCHIVE_DIR}" 2>/dev/null || { bad "${ARCHIVE_DIR} ban nahi payi"; FP=""; }
  if [[ -n "${FP}" ]]; then
    printf 'fake-archive-for-acp-destination-check-%s\n' "${STAMP}" > "${WORK}/index.html"
    tar czf "${ARCHIVE}" -C "${WORK}" index.html \
      || { bad "source archive ban nahi paya"; FP=""; }
  fi
  if [[ -n "${FP}" && -f "${ARCHIVE}" ]]; then
    SRC_SHA="$(sha256sum "${ARCHIVE}" | cut -d' ' -f1)"
    SRC_BYTES="$(stat -c%s "${ARCHIVE}")"
    ok "source archive ban gaya: ${ARCHIVE} (${SRC_BYTES} bytes)"

    KEY_PEM="$(python3 -c 'import json,sys; print(json.dumps(open(sys.argv[1]).read()))' "${KEYDIR}/key")"
    mkdir -p "${DEST_DIR}" || { bad "dest dir ban nahi payi"; FP=""; }
  fi

  if [[ -n "${FP}" && -f "${ARCHIVE}" && -d "${DEST_DIR}" ]]; then
    # 4) save
    if run_task backup.destination "{\"action\":\"save\",\"name\":\"${NAME}\",\"host\":\"127.0.0.1\",\"port\":22,\"user\":\"root\",\"path\":\"${DEST_DIR}\",\"auth\":\"key\",\"private_key\":${KEY_PEM},\"host_fingerprint\":\"${FP}\",\"retention_days\":30,\"_confirm\":\"backup.destination\"}"; then
      ok "destination save ho gayi (task #${LAST_TASK_ID})"
    else
      bad "save fail: ${LAST_ERR:-unknown}"
    fi
    if [[ -f "${CFG_DIR}/${NAME}.json" ]]; then
      ok "config likhi gayi: ${CFG_DIR}/${NAME}.json"
    else
      bad "config file nahi mili (${CFG_DIR}/${NAME}.json)"
    fi
    if [[ -f "${KEY_STORE}/${NAME}" && "$(stat -c%a "${KEY_STORE}/${NAME}" 2>/dev/null)" == "600" ]]; then
      ok "private key 0600 me store hui (result/argv me kabhi nahi)"
    else
      bad "private key file nahi mili ya 0600 nahi"
    fi

    # 5) test — door ke server par likh/padh/delete
    if run_task backup.destination "{\"action\":\"test\",\"name\":\"${NAME}\",\"_confirm\":\"backup.destination\"}"; then
      ok "test success (task #${LAST_TASK_ID})"
    else
      bad "test fail: ${LAST_ERR:-unknown}"
    fi
    if [[ -n "$(ls -A "${DEST_DIR}" 2>/dev/null | grep -E '^\.acp-probe-')" ]]; then
      bad "probe file reh gayi (remote saaf nahi hua)"
    else
      ok "remote probe file saaf ho gayi"
    fi

    # 6) push
    if run_task backup.destination "{\"action\":\"push\",\"name\":\"${NAME}\",\"archive_path\":\"${ARCHIVE}\",\"_confirm\":\"backup.destination\"}"; then
      ok "push task success (task #${LAST_TASK_ID})"
    else
      bad "push fail: ${LAST_ERR:-unknown}"
    fi
    REMOTE_FILE="${DEST_DIR}/${AID}.tar.gz"
    if [[ -f "${REMOTE_FILE}" ]]; then
      ok "archive door ke server par pahunch gaya: ${REMOTE_FILE}"
    else
      bad "archive door ke server par nahi mila (${REMOTE_FILE})"
    fi
    if [[ -f "${REMOTE_FILE}" && "$(sha256sum "${REMOTE_FILE}" | cut -d' ' -f1)" == "${SRC_SHA}" ]]; then
      ok "remote sha256 bilkul match (${SRC_BYTES} bytes)"
    else
      bad "remote sha256 match nahi hua"
    fi
    if compgen -G "${DEST_DIR}/*.part" >/dev/null 2>&1; then
      bad "adhoori .part file remote par reh gayi"
    else
      ok "koi adhoori .part file remote par nahi chhodi"
    fi

    # 7) browse
    if run_task backup.destination "{\"action\":\"browse\",\"name\":\"${NAME}\",\"_confirm\":\"backup.destination\"}"; then
      ok "browse success (task #${LAST_TASK_ID})"
    else
      bad "browse fail: ${LAST_ERR:-unknown}"
    fi

    # 8) MITM: galat pin wali destination — test ko MISMATCH pakadna hi chahiye
    if run_task backup.destination "{\"action\":\"save\",\"name\":\"${NAME_BAD}\",\"host\":\"127.0.0.1\",\"user\":\"root\",\"path\":\"${DEST_DIR}\",\"auth\":\"key\",\"private_key\":${KEY_PEM},\"host_fingerprint\":\"SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA\",\"_confirm\":\"backup.destination\"}"; then
      ok "galat pin wali destination save to ho gayi (par test MISMATCH dega)"
    else
      bad "galat pin wali destination save fail: ${LAST_ERR:-unknown}"
    fi
    refuse "galat host key pin (MITM jaisa)" \
      "{\"action\":\"test\",\"name\":\"${NAME_BAD}\",\"_confirm\":\"backup.destination\"}"

    # 9) remove — config + key dono jane chahiye
    if run_task backup.destination "{\"action\":\"remove\",\"name\":\"${NAME}\",\"_confirm\":\"backup.destination\"}"; then
      ok "remove success (task #${LAST_TASK_ID})"
    else
      bad "remove fail: ${LAST_ERR:-unknown}"
    fi
    if [[ ! -f "${CFG_DIR}/${NAME}.json" && ! -f "${KEY_STORE}/${NAME}" ]]; then
      ok "destination config + key dono hat gaye"
    else
      bad "remove ke baad bhi config/key maujood hai"
    fi
    run_task backup.destination "{\"action\":\"remove\",\"name\":\"${NAME_BAD}\",\"_confirm\":\"backup.destination\"}" >/dev/null 2>&1 || true
  fi
fi

DONE=1
echo
echo "=== S10 BACKUP DESTINATION LIVE CHECK: ${PASS} pass, ${FAIL} fail, ${SKIP} skip ==="
info "tasks: ${TASK_IDS}"

# report ko GitHub snapshot ke liye bhi likh do (hourly alphacp-sync le jata hai)
REPORT_DIR="${ACP_HOME}/verify-reports"
if mkdir -p "${REPORT_DIR}" 2>/dev/null; then
  {
    echo "=== S10 BACKUP DESTINATION LIVE CHECK ($(date -u +%Y-%m-%dT%H:%M:%SZ)) ==="
    echo "pass=${PASS} fail=${FAIL} skip=${SKIP}"
    echo "tasks: ${TASK_IDS}"
  } > "${REPORT_DIR}/s10-backup-destination-check.txt" 2>/dev/null || true
fi

[[ ${FAIL} -eq 0 ]]
