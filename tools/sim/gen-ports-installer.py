#!/usr/bin/env python3
"""installer/ports-config.sh generate karta hai (Owner Ports Config page)."""
import pathlib

repo = pathlib.Path(__file__).resolve().parents[2]
f = repo / "features/ports"
files = {
    "app/Models/PortConfig.php": (f / "app/Models/PortConfig.php").read_text(),
    "app/Http/Controllers/PortsController.php": (f / "app/Http/Controllers/PortsController.php").read_text(),
    "resources/views/ports/index.blade.php": (f / "resources/views/ports/index.blade.php").read_text(),
    "database/migrations/2026_10_07_000010_create_port_configs_table.php": (f / "database/migrations/2026_10_07_000010_create_port_configs_table.php").read_text(),
}
routes = (f / "routes-ports.php").read_text()

def block(dest, content):
    return (f'cat > "$PANEL/{dest}" <<\'ACP_FILE_EOF\'\n'
            + content.rstrip("\n") + "\n"
            + "ACP_FILE_EOF\n"
            + f'echo "  + {dest}"\n')

out = ["#!/usr/bin/env bash\n",
       "# AlphaCP — Owner Ports Config (panel page) installer  v1.0\n",
       "set -euo pipefail\n",
       "PANEL=/usr/local/alphacp/panel\n",
       'echo "=================================================="\n',
       'echo " AlphaCP Owner Ports Config installer  v1.0"\n',
       'echo "=================================================="\n',
       'echo "== Step 1: panel feature files =="\n',
       'mkdir -p "$PANEL/resources/views/ports"\n']
for dest, content in files.items():
    out.append(block(dest, content))
out.extend(['\n',
            'echo "== Step 2: routes (idempotent) =="\n',
            'if ! grep -q "Owner Ports Config" "$PANEL/routes/web.php"; then\n',
            "cat >> \"$PANEL/routes/web.php\" <<'ACP_ROUTES_EOF'\n",
            routes.rstrip("\n") + "\n",
            "ACP_ROUTES_EOF\n",
            'echo "[OK] routes appended"\n',
            "else\n",
            'echo "[OK] routes already present"\n',
            "fi\n",
            '\n',
            'echo "== Step 3: migrate + cache clear + tile self-flip =="\n',
            "sed -i -E \"s/('name' => 'Service Status',.*'status' => ')live(')/\\1live\\3/\" \"$PANEL/app/Support/ModuleCatalog.php\" || true\n",
            'cd "$PANEL"\n',
            "php artisan migrate --force\n",
            "php artisan route:clear || true\n",
            "php artisan config:clear || true\n",
            'echo "[OK] migrated"\n',
            '\n',
            'echo "=================================================="\n',
            'echo " ==> PORTS CONFIG v1.0 INSTALLED  (owner page: /ports)"\n',
            'echo "=================================================="\n',
            "alphacp-sync || true\n"])

(repo / "installer/ports-config.sh").write_text("".join(out))
print("WROTE installer/ports-config.sh", (repo / "installer/ports-config.sh").stat().st_size, "bytes")
