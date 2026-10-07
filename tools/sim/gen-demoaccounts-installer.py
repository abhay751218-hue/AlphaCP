#!/usr/bin/env python3
"""installer/demo-accounts.sh generate karta hai (demo/test panel accounts)."""
import pathlib

repo = pathlib.Path(__file__).resolve().parents[2]
f = repo / "features/demoaccounts"
cmd = (f / "app/Console/Commands/DemoAccountsCommand.php").read_text()

out = [
    "#!/usr/bin/env bash\n",
    "# AlphaCP — Demo/test panel accounts installer  v1.0\n",
    "# Usage:  sudo bash demo-accounts-v1.0.sh ['Password123'] ['customer1.test']\n",
    "set -euo pipefail\n",
    "PANEL=/usr/local/alphacp/panel\n",
    'PASS="${1:-}"\n',
    'DOMAIN="${2:-customer1.test}"\n',
    'echo "=================================================="\n',
    'echo " AlphaCP Demo Accounts installer  v1.0"\n',
    'echo "=================================================="\n',
    'echo "== Step 1: command file =="\n',
    'mkdir -p "$PANEL/app/Console/Commands"\n',
    'cat > "$PANEL/app/Console/Commands/DemoAccountsCommand.php" <<\'ACP_FILE_EOF\'\n',
    cmd.rstrip("\n") + "\n",
    "ACP_FILE_EOF\n",
    'echo "  + app/Console/Commands/DemoAccountsCommand.php"\n',
    "\n",
    'echo "== Step 2: accounts banao (idempotent) =="\n',
    'cd "$PANEL"\n',
    'php artisan config:clear || true\n',
    'if [ -n "$PASS" ]; then\n',
    '  php artisan alphacp:demo-accounts --password="$PASS" --domain="$DOMAIN"\n',
    "else\n",
    '  php artisan alphacp:demo-accounts --domain="$DOMAIN"\n',
    "fi\n",
    "\n",
    'echo "== Step 3: login info =="\n',
    'PORT=8090\n',
    'ALLPORTS=8090\n',
    'if [ -f /usr/local/alphacp/var/ports.json ]; then\n',
    '  P2=$(python3 -c "import json;'
    "d=json.load(open('/usr/local/alphacp/var/ports.json'));s=d.get('ssl') or [8090];"
    'print(s[0]);" 2>/dev/null || true)\n',
    '  PA=$(python3 -c "import json;'
    "d=json.load(open('/usr/local/alphacp/var/ports.json'));s=d.get('ssl') or [8090];"
    'print(\' \'.join(str(x) for x in s));" 2>/dev/null || true)\n',
    '  [ -n "${P2:-}" ] && PORT="$P2"\n',
    '  [ -n "${PA:-}" ] && ALLPORTS="$PA"\n',
    "fi\n",
    'IP=$(hostname -I 2>/dev/null | awk \'{print $1}\' || true)\n',
    'echo "  Login URL : https://${IP:-<server-ip>}:${PORT}/   (login page / par hai; open ports: ${ALLPORTS})"\n',
    'echo "  Personas  : demoresel (reseller) · democust (hosting customer) · demomail (email-only)"\n',
    'echo "  Root admin: pehle se hai (installer wala admin user)"\n',
    'echo "=================================================="\n',
    'echo " ==> DEMO ACCOUNTS v1.0 READY"\n',
    'echo "=================================================="\n',
    "alphacp-sync || true\n",
]

(repo / "installer/demo-accounts.sh").write_text("".join(out))
print("WROTE installer/demo-accounts.sh", (repo / "installer/demo-accounts.sh").stat().st_size, "bytes")
