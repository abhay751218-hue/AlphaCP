#!/usr/bin/env python3
"""Doctor v1.4 — FPM detection fix: find which PHP actually serves the panel.

Bug found on the real server: several PHP versions are installed (7.4 … 8.4).
`ls -d /etc/php/*/fpm | head -1` picked 7.4, so the doctor restarted the WRONG
fpm — and worse, a stale php7.4 alphacp pool was holding the panel socket, so
the Laravel 13 app (needs PHP 8.3+) died with 500 on every request.
"""
import pathlib

p = pathlib.Path("/home/user/installer/panel-doctor.sh")
s = p.read_text()

old = '''hdr "Step 2: php-fpm restart (${FPM})"
systemctl restart "${FPM}" 2>/dev/null || service "${FPM}" restart 2>/dev/null || true
sleep 2
if [[ -S /run/php/alphacp-fpm.sock ]]; then ok "socket ready: /run/php/alphacp-fpm.sock"; else warn "socket nahi mila"; fi'''

new = '''hdr "Step 2: php-fpm check (kaun sa PHP sach me panel serve kar raha hai)"

ver_ge() { [[ "$(printf '%s\\n%s\\n' "$2" "$1" | sort -V | head -1)" == "$2" ]]; }

# kis-kis PHP version me alphacp pool hai?
mapfile -t POOLFILES < <(grep -rl "alphacp-fpm.sock" /etc/php/*/fpm/pool.d/ 2>/dev/null || true)
if (( ${#POOLFILES[@]} )); then
  for f in "${POOLFILES[@]}"; do
    say "   pool mila: php$(printf '%s' "$f" | cut -d/ -f4)  ->  ${f}"
  done
else
  warn "alphacp pool kisi PHP me nahi mila"
fi

# stale pools (PHP < 8.3) hatao — ye panel ka socket grab kar lete hain
for f in "${POOLFILES[@]:-}"; do
  [[ -n "${f}" ]] || continue
  v="$(printf '%s' "$f" | cut -d/ -f4)"
  if ! ver_ge "${v}" "8.3"; then
    mv "${f}" "${f}.disabled-$(date +%s)" 2>/dev/null \
      && warn "stale pool hataya: php${v} (panel ko PHP 8.3+ chahiye)"
    systemctl stop "php${v}-fpm" >/dev/null 2>&1 || true
    systemctl disable "php${v}-fpm" >/dev/null 2>&1 || true
  fi
done

# 8.3+ wale har PHP ka fpm restart karo (jo pool rakhta hai wahi socket banata hai)
for v in $(printf '%s\\n' "${POOLFILES[@]:-}" | cut -d/ -f4 | sort -u -V); do
  [[ -n "${v}" ]] || continue
  ver_ge "${v}" "8.3" || continue
  rm -f /run/php/alphacp-fpm.sock 2>/dev/null || true
  systemctl restart "php${v}-fpm" 2>/dev/null || service "php${v}-fpm" restart 2>/dev/null || true
  sleep 2
  [[ -S /run/php/alphacp-fpm.sock ]] && ok "php${v}-fpm restart — socket ready"
done

# socket sach me kis PHP ne banaya?
SOCKPID="$(ss -xlp 2>/dev/null | grep -F 'alphacp-fpm.sock' | grep -oE 'pid=[0-9]+' | head -1 | cut -d= -f2)"
[[ -n "${SOCKPID}" ]] && say "   socket owner : $(ps -o cmd= -p "${SOCKPID}" 2>/dev/null | head -1)"

[[ -S /run/php/alphacp-fpm.sock ]] && ok "socket ready: /run/php/alphacp-fpm.sock" || warn "socket nahi mila"'''

assert old in s, "fpm block not found"
s = s.replace(old, new, 1)

old2 = '''PHPV="$(phpv)"; PHPV="${PHPV:-8.4}"
FPM="php${PHPV}-fpm"'''
new2 = '''# sabse NAYA installed PHP (7.4 jaisa purana nahi) — panel ko 8.3+ chahiye
PHPV="$(ls -d /etc/php/*/fpm 2>/dev/null | cut -d/ -f4 | sort -V | tail -1)"
PHPV="${PHPV:-8.4}"
FPM="php${PHPV}-fpm"'''
assert old2 in s, "PHPV block not found"
s = s.replace(old2, new2, 1)

p.write_text(s)
print("doctor v1.4 patched ✅")
