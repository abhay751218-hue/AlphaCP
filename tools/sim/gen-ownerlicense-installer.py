#!/usr/bin/env python3
"""installer/owner-license.sh generate karta hai (LicenseClient + owner command)."""
import pathlib

repo = pathlib.Path(__file__).resolve().parents[2]
f = repo / "features/license"
files = {
    "app/Support/License/LicenseClient.php": (f / "app/Support/License/LicenseClient.php").read_text(),
    "app/Console/Commands/OwnerLicenseCommand.php": (f / "app/Console/Commands/OwnerLicenseCommand.php").read_text(),
    "app/Console/Commands/LicenseRenewCommand.php": (f / "app/Console/Commands/LicenseRenewCommand.php").read_text(),
}

def block(dest, content):
    return (f'cat > "$PANEL/{dest}" <<\'ACP_FILE_EOF\'\n'
            + content.rstrip("\n") + "\n"
            + "ACP_FILE_EOF\n"
            + f'echo "  + {dest}"\n')

out = ["#!/usr/bin/env bash\n",
       "# AlphaCP — Owner Lifetime + Unlimited License installer  v1.0\n",
       "set -euo pipefail\n",
       "PANEL=/usr/local/alphacp/panel\n",
       'echo "=================================================="\n',
       'echo " AlphaCP Owner Lifetime License installer  v1.0"\n',
       'echo "=================================================="\n',
       'echo "== Step 1: license client + commands (backup ke saath) =="\n',
       'mkdir -p "$PANEL/app/Support/License" "$PANEL/app/Console/Commands"\n',
       'if [[ -f "$PANEL/app/Support/License/LicenseClient.php" && ! -f "$PANEL/app/Support/License/LicenseClient.php.bak" ]]; then\n',
       '  cp "$PANEL/app/Support/License/LicenseClient.php" "$PANEL/app/Support/License/LicenseClient.php.bak"\n',
       '  echo "  + LicenseClient.php.bak"\n',
       "fi\n"]
for dest, content in files.items():
    out.append(block(dest, content))
out.extend(['\n',
            'echo "== Step 2: caches + owner license apply =="\n',
            'cd "$PANEL"\n',
            "php artisan config:clear || true\n",
            "php artisan alphacp:license:owner\n",
            'echo "=================================================="\n',
            'echo " ==> OWNER LICENSE v1.0 APPLIED  (LIFETIME + UNLIMITED)"\n',
            'echo "=================================================="\n',
            "alphacp-sync || true\n"])

(repo / "installer/owner-license.sh").write_text("".join(out))
print("WROTE installer/owner-license.sh", (repo / "installer/owner-license.sh").stat().st_size, "bytes")
