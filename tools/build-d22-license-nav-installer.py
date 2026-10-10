#!/usr/bin/env python3
"""D22 — License Server nav link (ModuleCatalog whm-config section).

Deploy: installer/alphacp-update.sh (B5 updater) se hota hai — is wave ka
alag installer nahi banta. Ye file sirf build-release-tree.py ke liye hai.
"""

FILES = [
    ("d22-ModuleCatalog.php", "PANEL:app/Support/ModuleCatalog.php"),
]

if __name__ == "__main__":
    print("D22 deploys via installer/alphacp-update.sh — kuch build nahi karna.")
