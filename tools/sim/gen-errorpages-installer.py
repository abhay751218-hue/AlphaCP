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
       "# AlphaCP — Standalone error pages installer  v2.0\n",
       "# Error pages ab NA layout extend karti hain NA DB/auth use karti hain —\n",
       "# galat URL / 405 / 500 kabhi layout-crash se 500 nahi denge.\n",
       "set -euo pipefail\n",
       "PANEL=\"${ACP_PANEL:-/usr/local/alphacp/panel}\"\n",
       'echo "=================================================="\n',
       'echo " AlphaCP Standalone Error Pages installer  v2.0"\n',
       'echo "=================================================="\n',
       'mkdir -p "$PANEL/resources/views/errors"\n']
for f in sorted(d.glob("*.blade.php")):
    out.append(block(f.name[:-len('.blade.php')], f.read_text()))
out.extend(['\n',
            'cd "$PANEL"\n',
            "php artisan view:clear || true\n",
            'echo "=================================================="\n',
            'echo " ==> STANDALONE ERROR PAGES v2.0 APPLIED"\n',
            'echo "=================================================="\n',
            "alphacp-sync || true\n"])

(repo / "installer/error-pages.sh").write_text("".join(out))
print("WROTE installer/error-pages.sh", (repo / "installer/error-pages.sh").stat().st_size, "bytes")
