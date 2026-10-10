#!/usr/bin/env python3
"""D23 — license_keys.expires_at TIMESTAMP->DATETIME (Y2038 fix).

Deploy: installer/alphacp-update.sh se (migrate --force isme shamil hai).
"""

FILES = [
    ("d23-migration-expires-datetime.php", "PANEL:database/migrations/2026_10_10_000001_license_keys_expires_datetime.php"),
]

if __name__ == "__main__":
    print("D23 deploys via installer/alphacp-update.sh")
