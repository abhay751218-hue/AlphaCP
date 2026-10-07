#!/usr/bin/env python3
"""installer/secextra.sh generate karta hai (Hotlink + Leech) feature files se."""
import pathlib

repo = pathlib.Path(__file__).resolve().parents[2]
f = repo / "features/secextra"
files = {
    "app/Models/SecurityExtra.php": (f / "app/Models/SecurityExtra.php").read_text(),
    "app/Http/Controllers/SecurityExtrasController.php": (f / "app/Http/Controllers/SecurityExtrasController.php").read_text(),
    "resources/views/secextra/hotlink.blade.php": (f / "resources/views/secextra/hotlink.blade.php").read_text(),
    "resources/views/secextra/leech.blade.php": (f / "resources/views/secextra/leech.blade.php").read_text(),
    "database/migrations/2026_10_07_000005_create_security_extras_table.php": (f / "database/migrations/2026_10_07_000005_create_security_extras_table.php").read_text(),
}
routes = (f / "routes-secextra.php").read_text()

def block(dest, content):
    return (f'cat > "$PANEL/{dest}" <<\'ACP_FILE_EOF\'\n'
            + content.rstrip("\n") + "\n"
            + "ACP_FILE_EOF\n"
            + f'echo "  + {dest}"\n')

out = ["#!/usr/bin/env bash\n",
       "# AlphaCP — Hotlink + Leech Protection portable installer  v1.0\n",
       "set -euo pipefail\n",
       "PANEL=/usr/local/alphacp/panel\n",
       'echo "=================================================="\n',
       'echo " AlphaCP Hotlink + Leech Protection installer  v1.0"\n',
       'echo "=================================================="\n',
       'echo "== Step 1: panel feature files =="\n',
       'mkdir -p "$PANEL/resources/views/secextra"\n']
for dest, content in files.items():
    out.append(block(dest, content))
out.extend(['\n',
            'echo "== Step 2: routes (idempotent) =="\n',
            'if ! grep -q "Security extras: Hotlink + Leech Protection" "$PANEL/routes/web.php"; then\n',
            "cat >> \"$PANEL/routes/web.php\" <<'ACP_ROUTES_EOF'\n",
            routes.rstrip("\n") + "\n",
            "ACP_ROUTES_EOF\n",
            'echo "[OK] routes appended"\n',
            "else\n",
            'echo "[OK] routes already present"\n',
            "fi\n",
            '\n',
            'echo "== Step 3: migrate + cache clear =="\n',
            'cd "$PANEL"\n',
            "php artisan migrate --force\n",
            "php artisan route:clear || true\n",
            "php artisan config:clear || true\n",
            'echo "[OK] migrated"\n',
            '\n',
            'echo "=================================================="\n',
            'echo " ==> HOTLINK + LEECH PROTECTION v1.0 INSTALLED"\n',
            'echo "=================================================="\n',
            "alphacp-sync || true\n"])

(repo / "installer/secextra.sh").write_text("".join(out))
print("WROTE installer/secextra.sh", (repo / "installer/secextra.sh").stat().st_size, "bytes")
