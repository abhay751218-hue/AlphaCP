#!/usr/bin/env python3
"""installer/g5.sh generate karta hai (Git + Terminal) feature files se."""
import pathlib

repo = pathlib.Path(__file__).resolve().parents[2]
f = repo / "features/g5"
files = {
    "app/Http/Controllers/GitController.php": (f / "app/Http/Controllers/GitController.php").read_text(),
    "app/Http/Controllers/TerminalController.php": (f / "app/Http/Controllers/TerminalController.php").read_text(),
    "resources/views/git/index.blade.php": (f / "resources/views/git/index.blade.php").read_text(),
    "resources/views/git/status.blade.php": (f / "resources/views/git/status.blade.php").read_text(),
    "resources/views/terminal/index.blade.php": (f / "resources/views/terminal/index.blade.php").read_text(),
}
routes = (f / "routes-g5.php").read_text()

def block(dest: str, content: str) -> str:
    return (f'cat > "$PANEL/{dest}" <<\'ACP_FILE_EOF\'\n'
            + content.rstrip("\n") + "\n"
            + "ACP_FILE_EOF\n"
            + f'echo "  + {dest}"\n')

out = ["#!/usr/bin/env bash\n",
       "# AlphaCP — G5 Git Version Control + Terminal portable installer  v1.0\n",
       "set -euo pipefail\n",
       "PANEL=/usr/local/alphacp/panel\n",
       'echo "=================================================="\n',
       'echo " AlphaCP G5 (Git + Terminal) installer  v1.0"\n',
       'echo "=================================================="\n',
       'echo "== Step 1: panel feature files =="\n',
       'mkdir -p "$PANEL/resources/views/git" "$PANEL/resources/views/terminal"\n']
for dest, content in files.items():
    out.append(block(dest, content))
out.extend(['\n',
            'echo "== Step 2: routes (idempotent) =="\n',
            'if ! grep -q "G5: Git Version Control + Terminal" "$PANEL/routes/web.php"; then\n',
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
            'echo " ==> G5 (GIT + TERMINAL) v1.0 INSTALLED  (panel: /git, /terminal)"\n',
            'echo "=================================================="\n',
            "alphacp-sync || true\n"])

(repo / "installer/g5.sh").write_text("".join(out))
print("WROTE installer/g5.sh", (repo / "installer/g5.sh").stat().st_size, "bytes")
