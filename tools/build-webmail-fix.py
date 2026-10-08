#!/usr/bin/env python3
"""installer/webmail-fix.sh.in → installer/webmail-fix.sh (payloads embed)."""
import hashlib
import pathlib
import sys

REPO = pathlib.Path(__file__).resolve().parent.parent
TEMPLATE = REPO / "installer" / "webmail-fix.sh.in"
OUTPUT = REPO / "installer" / "webmail-fix.sh"
A = REPO / "agent"
P = REPO / "server-snapshot" / "files" / "usr" / "local" / "alphacp" / "panel"
W = REPO / "webmail" / "roundcube"

PAYLOADS = {
    "@@AGENT_MAILSERVER_PHP@@": A / "src" / "MailServer.php",
    "@@TESTS_RUN_PHP@@": A / "tests" / "run-tests.php",
    "@@PANEL_WEBMAIL_CTRL@@": P / "app" / "Http" / "Controllers" / "WebmailController.php",
    "@@PANEL_SSO_CTRL@@": P / "app" / "Http" / "Controllers" / "WebmailSsoController.php",
    "@@PANEL_ROUTES@@": P / "routes" / "web.php",
    "@@PANEL_CONFIG@@": P / "config" / "acp.php",
    "@@PANEL_WEBMAIL_VIEW@@": P / "resources" / "views" / "webmail" / "index.blade.php",
    "@@RC_PLUGIN@@": W / "acp_sso.php",
    "@@RC_PLUGIN_CONF@@": W / "config.inc.php",
}


def main() -> int:
    src = TEMPLATE.read_text()
    for token, path in PAYLOADS.items():
        if not path.is_file():
            raise SystemExit(f"[x] payload file nahi mili: {path.relative_to(REPO)}")
        body = path.read_text().rstrip("\n")
        if token in src:
            src = src.replace(token, body)
    missing = [t for t in PAYLOADS if t in src]
    if missing:
        raise SystemExit(f"[x] template me token nahi mila: {missing}")
    OUTPUT.write_text(src)
    digest = hashlib.sha256(OUTPUT.read_bytes()).hexdigest()
    print(f"[OK] built {OUTPUT.relative_to(REPO)}  ({len(OUTPUT.read_bytes())} bytes)")
    print(f"     sha256: {digest}")
    print(f"     embedded OK: {len(PAYLOADS)} payload files (byte-for-byte)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
