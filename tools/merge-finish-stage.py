#!/usr/bin/env python3
"""Merge the chunk-fetch stage into the Step 2B finishing script -> step2b-setup.sh"""
import pathlib
import re

root = pathlib.Path(__file__).resolve().parent.parent
src = (root / "installer" / "step2b-finish.sh").read_text()

stage = r'''
# -----------------------------------------------------------------------------
#  STAGE A — panel code laao (paste.rs chunks) + composer install
# -----------------------------------------------------------------------------
stage_code() {
  step "Stage A — panel code (Laravel 13) + composer install"

  # root check SABSE pehle — warna bina sudo chalane par aadha panel delete ho jata hai
  if [[ "${EUID}" -ne 0 ]]; then
    err "root chahiye: sudo bash $0"
    exit 1
  fi

  local chunks=("kiprz" "tzbUI" "RZSl9")
  local expected="61c46d6dd8e1ec1c74bc65cc6e49f575c5d21f999f6ca5c53b701b655c2f5a6c"
  cd / 2>/dev/null || true          # panel dir delete karne se pehle cwd safe karo
  local work="/tmp/acp-setup.$$"
  mkdir -p "$work"
  trap 'rm -rf "${work:-}"' EXIT

  info "panel code download (3 chunks)…"
  local f
  for f in "${chunks[@]}"; do
    curl -fsSL --retry 3 --retry-delay 2 --connect-timeout 20 --max-time 120 \
      "https://paste.rs/${f}" >> "${work}/panel.b64" \
      || die "chunk ${f} download fail — net check karke dobara chalao"
  done
  base64 -d "${work}/panel.b64" > "${work}/panel-code.tar.gz" \
    || die "base64 decode fail (chunk adhoora aaya — dobara chalao)"

  local got; got="$(sha256sum "${work}/panel-code.tar.gz" | awk '{print $1}')"
  if [[ "$got" != "$expected" ]]; then
    die "checksum mismatch! got=${got} expected=${expected}"
  fi
  ok "panel code verified (sha256 ${expected:0:16}…)"

  tar tzf "${work}/panel-code.tar.gz" >/dev/null 2>&1 || die "tarball corrupt — dobara chalao"

  # purana/adhoora panel hata kar saaf jagah banao (fail ho to chup-chaap aage mat badho)
  rm -rf "${PANEL_ROOT}" || {
    # kuch files alphacp ke naam par hain — us user ke through dobara try karo
    runuser -u "$(id -un)" -- rm -rf "${PANEL_ROOT}" 2>/dev/null || true
    rm -rf "${PANEL_ROOT}" 2>/dev/null || true
  }
  if [[ -e "${PANEL_ROOT}" ]]; then
    chown -R root:root "${PANEL_ROOT}" 2>/dev/null || true
    rm -rf "${PANEL_ROOT}" || die "purana panel delete nahi ho paya: ${PANEL_ROOT}"
  fi
  mkdir -p "${PANEL_ROOT}"
  tar xzf "${work}/panel-code.tar.gz" -C "${PANEL_ROOT}" --strip-components=1

  [[ -f "${PANEL_ROOT}/artisan" ]] || die "artisan nahi mila — extract galat hua"
  grep -q 'laravel/framework' "${PANEL_ROOT}/composer.json" || die "composer.json nahi mila"
  if ! grep -q '"laravel/framework": "\^13' "${PANEL_ROOT}/composer.json"; then
    die "galat composer.json (Laravel 13 expected) — extract check karo"
  fi
  ok "panel code extract ho gaya (composer.json = Laravel 13.x)"

  mkdir -p "${PANEL_ROOT}/bootstrap/cache" \
           "${PANEL_ROOT}/storage/framework/views" \
           "${PANEL_ROOT}/storage/framework/sessions" \
           "${PANEL_ROOT}/storage/framework/cache/data" \
           "${PANEL_ROOT}/storage/logs"

  info "composer install (vendor download ~1 min)…"
  if ! ( cd "${PANEL_ROOT}" && COMPOSER_ALLOW_SUPERUSER=1 composer install \
           --no-dev --no-interaction --no-progress ) >>"${LOG_FILE}" 2>&1; then
    err "composer install fail — aakhri lines:"
    tail -15 "${LOG_FILE}" | tee -a "${LOG_FILE}"
    die "composer install fail"
  fi
  [[ -f "${PANEL_ROOT}/vendor/autoload.php" ]] || die "vendor nahi bana"
  local ver; ver="$( cd "${PANEL_ROOT}" && php artisan --version 2>/dev/null || true )"
  ok "composer install done — ${ver:-vendor ready}"
}

# -----------------------------------------------------------------------------
'''

m = re.search(r"main\(\) \{\n", src)
assert m, "main() not found"
if "stage_code\n" not in src.split("main() {", 1)[1][:200]:
    src = src[:m.end()] + "  stage_code\n\n" + src[m.end():]
else:
    print("stage_code already present — skipping")

anchor = "# -----------------------------------------------------------------------------\nmain() {"
assert anchor in src, "main() anchor not found"
src = src.replace(anchor, stage + "\n" + anchor, 1)

src = src.replace(
    "#  AlphaCP — Step 2B finishing script (panel already copied + composer installed)\n"
    "#  Version 0.3.7  ·  port 8090  ·  Ubuntu 22.04/24.04 (x86_64)",
    "#  AlphaCP — Step 2B FULL setup (code + composer + database + admin + nginx :8090)\n"
    "#  Version 0.3.7  ·  port 8090  ·  Ubuntu 22.04/24.04 (x86_64)", 1)

src = src.replace(
    "#  Jab tak panel ka code `/usr/local/alphacp/panel` me aa gaya ho aur\n"
    "#  `composer install` chal chuka ho, yeh script baaki ka kaam karti hai:",
    "#  Yeh script SAB kuch karti hai (kuch pehle se karne ki zarurat nahi):\n"
    "#     0. panel code khud download karti hai (checksum-verified) aur composer install\n"
    "#     phir:", 1)

out = root / "installer" / "step2b-setup.sh"
out.write_text(src)
print(f"merged -> {out} ({len(src)} bytes)")
