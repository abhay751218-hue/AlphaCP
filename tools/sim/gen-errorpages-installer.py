#!/usr/bin/env python3
"""installer/error-pages.sh generate karta hai (standalone error views)."""
import pathlib

repo = pathlib.Path(__file__).resolve().parents[2]
d = repo / "features/errfix/resources/views/errors"

def block(code, content):
    return (f'cat > "$PANEL/resources/views/errors/{code}.blade.php" <<\'ACP_FILE_EOF\'\n'
            + content.rstrip("\n") + "\n"
            + "ACP_FILE_EOF\n"
            + f'echo "  + errors/{code}.blade.php"\n')

out = ["#!/usr/bin/env bash\n",
       "# AlphaCP — Standalone error pages installer  v2.1\n",
       "# Error pages ab NA layout extend karti hain NA DB/auth use karti hain —\n",
       "# galat URL / 405 / 500 kabhi layout-crash se 500 nahi denge.\n",
       "set -euo pipefail\n",
       "PANEL=\"${ACP_PANEL:-/usr/local/alphacp/panel}\"\n",
       'echo "=================================================="\n',
       'echo " AlphaCP Standalone Error Pages installer  v2.1"\n',
       'echo "=================================================="\n',
       'mkdir -p "$PANEL/resources/views/errors"\n']
for f in sorted(d.glob("*.blade.php")):
    out.append(block(f.name[:-len('.blade.php')], f.read_text()))
out.extend(['\n',
            'cd "$PANEL"\n',
            '',
            '# ---- env hardening: stale compiled views / opcache / ownership ----\n',
            'PHP_BIN="$(command -v php8.4 || command -v php || echo /usr/bin/php8.4)"\n',
            'FPM_USER=$(ps axo user=,comm= 2>/dev/null | awk \'$2 ~ /php-fpm/ {print $1}\' | sort | uniq -c | sort -rn | awk \'NR==1 {print $2}\')\n',
            'if [ -z "$FPM_USER" ] || [ "$FPM_USER" = "root" ]; then\n',
            '  FPM_USER="$(awk -F\'=\' \'/^[[:space:]]*user[[:space:]]*=/{gsub(/ /,"",$2); print $2; exit}\' /etc/php/*/fpm/pool.d/*.conf 2>/dev/null | head -1 || true)"\n',
            'fi\n',
            'FPM_USER=${FPM_USER:-www-data}\n',
            'echo "  fpm worker user: $FPM_USER"\n',
            'chown -R "$FPM_USER":"$FPM_USER" storage bootstrap/cache 2>/dev/null || \\\n',
            '  chown -R "$FPM_USER" storage bootstrap/cache 2>/dev/null || true\n',
            'chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true\n',
            "\"$PHP_BIN\" artisan view:clear   2>&1 | tail -1 || true\n",
            "\"$PHP_BIN\" artisan config:clear 2>&1 | tail -1 || true\n",
            "\"$PHP_BIN\" artisan route:clear  2>&1 | tail -1 || true\n",
            "\"$PHP_BIN\" artisan cache:clear  2>&1 | tail -1 || true\n",
            'systemctl restart php8.4-fpm 2>/dev/null || systemctl restart php-fpm 2>/dev/null || \\\n',
            '  service php8.4-fpm restart 2>/dev/null || echo "  (fpm restart manually: sudo systemctl restart php8.4-fpm)"\n',
            'echo "=================================================="\n',
            'echo " ==> STANDALONE ERROR PAGES v2.1 APPLIED (env hardened)"\n',
            'echo "=================================================="\n',
            "alphacp-sync || true\n"])

(repo / "installer/error-pages.sh").write_text("".join(out))
print("WROTE installer/error-pages.sh", (repo / "installer/error-pages.sh").stat().st_size, "bytes")
