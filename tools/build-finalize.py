#!/usr/bin/env python3
"""installer/finalize.sh.in -> installer/finalize.sh (payload embed + sha256)."""
import hashlib, pathlib

ROOT = pathlib.Path(__file__).resolve().parent.parent
PAYLOADS = [
    ('PANEL_BLADE',   'server-snapshot/files/usr/local/alphacp/panel/resources/views/layouts/panel.blade.php'),
    ('PANEL_CSS',     'server-snapshot/files/usr/local/alphacp/panel/public/assets/panel.css'),
    ('PANEL_ACPCFG',  'server-snapshot/files/usr/local/alphacp/panel/config/acp.php'),
    ('RC_LOGOSVG',    'installer/payloads/acp-logo.svg'),
]

src = (ROOT / 'installer' / 'finalize.sh.in').read_text()
for marker, rel in PAYLOADS:
    body = (ROOT / rel).read_text()
    assert f'@@{marker}@@' in src, marker
    assert f'@@{marker}@@' not in body
    src = src.replace(f'@@{marker}@@', body.rstrip('\n'))
out = ROOT / 'installer' / 'finalize.sh'
out.write_text(src)
sha = hashlib.sha256(src.encode()).hexdigest()
print(f'[OK] built installer/finalize.sh  ({len(src.encode())} bytes)')
print(f'     sha256: {sha}')
