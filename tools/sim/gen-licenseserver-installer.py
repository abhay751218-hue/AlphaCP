#!/usr/bin/env python3
"""installer/license-server.sh generate karta hai feature files se."""
import pathlib

repo = pathlib.Path(__file__).resolve().parents[2]
f = repo / "features/license-server"
files = {
    "app/Support/LicenseSigner.php": (f / "app/Support/LicenseSigner.php").read_text(),
    "app/Models/LicenseKey.php": (f / "app/Models/LicenseKey.php").read_text(),
    "app/Http/Controllers/LicenseServerController.php": (f / "app/Http/Controllers/LicenseServerController.php").read_text(),
    "resources/views/license-server/index.blade.php": (f / "resources/views/license-server/index.blade.php").read_text(),
    "database/migrations/2026_10_07_000004_create_license_keys_table.php": (f / "database/migrations/2026_10_07_000004_create_license_keys_table.php").read_text(),
}
routes = (f / "routes-license-server.php").read_text()

def block(dest, content):
    return (f'cat > "$PANEL/{dest}" <<\'ACP_FILE_EOF\'\n'
            + content.rstrip("\n") + "\n"
            + "ACP_FILE_EOF\n"
            + f'echo "  + {dest}"\n')

out = ["#!/usr/bin/env bash\n",
       "# AlphaCP — License Server (sellable signed licenses) portable installer  v1.0\n",
       "set -euo pipefail\n",
       "PANEL=/usr/local/alphacp/panel\n",
       'echo "=================================================="\n',
       'echo " AlphaCP License Server installer  v1.0"\n',
       'echo "=================================================="\n',
       'echo "== Step 1: panel feature files =="\n',
       'mkdir -p "$PANEL/resources/views/license-server"\n']
for dest, content in files.items():
    out.append(block(dest, content))
out.extend(['\n',
            'echo "== Step 2: routes (idempotent) =="\n',
            'if ! grep -q "License Server (sellable signed licenses)" "$PANEL/routes/web.php"; then\n',
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
            'echo "NOTE: ACP_LICENSE_SECRET .env me set karo (license signing ke liye)."\n',
            'echo "=================================================="\n',
            'echo " ==> LICENSE SERVER v1.0 INSTALLED  (panel: /license-server)"\n',
            'echo "=================================================="\n',
            "alphacp-sync || true\n"])

(repo / "installer/license-server.sh").write_text("".join(out))
print("WROTE installer/license-server.sh", (repo / "installer/license-server.sh").stat().st_size, "bytes")
