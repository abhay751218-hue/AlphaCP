#!/usr/bin/env python3
"""installer/webdisk.sh generate karta hai feature files se."""
import pathlib

repo = pathlib.Path(__file__).resolve().parents[2]
f = repo / "features/webdisk"
files = {
    "app/Models/WebDiskAccount.php": (f / "app/Models/WebDiskAccount.php").read_text(),
    "app/Http/Controllers/WebDiskController.php": (f / "app/Http/Controllers/WebDiskController.php").read_text(),
    "resources/views/webdisk/index.blade.php": (f / "resources/views/webdisk/index.blade.php").read_text(),
    "database/migrations/2026_10_07_000006_create_webdisk_accounts_table.php": (f / "database/migrations/2026_10_07_000006_create_webdisk_accounts_table.php").read_text(),
}
routes = (f / "routes-webdisk.php").read_text()

def block(dest, content):
    return (f'cat > "$PANEL/{dest}" <<\'ACP_FILE_EOF\'\n'
            + content.rstrip("\n") + "\n"
            + "ACP_FILE_EOF\n"
            + f'echo "  + {dest}"\n')

out = ["#!/usr/bin/env bash\n",
       "# AlphaCP — Web Disk (WebDAV accounts) portable installer  v1.0\n",
       "set -euo pipefail\n",
       "PANEL=/usr/local/alphacp/panel\n",
       'echo "=================================================="\n',
       'echo " AlphaCP Web Disk installer  v1.0"\n',
       'echo "=================================================="\n',
       'echo "== Step 1: panel feature files =="\n',
       'mkdir -p "$PANEL/resources/views/webdisk"\n']
for dest, content in files.items():
    out.append(block(dest, content))
out.extend(['\n',
            'echo "== Step 2: routes (idempotent) =="\n',
            'if ! grep -q "Web Disk (WebDAV accounts)" "$PANEL/routes/web.php"; then\n',
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
            'echo " ==> WEB DISK v1.0 INSTALLED  (panel: /webdisk)"\n',
            'echo "=================================================="\n',
            "alphacp-sync || true\n"])

(repo / "installer/webdisk.sh").write_text("".join(out))
print("WROTE installer/webdisk.sh", (repo / "installer/webdisk.sh").stat().st_size, "bytes")
