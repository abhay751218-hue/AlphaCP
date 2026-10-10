#!/usr/bin/env python3
"""D24 — webmail_sso_tokens table (webmail SSO 500 fix on fresh installs).

Deploy: installer/alphacp-update.sh ya alphacp-server-update.sh (migrate --force shamil).
"""

FILES = [
    ("d24-migration-webmail-sso-tokens.php", "PANEL:database/migrations/2026_10_10_000002_webmail_sso_tokens.php"),
]

if __name__ == "__main__":
    print("D24 deploys via updater scripts")
