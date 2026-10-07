#!/usr/bin/env python3
"""installer/openbasedir-fix.sh — patched controllers deploy karta hai."""
import pathlib

repo = pathlib.Path(__file__).resolve().parents[2]
eg = (repo / "features/entrygate/app/Http/Controllers/Auth/EntryLoginController.php").read_text()
pc = (repo / "features/ports/app/Http/Controllers/PortsController.php").read_text()

def heredoc(path, content):
    return (f'cat > "{path}" <<\'ACP_PHP_EOF\'\n' + content.rstrip("\n") + "\nACP_PHP_EOF\n"
            f'echo "  + {path}"\n')

out = [
 '#!/usr/bin/env bash\n',
 '# AlphaCP — open_basedir 500 PERMANENT fix  v1.0\n',
 '# Root cause: EntryLoginController var/ports.json is_file() karta tha jo\n',
 '# open_basedir se bahar hai → ErrorException → har request 500.\n',
 '# Fix: controllers ab etc/ports.json (allowed) padhte hain, try/catch ke saath;\n',
 '# PortsController etc/ me likhta hai. var/ copy CLI/agent ke liye bani rahti hai.\n',
 'set -uo pipefail\n',
 'PANEL="${ACP_PANEL:-/usr/local/alphacp/panel}"\n',
 'ETC="${ACP_ETC:-/usr/local/alphacp/etc}"\n',
 'echo "=================================================="\n',
 'echo " AlphaCP open_basedir 500 Fix  v1.0"\n',
 'echo "=================================================="\n',
 'mkdir -p "$PANEL/app/Http/Controllers/Auth" "$PANEL/app/Http/Controllers" "$ETC"\n',
 heredoc('$PANEL/app/Http/Controllers/Auth/EntryLoginController.php', eg),
 heredoc('$PANEL/app/Http/Controllers/PortsController.php', pc),
 '# ---- ports.json: etc/ copy (web-readable) ensure ----\n',
 'if [ ! -f "$ETC/ports.json" ] && [ -f /usr/local/alphacp/var/ports.json ]; then\n',
 '  cp /usr/local/alphacp/var/ports.json "$ETC/ports.json" && echo "  + var/ports.json -> etc/ports.json copied"\n',
 'fi\n',
 'chown root:alphacp "$ETC/ports.json" 2>/dev/null && chmod 0640 "$ETC/ports.json" 2>/dev/null || true\n',
 '# ---- fpm restart (opcache flush) ----\n',
 'systemctl restart php8.4-fpm 2>/dev/null || systemctl restart php-fpm 2>/dev/null || service php8.4-fpm restart 2>/dev/null || true\n',
 '# ---- verify ----\n',
 'echo "-- http status:"\n',
 'curl -sk -o /dev/null -w \'   /       => %{http_code}\\n\' "https://127.0.0.1:8090/" 2>/dev/null || true\n',
 'curl -sk -o /dev/null -w \'   /login  => %{http_code}\\n\' "https://127.0.0.1:8090/login" 2>/dev/null || true\n',
 'echo "=================================================="\n',
 'echo " ==> OPEN_BASEDIR FIX v1.0 APPLIED"\n',
 'echo "=================================================="\n',
 'alphacp-sync || true\n',
]
(repo / "installer/openbasedir-fix.sh").write_text("".join(out))
print("WROTE installer/openbasedir-fix.sh", (repo / "installer/openbasedir-fix.sh").stat().st_size)
