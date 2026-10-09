#!/usr/bin/env python3
"""installer/design-parity.sh.in -> installer/design-parity.sh"""
import hashlib, pathlib
ROOT = pathlib.Path(__file__).resolve().parent.parent
PAYLOADS = [
    ('P_LAYOUT', 'server-snapshot/files/usr/local/alphacp/panel/resources/views/layouts/panel.blade.php'),
    ('P_CSS',    'server-snapshot/files/usr/local/alphacp/panel/public/assets/panel.css'),
    ('P_DWHM',   'server-snapshot/files/usr/local/alphacp/panel/resources/views/dashboard-whm.blade.php'),
    ('P_DCP',    'server-snapshot/files/usr/local/alphacp/panel/resources/views/dashboard-cpanel.blade.php'),
    ('P_TILE',   'server-snapshot/files/usr/local/alphacp/panel/resources/views/partials/tile.blade.php'),
    ('P_DSECT',  'server-snapshot/files/usr/local/alphacp/panel/resources/views/partials/dash-sections.blade.php'),
    ('P_WSIDE',  'server-snapshot/files/usr/local/alphacp/panel/resources/views/partials/whm-sidebar.blade.php'),
    ('P_ICONS',  'server-snapshot/files/usr/local/alphacp/panel/resources/views/partials/icons.blade.php'),
    ('P_CSIDE',  'server-snapshot/files/usr/local/alphacp/panel/resources/views/partials/cpanel-sidebar.blade.php'),
    ('P_GUEST',  'server-snapshot/files/usr/local/alphacp/panel/resources/views/layouts/guest.blade.php'),
    ('P_LOGIN',  'server-snapshot/files/usr/local/alphacp/panel/resources/views/auth/login.blade.php'),
]
src = (ROOT / 'installer' / 'design-parity.sh.in').read_text()
for marker, rel in PAYLOADS:
    body = (ROOT / rel).read_text()
    assert f'@@{marker}@@' in src, marker
    src = src.replace(f'@@{marker}@@', body.rstrip('\n'))
out = ROOT / 'installer' / 'design-parity.sh'
out.write_text(src)
print(f'[OK] built installer/design-parity.sh  ({len(src.encode())} bytes)')
print('     sha256:', hashlib.sha256(src.encode()).hexdigest())
