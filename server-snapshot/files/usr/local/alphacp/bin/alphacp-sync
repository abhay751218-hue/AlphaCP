#!/usr/bin/env bash
# =============================================================================
#  AlphaCP — SERVER → GITHUB SYNC  v1.3
#  v1.3: SNAPSHOT COMPLETENESS FIX — `-name backup`/`-name ssl`/`-name keys`/`-name storage`
#        jaisi bare-name prunes hataayi gayi (wo panel ke resources/views/backup/ aur
#        resources/views/ssl/ ko chup-chaap uda deti thi). Secret pattern ab EOL-anchored
#        hai, isliye `PASSWORD = document.getElementById(...)` jaisi normal code lines par
#        poori file drop nahi hoti. STATE.md me ab "Snapshot completeness" section aata hai
#        jo MANIFEST.json ke file count se copy hue files compare karta hai.
#  v1.2: `alphacp-sync get <commit> <path> <out> [sha256]` — deploy key se repo ki file laata hai
#        (PRIVATE repo me bhi chalta hai; raw.githubusercontent private repo par 404 deta hai)
#  v1.1: releases/ (purane backup/failed panel copies) snapshot me nahi — sirf naam STATE.md me;
#        STATE.md me panel MANIFEST version + license/trial haalat (state/expiry, koi secret nahi)
# -----------------------------------------------------------------------------
#  Server par jo bhi install/update hai (panel code, agent, license/trial, configs,
#  DB schema, versions, routes, services) uska SAAF snapshot GitHub repo ke
#  `server-snapshot/` folder me push karta hai — taaki koi bhi naya AI sirf GitHub
#  dekh kar poori situation samajh le.
#
#  SECRETS KABHI PUSH NAHI HOTE:
#    * .env, etc/*.env, var/ (passwords, APP_KEY), storage/, vendor/, keys/certs — copy hi nahi hote
#    * server ke asli secret VALUES padh kar har file me dhoondhe jaate hain — mile to file skip
#    * private key / token / "PASS=..." jaisi lines wali files bhi skip
#    * DB se sirf schema (tables/columns) — koi data/row nahi
#
#  Pehli baar (setup):   sudo bash alphacp-sync.sh
#     -> GitHub deploy key banata hai, screen par dikhata hai, aap GitHub me add karte ho,
#        script khud wait karke check karti hai, phir pehla sync + har ghante auto-sync timer.
#  Baad me (kabhi bhi):  sudo alphacp-sync            (turant sync)
#                        sudo alphacp-sync --status   (setup/timer/last sync dekho)
# =============================================================================
set -uo pipefail

SYNC_VERSION="1.3"
REPO_SLUG="${SYNC_REPO_SLUG:-abhay751218-hue/AlphaCP}"
BRANCH="${SYNC_BRANCH:-main}"
ACP_HOME="${ACP_HOME:-/usr/local/alphacp}"
CONF_DIR="${SYNC_CONF_DIR:-/etc/alphacp-sync}"
KEY="${CONF_DIR}/github_deploy_key"
KNOWN="${CONF_DIR}/known_hosts"
CONF="${CONF_DIR}/sync.conf"
WORK="${SYNC_WORK_DIR:-/var/lib/alphacp-sync}"
LOCK="/run/alphacp-sync.lock"
BIN_DST="${ACP_HOME}/bin/alphacp-sync"
WAIT_SECS="${SYNC_WAIT_SECS:-1800}"      # deploy key add hone ka max wait (30 min)
MAX_FILE=$((2*1024*1024))                # 2 MB se badi file snapshot me nahi

C_G=$'\033[32m'; C_R=$'\033[31m'; C_Y=$'\033[33m'; C_B=$'\033[1m'; C_0=$'\033[0m'
say()  { printf '%s\n' "$*"; }
ok()   { say "${C_G}[OK]${C_0} $*"; }
warn() { say "${C_Y}[!]${C_0} $*"; }
err()  { say "${C_R}[x]${C_0} $*"; }
hdr()  { say ""; say "${C_B}== $* ==${C_0}"; }
die()  { err "$*"; say "    — alphacp-sync v${SYNC_VERSION}"; exit 1; }

[[ "${EUID}" -eq 0 ]] || die "root chahiye:  sudo alphacp-sync   (ya sudo bash $0)"

MODE="sync"
case "${1:-}" in
  --status) MODE="status" ;;
  get) MODE="get"; shift ;;
  --help|-h) sed -n '2,24p' "$0"; exit 0 ;;
  "") ;;
  *) die "unknown option: $1  (use: --status | get <commit> <path> <out> [sha256])" ;;
esac

if [[ "${MODE}" != "get" ]]; then
say ""
say "${C_B}===============================================================${C_0}"
say "${C_B}   AlphaCP SERVER → GITHUB SYNC  -  v${SYNC_VERSION}${C_0}"
say "${C_B}===============================================================${C_0}"
fi

# GitHub ke official SSH host keys (api.github.com/meta se verify kiye) — MITM se bachav
write_known_hosts() {
  mkdir -p "${CONF_DIR}"; chmod 0700 "${CONF_DIR}"
  cat > "${KNOWN}" <<'EOF'
github.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl
[ssh.github.com]:443 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl
EOF
  chmod 0644 "${KNOWN}"
}

REMOTE_URL=""
load_conf() {
  if [[ -n "${SYNC_REPO_URL:-}" ]]; then REMOTE_URL="${SYNC_REPO_URL}"; return; fi   # tests
  [[ -f "${CONF}" ]] && REMOTE_URL="$(sed -n 's/^REMOTE_URL=//p' "${CONF}" | head -1)"
  REMOTE_URL="${REMOTE_URL:-git@github.com:${REPO_SLUG}.git}"
}
export_git_ssh() {
  export GIT_SSH_COMMAND="ssh -i ${KEY} -o IdentitiesOnly=yes -o UserKnownHostsFile=${KNOWN} -o StrictHostKeyChecking=yes -o BatchMode=yes -o ConnectTimeout=15"
  export GIT_TERMINAL_PROMPT=0
}

# ============================================================== get (v1.2)
# alphacp-sync get <40-hex-commit> <repo/path> <out-file> [sha256]
if [[ "${MODE}" == "get" ]]; then
  G_COMMIT="${1:-}"; G_PATH="${2:-}"; G_OUT="${3:-}"; G_SHA="${4:-}"
  [[ "${G_COMMIT}" =~ ^[0-9a-f]{40}$ ]] || die "get: commit poora 40-char SHA hona chahiye (mila: '${G_COMMIT}')"
  [[ -n "${G_PATH}" && "${G_PATH}" != /* && "/${G_PATH}/" != */../* ]] || die "get: galat repo path '${G_PATH}'"
  [[ -n "${G_OUT}" ]] || die "get: output file do  (alphacp-sync get <commit> <path> <out> [sha256])"
  [[ -z "${G_SHA}" || "${G_SHA}" =~ ^[0-9a-f]{64}$ ]] || die "get: sha256 64-char hex hona chahiye"
  [[ -f "${KEY}" ]] || die "get: deploy key nahi hai — pehle setup: sudo alphacp-sync"
  command -v git >/dev/null 2>&1 || die "get: git missing"
  write_known_hosts; load_conf; export_git_ssh
  CACHE="${WORK}/get-cache.git"; mkdir -p "${WORK}"
  exec 8>"${WORK}/get.lock"; flock -w 120 8 || die "get: doosra get chal raha hai"
  [[ -d "${CACHE}/objects" ]] || git init -q --bare "${CACHE}"
  if ! git -C "${CACHE}" cat-file -e "${G_COMMIT}^{commit}" 2>/dev/null; then
    if ! git -C "${CACHE}" fetch -q --no-tags --depth 1 "${REMOTE_URL}" "${G_COMMIT}" 2>"${WORK}/get.err"; then
      # SHA seedha na mile (purana/squash-merged commit) to saare branches + PR refs lao
      git -C "${CACHE}" fetch -q --no-tags "${REMOTE_URL}" '+refs/heads/*:refs/remotes/o/*' '+refs/pull/*/head:refs/remotes/pr/*' 2>>"${WORK}/get.err" || true
    fi
  fi
  git -C "${CACHE}" cat-file -e "${G_COMMIT}^{commit}" 2>/dev/null \
    || die "get: commit ${G_COMMIT:0:12} GitHub se nahi mila ($(tail -1 "${WORK}/get.err" 2>/dev/null))"
  git -C "${CACHE}" cat-file -e "${G_COMMIT}:${G_PATH}" 2>/dev/null || die "get: '${G_PATH}' commit ${G_COMMIT:0:7} me nahi hai"
  TMPG="$(mktemp "${G_OUT}.XXXXXX" 2>/dev/null)" || die "get: '${G_OUT}' likh nahi sakta"
  git -C "${CACHE}" cat-file blob "${G_COMMIT}:${G_PATH}" > "${TMPG}" || { rm -f "${TMPG}"; die "get: file nikal nahi paaya"; }
  GOT="$(sha256sum "${TMPG}" | awk '{print $1}')"
  if [[ -n "${G_SHA}" && "${GOT}" != "${G_SHA}" ]]; then rm -f "${TMPG}"; die "get: sha256 mismatch (mila ${GOT:0:16}…, chahiye ${G_SHA:0:16}…) — file NAHI likhi"; fi
  chmod 0644 "${TMPG}"; mv -f "${TMPG}" "${G_OUT}"
  ok "get: ${G_PATH} @ ${G_COMMIT:0:7} -> ${G_OUT}  (sha256 ${GOT:0:16}…$([[ -n "${G_SHA}" ]] && echo ' verified'))   — alphacp-sync v${SYNC_VERSION}"
  exit 0
fi

# ============================================================== --status
if [[ "${MODE}" == "status" ]]; then
  load_conf
  say "   remote       : ${REMOTE_URL}  (branch ${BRANCH})"
  if [[ -f "${KEY}.pub" ]]; then say "   deploy key   : ${KEY}.pub"; say "   $(cat "${KEY}.pub")"; else say "   deploy key   : (nahi bani)"; fi
  say "   timer        : $(systemctl is-active alphacp-sync.timer 2>/dev/null || echo none)  ($(systemctl show alphacp-sync.timer -p NextElapseUSecRealtime --value 2>/dev/null))"
  if [[ -f "${WORK}/last-result" ]]; then say "   last result  : $(cat "${WORK}/last-result")"; fi
  say "    — alphacp-sync v${SYNC_VERSION}"
  exit 0
fi

exec 9>"${LOCK}"
flock -n 9 || die "doosra sync already chal raha hai — 1 minute baad try karo"

# ============================================================== 1. tools
hdr "Step 1: zaroori tools"
NEED=()
for t in git ssh ssh-keygen python3; do command -v "${t}" >/dev/null 2>&1 || NEED+=("${t}"); done
if (( ${#NEED[@]} )); then
  pk=(); for t in "${NEED[@]}"; do case "$t" in ssh|ssh-keygen) pk+=(openssh-client) ;; *) pk+=("$t") ;; esac; done
  warn "install kar raha hoon: ${pk[*]}"
  DEBIAN_FRONTEND=noninteractive apt-get install -y -q "${pk[@]}" >/dev/null 2>&1 || die "apt install fail: ${pk[*]}"
fi
ok "git $(git --version | awk '{print $3}'), ssh, python3 ready"

# ============================================================== 2. install self + deploy key
hdr "Step 2: setup (self-install + GitHub deploy key)"
mkdir -p "${ACP_HOME}/bin" "${WORK}"
SELF="$(readlink -f "$0" 2>/dev/null || echo "$0")"
if [[ "${SELF}" != "$(readlink -f "${BIN_DST}" 2>/dev/null)" ]]; then
  install -m 0755 "${SELF}" "${BIN_DST}"
  ln -sf "${BIN_DST}" /usr/local/sbin/alphacp-sync
  ok "command install: sudo alphacp-sync   (${BIN_DST})"
fi
write_known_hosts
load_conf

if [[ -z "${SYNC_REPO_URL:-}" ]]; then
  if [[ ! -f "${KEY}" ]]; then
    ssh-keygen -q -t ed25519 -N "" -C "alphacp-sync@$(hostname)" -f "${KEY}" || die "ssh-keygen fail"
    chmod 0600 "${KEY}"; ok "naya deploy key bana: ${KEY}"
  else
    ok "deploy key pehle se hai: ${KEY}"
  fi
  export_git_ssh

  # port 22 band ho to GitHub ka port 443 (ssh.github.com) use karo
  probe() { ssh -i "${KEY}" -o IdentitiesOnly=yes -o UserKnownHostsFile="${KNOWN}" -o StrictHostKeyChecking=yes \
               -o BatchMode=yes -o ConnectTimeout=15 "$@" -T git@"${HOST}" 2>&1; }
  HOST="github.com"; OUT="$(probe -p 22)"
  if ! grep -qiE "successfully authenticated|Permission denied" <<<"${OUT}"; then
    HOST="ssh.github.com"; OUT="$(probe -p 443)"
    if grep -qiE "successfully authenticated|Permission denied" <<<"${OUT}"; then
      REMOTE_URL="ssh://git@ssh.github.com:443/${REPO_SLUG}.git"; warn "port 22 band — GitHub port 443 use hoga"
    else
      die "GitHub se connect nahi ho pa raha: ${OUT}"
    fi
  else
    REMOTE_URL="git@github.com:${REPO_SLUG}.git"
  fi
  printf 'REMOTE_URL=%s\n' "${REMOTE_URL}" > "${CONF}"

  if ! grep -qi "successfully authenticated" <<<"${OUT}"; then
    [[ -t 0 || -t 1 ]] || die "GitHub deploy key kaam nahi kar rahi (timer mode) — terminal me 'sudo alphacp-sync' chalao"
    say ""
    say "${C_Y}${C_B}  >>> EK BAAR KA KAAM: ye key GitHub me add karo (sirf public key hai, safe hai) <<<${C_0}"
    say ""
    say "  1) Browser me kholo:  ${C_B}https://github.com/${REPO_SLUG}/settings/keys/new${C_0}"
    say "  2) Title:  $(hostname)-sync"
    say "  3) Key box me ye POORI line paste karo:"
    say ""
    say "${C_G}$(cat "${KEY}.pub")${C_0}"
    say ""
    say "  4) ${C_B}'Allow write access' par TICK zaroor lagao${C_0}  ->  'Add key'"
    say ""
    say "  Main yahan khud check kar raha hoon (max $((WAIT_SECS/60)) min) — terminal band mat karna..."
    waited=0
    while (( waited < WAIT_SECS )); do
      sleep 10; waited=$((waited+10))
      OUT="$(if [[ "${HOST}" == "github.com" ]]; then probe -p 22; else probe -p 443; fi)"
      if grep -qi "successfully authenticated" <<<"${OUT}"; then break; fi
      (( waited % 60 == 0 )) && say "   ...abhi tak key nahi mili ($((waited/60)) min)"
    done
    grep -qi "successfully authenticated" <<<"${OUT}" || die "key add nahi hui. Key add karke yahi command dobara chalao."
  fi
  ok "GitHub access OK (${REMOTE_URL})"
fi

# ============================================================== 3. repo clone/update
hdr "Step 3: GitHub repo (${BRANCH}) le raha hoon"
REPO="${WORK}/repo"
if [[ -d "${REPO}/.git" ]]; then
  git -C "${REPO}" remote set-url origin "${REMOTE_URL}"
  git -C "${REPO}" fetch -q --depth 20 origin "${BRANCH}" || die "git fetch fail"
  git -C "${REPO}" checkout -q -B "${BRANCH}" "origin/${BRANCH}" && git -C "${REPO}" reset -q --hard "origin/${BRANCH}"
else
  rm -rf "${REPO}"
  git clone -q --depth 20 --branch "${BRANCH}" "${REMOTE_URL}" "${REPO}" || die "git clone fail (${REMOTE_URL})"
fi
git -C "${REPO}" config user.name  "alphacp-sync ($(hostname))"
git -C "${REPO}" config user.email "alphacp-sync@$(hostname).local"
ok "repo ready: ${REPO}"

# ============================================================== 4. secrets list (sirf check ke liye, kahin likhe nahi jaate)
hdr "Step 4: server ke secrets pehchaan raha hoon (taaki galti se bhi push na ho)"
SECRETS="$(mktemp)"; chmod 0600 "${SECRETS}"
trap 'rm -f "${SECRETS}"' EXIT
{
  for f in "${ACP_HOME}"/etc/*.env "${ACP_HOME}"/etc/*.conf "${ACP_HOME}"/panel/.env "${ACP_HOME}"/*/.env "${ACP_HOME}"/var/*.txt /root/.alphacp-admin-credentials; do
    [[ -f "${f}" ]] || continue
    # KEY=VALUE aur "Key : value" dono formats; sirf secret-type keys
    sed -nE 's/^[[:space:]]*[A-Za-z0-9_]*(pass|secret|key|token|salt|private)[A-Za-z0-9_]*[[:space:]]*[=:][[:space:]]*//Ip' "${f}" \
      | sed -E 's/^["'\'']//; s/["'\'']$//' | sed 's/^base64://'
  done
  if [[ -f "${ACP_HOME}/var/panel-appkey.txt" ]]; then sed 's/^base64://' "${ACP_HOME}/var/panel-appkey.txt"; fi
} | awk 'length($0) >= 8 && $0 !~ /^\/[^ ]*$/ && $0 !~ /^(null|true|false|base64:)$/' | sort -u > "${SECRETS}"
ok "$(wc -l < "${SECRETS}") secret values pehchaane (sirf memory/tmp me, push nahi hote)"

# ============================================================== 5. snapshot build
hdr "Step 5: snapshot bana raha hoon"
SNAP="$(mktemp -d)"; trap 'rm -f "${SECRETS}"; rm -rf "${SNAP}"' EXIT
FILES="${SNAP}/files"; mkdir -p "${FILES}"
SKIPPED="${SNAP}/.skipped"; : > "${SKIPPED}"

# ACP_HOME + license/extra folders (agar kisi AI ne /opt ya /srv me kuch rakha ho)
SRC_DIRS=("${ACP_HOME}")
for d in /opt/alphacp* /srv/alphacp* /var/www/alphacp* /usr/local/alphacp-* /opt/*license* /var/www/*license*; do
  [[ -d "${d}" ]] && SRC_DIRS+=("${d}")
done

copy_tree() {  # $1 = source dir; secrets/heavy cheezein prune
  local src="$1"
  # NOTE (v1.3): prunes AB PATH-SCOPED hain. v1.2 tak `-name backup`, `-name ssl`,
  # `-name keys`, `-name storage` jaisi bare-name prunes thi, jo source tree ke andar
  # usi naam ki koi bhi directory uda deti thi. Natija: panel ke
  #   resources/views/backup/index.blade.php  aur  resources/views/ssl/index.blade.php
  # snapshot me kabhi aaye hi nahi (secret scan tak pahunche hi nahi, isliye SKIPPED
  # list me bhi naam nahi tha). Repo se panel dobara banane par /backup aur /ssl
  # "View not found" 500 dete. Ab sirf wahi paths prune hote hain jo sach me
  # secret/runtime hain; baaki sab file-name excludes + secret scan sambhalte hain.
  ( cd / && find "${src#/}" \
      \( -name vendor -o -name node_modules -o -name .git \
         -o -path "${ACP_HOME#/}/etc" -o -path "${ACP_HOME#/}/var" -o -path "${ACP_HOME#/}/releases" \
         -o -path "${ACP_HOME#/}/panel/storage" -o -path "*/panel/storage" \) -prune -o \
      -type f \! \( -name '.env' -o -name '.env.*' -o -name '*.sqlite' -o -name '*.sqlite3' -o -name '*.db' -o -name '*.pem' \
         -o -name '*.key' -o -name '*.crt' -o -name '*.p12' -o -name '*.pfx' -o -name 'id_*' -o -name '*.log' -o -name '*.bak*' \
         -o -name '*.disabled-*' -o -iname '*secret*' -o -iname '*private*' -o -name '*.tar*' -o -name '*.zip' -o -name '*.gz' -o -name '*.sock' \) \
      -size -"${MAX_FILE}"c -print0 ) \
  | while IFS= read -r -d '' rel; do
      mkdir -p "${FILES}/$(dirname "${rel}")"; cp -p "/${rel}" "${FILES}/${rel}" 2>/dev/null || true
    done
}
for d in "${SRC_DIRS[@]}"; do copy_tree "${d}"; done

# system configs jo panel ke liye zaroori hain (in me secrets nahi hote; phir bhi scan hota hai)
for f in /etc/nginx/sites-available/*alphacp* /etc/nginx/conf.d/*alphacp* /etc/php/*/fpm/pool.d/alphacp*.conf \
         /etc/systemd/system/*alphacp* /etc/systemd/system/paneld* /etc/systemd/system/php*-fpm.service.d/*.conf \
         /etc/cron.d/*alphacp* /etc/sudoers.d/*alphacp* /etc/fail2ban/jail.d/*alphacp* /etc/logrotate.d/*alphacp*; do
  [[ -f "${f}" ]] || continue
  case "${f}" in *.disabled-*|*.bak*) continue ;; esac
  mkdir -p "${FILES}$(dirname "${f}")"; cp -p "${f}" "${FILES}${f}"
done

# --- secret scan: literal values + patterns -> file hata do
python3 - "${FILES}" "${SECRETS}" "${SKIPPED}" <<'PY'
import os, re, sys
root, secf, skipf = sys.argv[1], sys.argv[2], sys.argv[3]
secrets = [l.rstrip("\n") for l in open(secf, encoding="utf-8", errors="ignore") if len(l.strip()) >= 8]
# v1.3: KEY=VALUE pattern ab END-OF-LINE anchored hai aur value ya to ek quoted
# literal hona chahiye ya ek "token" jisme code punctuation ( ( ) ; ' " ) na ho.
# v1.2 ka pattern `[^\s'"$]{6,}` kisi bhi code line ko pakad leta tha, jaise:
#     PASSWORD = document.getElementById('password').value;
#     TOKEN = form.querySelector('[name=_token]').value;
# Isliye backup-destinations/transfer-tool ke blade views aur Ssh/TransferTool
# tests poori files drop ho jaati thi. Asli secret ab bhi pakda jata hai:
#     DB_PASSWORD=Sup3rS3cretValue      "PASSWORD": "abc123456",     TOKEN='abcdef'
pat = re.compile(
    r"-----BEGIN [A-Z ]*PRIVATE KEY-----"
    r"|APP_KEY=base64:[A-Za-z0-9+/=]{20,}"
    r"|\bAKIA[0-9A-Z]{16}\b"
    r"|\bgh[pousr]_[A-Za-z0-9]{30,}"
    r"|^\s*[\"']?[A-Z0-9_]*(PASS|PASSWORD|PASSWD|SECRET|TOKEN|API_?KEY)[A-Z0-9_]*[\"']?\s*[:=]\s*"
    r"(?:\"[^\"\n]{6,}\"|'[^'\n]{6}'|[^\s'\"();#]{6,})[\s,;]*$"
    r"|^panel_pass=", re.M)
skipped = []
for dp, dn, fn in os.walk(root):
    for n in fn:
        p = os.path.join(dp, n)
        try:
            data = open(p, "rb").read()
        except Exception:
            continue
        rel = os.path.relpath(p, root)
        if b"\x00" in data[:8192]:
            os.remove(p); skipped.append(f"/{rel}  (binary)"); continue
        text = data.decode("utf-8", errors="ignore")
        why = None
        for s in secrets:
            if s in text:
                why = "server secret value mila"; break
        if not why and pat.search(text):
            why = "secret jaisa pattern"
        if why:
            os.remove(p); skipped.append(f"/{rel}  ({why})")
with open(skipf, "a") as f:
    for s in sorted(skipped):
        f.write(s + "\n")
PY
find "${FILES}" -type d -empty -delete 2>/dev/null || true
ok "files copy: $(find "${FILES}" -type f | wc -l)   skip (secret/binary): $(wc -l < "${SKIPPED}")"

# --- v1.3 completeness check: panel ki kaun si source files snapshot me NAHI pahunchi
# (v1.2 tak bare-name prunes ki wajah se kuch files chup-chaap gayab ho jaati thi aur
#  SKIPPED list me bhi naam nahi aata tha — isliye kisi ko pata hi nahi chalta tha.)
MISSING="${SNAP}/.missing"; : > "${MISSING}"
python3 - "${ACP_HOME}/panel" "${FILES}" "${MISSING}" <<'PY'
import os, sys
panel, root, out = sys.argv[1], sys.argv[2], sys.argv[3]
# runtime/secret dirs jo JAAN-BOOJH kar snapshot me nahi aate
SKIP_PARTS = ("/vendor/", "/node_modules/", "/storage/", "/.git/", "/bootstrap/cache/")
SKIP_NAMES = (".env",)
missing = []
for dp, dn, fn in os.walk(panel):
    for n in fn:
        p = os.path.join(dp, n)
        rel = "/" + os.path.relpath(p, "/")
        if any(s in rel for s in SKIP_PARTS):
            continue
        if n in SKIP_NAMES or n.startswith(".env."):
            continue
        if not os.path.exists(os.path.join(root, rel.lstrip("/"))):
            missing.append(rel)
with open(out, "w") as f:
    for m in sorted(missing):
        f.write(m + "\n")
PY
MCOUNT="$(wc -l < "${MISSING}")"
if [[ "${MCOUNT}" -eq 0 ]]; then
  ok "completeness: panel ki har source file snapshot me hai"
else
  warn "completeness: ${MCOUNT} panel source file snapshot me NAHI aayi (STATE.md dekho)"
fi

# ============================================================== 6. STATE.md (AI ke liye server ki haalat)
hdr "Step 6: STATE.md (versions, services, routes, license files, DB schema)"
PHPBIN=""; for v in 8.5 8.4 8.3; do [[ -x "/usr/bin/php${v}" ]] && { PHPBIN="/usr/bin/php${v}"; break; }; done
PHPBIN="${PHPBIN:-$(command -v php || true)}"
PANEL="${ACP_HOME}/panel"
art() { ( cd "${PANEL}" && timeout 90 runuser -u alphacp -- env ACP_HOME="${ACP_HOME}" "${PHPBIN}" artisan "$@" ) 2>/dev/null; }
envkey() { sed -n "s/^$1=//p" "$2" 2>/dev/null | head -1; }
S="${SNAP}/STATE.md"
PY_ROUTES="$(cat <<'PY'
import json, sys
try:
    rs = json.load(sys.stdin)
except Exception:
    sys.exit(0)
for r in rs:
    m = r.get('method') or ''
    u = '/' + (r.get('uri') or '').lstrip('/')
    n = r.get('name') or ''
    if n.startswith('generated::'):
        n = ''   # random naam har run me badalta hai
    print('%-18s %-45s %s' % (m, u, n))
PY
)"
{
  say "# SERVER STATE — $(hostname)  (auto-generated by alphacp-sync v${SYNC_VERSION})"
  say ""
  say "> Ye file server se automatic banti hai. Haath se edit mat karo — agla sync overwrite kar dega."
  say "> Secrets (passwords, APP_KEY, license keys) jaan-boojh kar is folder me NAHI hain."
  say ""
  say "## System"
  say '```'
  say "os       : $(. /etc/os-release 2>/dev/null; echo "${PRETTY_NAME:-?}")"
  say "kernel   : $(uname -r)   arch: $(uname -m)"
  say "php      : $([[ -n "${PHPBIN}" ]] && "${PHPBIN}" -r 'echo PHP_VERSION;' 2>/dev/null) (${PHPBIN:-none})"
  say "php-all  : $(ls -d /etc/php/*/ 2>/dev/null | cut -d/ -f4 | tr '\n' ' ')"
  say "nginx    : $(command -v nginx >/dev/null && nginx -v 2>&1 | sed 's/.*\///')"
  say "apache   : $(apache2 -v 2>/dev/null | head -1 | sed 's/.*\///; s/ .*//')"
  say "mariadb  : $( (mariadb --version || mysql --version) 2>/dev/null | head -1)"
  say '```'
  say ""
  say "## AlphaCP"
  say '```'
  if [[ -f "${PANEL}/artisan" ]]; then
    say "laravel       : $(art --version)"
    say "panel code    : $(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["version"])' "${PANEL}/MANIFEST.json" 2>/dev/null || echo '?')   (MANIFEST.json = asli deployed code version)"
    say "ACP_VERSION   : $(envkey ACP_VERSION "${PANEL}/.env")   (.env)"
    say "AGENT_VERSION : $(envkey ACP_AGENT_VERSION "${PANEL}/.env")"
    say "APP_ENV       : $(envkey APP_ENV "${PANEL}/.env")   APP_DEBUG: $(envkey APP_DEBUG "${PANEL}/.env")"
  else
    say "panel: ${PANEL} me nahi mila"
  fi
  say "panel http    : $(curl -k -s -o /dev/null -w '%{http_code}' -m 10 https://127.0.0.1:8090/ 2>/dev/null)"
  say '```'
  say ""
  say "## License / trial (sirf state + dates; fingerprint/signature nahi)"
  say '```'
  LIC="$(envkey ACP_LICENSE_STORE_PATH "${PANEL}/.env")"; LIC="${LIC:-${PANEL}/storage/app/private/license.json}"
  if [[ -f "${LIC}" ]]; then
    python3 - "${LIC}" <<'PY' 2>/dev/null || say "license store padh nahi paaya"
import json, sys, datetime
r = json.load(open(sys.argv[1])); p = r.get("payload") or {}
exp = p.get("expires_at", "")
try:
    state = "valid (expiry se pehle)" if datetime.datetime.fromisoformat(exp) > datetime.datetime.now(datetime.timezone.utc) else "EXPIRED"
except Exception:
    state = "?"
print(f"store      : {sys.argv[1]}")
print(f"source     : {r.get('source', '?')}   tier: {p.get('tier', '?')}   max_accounts: {p.get('max_accounts', '?')}")
print(f"issued_at  : {p.get('issued_at', '?')}")
print(f"expires_at : {exp or '?'}   -> {state}")
print(f"signed     : {'yes' if r.get('signature') else 'no (local trial)'}")
PY
  else
    say "license store nahi mila (${LIC}) — panel ka /license page khulte hi trial shuru hota hai"
  fi
  say '```'
  say ""
  say "## Releases (${ACP_HOME}/releases — sirf naam, code snapshot me nahi)"
  say '```'
  ls -1 "${ACP_HOME}/releases" 2>/dev/null || say "(khaali)"
  say '```'
  say ""
  say "## Services"
  say '```'
  mapfile -t UNITS < <( { printf '%s\n' nginx apache2 mariadb mysql redis-server fail2ban paneld; \
      for v in /etc/php/*/fpm; do [[ -d "$v" ]] && echo "php$(cut -d/ -f4 <<<"$v")-fpm"; done; \
      systemctl list-unit-files --no-legend 2>/dev/null | awk '{print $1}' | grep -iE 'alphacp|paneld|license' | sed 's/\.service$//'; } | sort -u )
  for u in "${UNITS[@]}"; do
    st="$(systemctl is-active "${u}" 2>/dev/null)"; [[ "${st}" == "inactive" && -z "$(systemctl list-unit-files "${u}.service" --no-legend 2>/dev/null)" ]] && continue
    printf '%-26s %s\n' "${u}" "${st:-unknown}"
  done
  say '```'
  say ""
  say "## Listening ports"
  say '```'
  # ephemeral ports (32768+) har baar badalte hain — unhe chhod do, warna har sync par fake commit
  ss -ltnpH 2>/dev/null | awk '{p=$4; sub(/.*:/,"",p); u=$6; gsub(/.*\(\("|".*/,"",u); if (p+0 < 32768) print p"\t"u}' | sort -n -u
  say '```'
  say ""
  say "## systemd drop-ins / timers (alphacp)"
  say '```'
  ls -1 /etc/systemd/system/php*-fpm.service.d/ 2>/dev/null
  systemctl list-timers --all --no-legend 2>/dev/null | grep -iE 'alphacp|license' | awk '{print $(NF-1), $NF}'
  say '```'
  if [[ -f "${PANEL}/artisan" && -n "${PHPBIN}" ]]; then
    say ""
    say "## Custom artisan commands (alphacp / license / trial)"
    say '```'
    art list --raw | grep -iE '^(alphacp|acp|license|trial)' | awk '{print $1}'
    say '```'
    say ""
    say "## Migrations"
    say '```'
    art migrate:status --no-ansi | sed -E 's/\.{3,}/ /; s/[[:space:]]+$//' | grep -E 'Ran|Pending|[0-9]{4}_' 
    say '```'
    say ""
    say "## Routes (web)"
    say '```'
    art route:list --json --except-vendor | python3 -c "${PY_ROUTES}"
    say '```'
  fi
  say ""
  say "## License / trial se jude files (naam se)"
  say '```'
  ( cd "${FILES}" && find . -type f | grep -iE 'licen|trial' | sed 's|^\./|/|' | sort )
  say '```'
  say ""
  say "## Secret files — sirf naam aur KEYS (values kabhi nahi)"
  say '```'
  for f in "${ACP_HOME}"/etc/* "${ACP_HOME}"/var/* "${PANEL}/.env"; do
    [[ -f "${f}" ]] || continue
    keys="$(grep -oE '^[A-Za-z_][A-Za-z0-9_]*=' "${f}" 2>/dev/null | tr -d '=' | tr '\n' ' ')"
    printf '%s  %s\n' "${f}" "${keys:+keys: ${keys}}"
  done
  say '```'
  say ""
  say "## Snapshot se skip hui files (secret/binary)"
  say '```'
  cat "${SKIPPED}"
  say '```'
  say ""
  say "## Snapshot completeness (v1.3)"
  say '```'
  if [[ -s "${MISSING}" ]]; then
    say "PANEL SOURCE FILES JO SNAPSHOT ME NAHI AAYI (${MCOUNT}) — repo se panel dobara banane par ye pages tootenge:"
    cat "${MISSING}"
  else
    say "panel ki har source file (vendor/storage/.env chhod kar) snapshot me hai — repo = server ✔"
  fi
  say '```'
} > "${S}" 2>/dev/null

# DB schema (sirf structure, koi data nahi)
DUMP="$(command -v mariadb-dump || command -v mysqldump || true)"
DBN="$(sed -n 's/^ACP_DB_NAME=//p' "${ACP_HOME}/etc/database.env" 2>/dev/null | head -1)"; DBN="${DBN:-alphacp}"
if [[ -n "${DUMP}" ]] && "${DUMP}" --no-data --skip-dump-date --skip-comments "${DBN}" > "${SNAP}/db-schema.sql" 2>/dev/null; then
  sed -i -E 's/ AUTO_INCREMENT=[0-9]+//' "${SNAP}/db-schema.sql"
  ok "DB schema (${DBN}): $(grep -c '^CREATE TABLE' "${SNAP}/db-schema.sql") tables — data nahi"
else
  rm -f "${SNAP}/db-schema.sql"; warn "DB schema dump skip (mariadb-dump/DB nahi mila)"
fi

# file list (vendor/storage chhod kar) — AI ko layout samajhne ke liye
( for d in "${SRC_DIRS[@]}"; do find "${d}" \( -name vendor -o -name node_modules -o -name storage -o -name .git \) -prune -o -type f -print 2>/dev/null; done ) \
  | grep -vE '\.log$|\.disabled-|/bootstrap/cache/' | sort > "${SNAP}/MANIFEST.txt"

# final safety net: poore snapshot (STATE/schema/manifest bhi) me secret value dhoondho
if [[ -s "${SECRETS}" ]] && grep -rqFf "${SECRETS}" "${SNAP}" 2>/dev/null; then
  bad="$(grep -rlFf "${SECRETS}" "${SNAP}" | sed "s|${SNAP}/||")"
  die "SAFETY STOP: in files me server ka secret mila, push NAHI kiya: ${bad}"
fi
ok "final secret check: saaf ✅"

# ============================================================== 7. commit + push
hdr "Step 7: GitHub par push"
DEST="${REPO}/server-snapshot"
rm -rf "${DEST}"; mkdir -p "${DEST}"
cp -a "${SNAP}/." "${DEST}/"; rm -f "${DEST}/.skipped"
cat > "${DEST}/README.md" <<EOF
# server-snapshot/ — live server ki copy (automatic)

\`alphacp-sync\` (installer/alphacp-sync.sh) is folder ko server se banata hai. **Haath se edit mat karo.**

| File | Kya hai |
|---|---|
| \`STATE.md\` | versions, services, ports, migrations, routes, license files, secret files ke sirf naam |
| \`files/\` | server par jo code/config abhi install hai (vendor/storage/secrets ke bina) |
| \`db-schema.sql\` | DB structure (koi data nahi) |
| \`MANIFEST.txt\` | server par files ki list |
| \`LAST-SYNC.md\` | aakhri sync kab hua |
EOF
# LAST-SYNC.md sirf asli badlav par badalta hai — compare se pehle purana wapas rakho
git -C "${REPO}" checkout -q HEAD -- server-snapshot/LAST-SYNC.md 2>/dev/null || true
git -C "${REPO}" add -A server-snapshot
if git -C "${REPO}" diff --cached --quiet; then
  ok "koi badlav nahi — GitHub pehle se up-to-date hai"
  printf '%s  no-change\n' "$(date -Is)" > "${WORK}/last-result"
else
  CHG="$(git -C "${REPO}" diff --cached --name-only | wc -l)"
  printf '# Last sync\n\n- time: %s\n- host: %s\n- files changed: %s\n- tool: alphacp-sync v%s\n' \
    "$(date -u '+%F %T UTC')" "$(hostname)" "${CHG}" "${SYNC_VERSION}" > "${DEST}/LAST-SYNC.md"
  git -C "${REPO}" add -A server-snapshot
  git -C "${REPO}" commit -q -m "chore(sync): server snapshot $(hostname) $(date -u '+%F %H:%M')Z (${CHG} files)" || die "commit fail"
  pushed=0
  for i in 1 2 3; do
    if PO="$(git -C "${REPO}" push -q origin "HEAD:${BRANCH}" 2>&1)"; then pushed=1; break; fi
    if grep -qi "read only\|read-only\|denied" <<<"${PO}"; then
      die "push DENIED — GitHub deploy key me 'Allow write access' tick nahi hai. Settings → Deploy keys → key delete karke tick ke saath dobara add karo."
    fi
    warn "push reject (try ${i}) — latest le kar dobara"; git -C "${REPO}" pull -q --rebase origin "${BRANCH}" || true
  done
  (( pushed )) || die "push fail: ${PO}"
  ok "push ho gaya: ${CHG} files  →  https://github.com/${REPO_SLUG}/tree/${BRANCH}/server-snapshot"
  printf '%s  pushed %s files (%s)\n' "$(date -Is)" "${CHG}" "$(git -C "${REPO}" rev-parse --short HEAD)" > "${WORK}/last-result"
fi

# ============================================================== 8. auto-sync timer (har ghante)
if [[ -z "${SYNC_NO_TIMER:-}" ]] && command -v systemctl >/dev/null 2>&1; then
  hdr "Step 8: auto-sync (har ghante, sirf badlav ho tab push)"
  cat > /etc/systemd/system/alphacp-sync.service <<EOF
[Unit]
Description=AlphaCP server -> GitHub snapshot sync
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=${BIN_DST}
Nice=10
EOF
  cat > /etc/systemd/system/alphacp-sync.timer <<'EOF'
[Unit]
Description=AlphaCP GitHub sync (hourly)

[Timer]
OnBootSec=10min
OnUnitActiveSec=1h
RandomizedDelaySec=5min
Persistent=true

[Install]
WantedBy=timers.target
EOF
  systemctl daemon-reload >/dev/null 2>&1 && systemctl enable --now alphacp-sync.timer >/dev/null 2>&1 \
    && ok "timer ON: alphacp-sync.timer (har ghante)" || warn "timer enable nahi hua (manual: sudo alphacp-sync)"
fi

hdr "RESULT"
say "${C_G}${C_B}==> SYNC OK ✅${C_0}   $(cat "${WORK}/last-result" 2>/dev/null)"
say "    GitHub : https://github.com/${REPO_SLUG}/tree/${BRANCH}/server-snapshot"
say "    Kabhi bhi turant sync:  sudo alphacp-sync"
say "    — alphacp-sync v${SYNC_VERSION}"
say ""
