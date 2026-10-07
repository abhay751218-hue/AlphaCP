#!/usr/bin/env python3
"""installer/filextras.sh generate karta hai (Images+Optimize+Trash) feature files se."""
import pathlib

repo = pathlib.Path(__file__).resolve().parents[2]
f = repo / "features/filextras"
files = {
    "app/Models/OptimizeSetting.php": (f / "app/Models/OptimizeSetting.php").read_text(),
    "app/Http/Controllers/OptimizeController.php": (f / "app/Http/Controllers/OptimizeController.php").read_text(),
    "app/Http/Controllers/ImagesController.php": (f / "app/Http/Controllers/ImagesController.php").read_text(),
    "app/Http/Controllers/TrashController.php": (f / "app/Http/Controllers/TrashController.php").read_text(),
    "resources/views/optimize/index.blade.php": (f / "resources/views/optimize/index.blade.php").read_text(),
    "resources/views/images/index.blade.php": (f / "resources/views/images/index.blade.php").read_text(),
    "resources/views/trash/index.blade.php": (f / "resources/views/trash/index.blade.php").read_text(),
    "database/migrations/2026_10_07_000008_create_optimize_settings_table.php": (f / "database/migrations/2026_10_07_000008_create_optimize_settings_table.php").read_text(),
}
routes = (f / "routes-filextras.php").read_text()

def block(dest, content):
    return (f'cat > "$PANEL/{dest}" <<\'ACP_FILE_EOF\'\n'
            + content.rstrip("\n") + "\n"
            + "ACP_FILE_EOF\n"
            + f'echo "  + {dest}"\n')

out = ["#!/usr/bin/env bash\n",
       "# AlphaCP — File extras (Images + Optimize Website + Trash) installer  v1.0\n",
       "set -euo pipefail\n",
       "PANEL=/usr/local/alphacp/panel\n",
       'echo "=================================================="\n',
       'echo " AlphaCP File extras installer  v1.0"\n',
       'echo "=================================================="\n',
       'echo "== Step 1: panel feature files =="\n',
       'mkdir -p "$PANEL/resources/views/optimize" "$PANEL/resources/views/images" "$PANEL/resources/views/trash"\n']
for dest, content in files.items():
    out.append(block(dest, content))
out.extend(['\n',
            'echo "== Step 2: routes (idempotent) =="\n',
            'if ! grep -q "File extras: Images + Optimize Website + Trash" "$PANEL/routes/web.php"; then\n',
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
            'echo " ==> FILE EXTRAS v1.0 INSTALLED  (/images /optimize-website /trash)"\n',
            'echo "=================================================="\n',
            "alphacp-sync || true\n"])

(repo / "installer/filextras.sh").write_text("".join(out))
print("WROTE installer/filextras.sh", (repo / "installer/filextras.sh").stat().st_size, "bytes")
