#!/usr/bin/env python3
"""installer/ports-ctrl.sh.in → installer/ports-ctrl.sh (payloads embed)."""
import hashlib, pathlib, sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
SRC = ROOT / 'installer' / 'ports-ctrl.sh.in'
OUT = ROOT / 'installer' / 'ports-ctrl.sh'

PAYLOADS = [
    ('PANEL_PORTMAP',      'server-snapshot/files/usr/local/alphacp/panel/app/Support/PortMap.php'),
    ('PANEL_GUARD',        'server-snapshot/files/usr/local/alphacp/panel/app/Http/Middleware/AcpPortGuard.php'),
    ('PANEL_LOGINCTRL',    'server-snapshot/files/usr/local/alphacp/panel/app/Http/Controllers/Auth/LoginController.php'),
    ('PANEL_PORTSCTRL',    'server-snapshot/files/usr/local/alphacp/panel/app/Http/Controllers/PortsController.php'),
    ('PANEL_LOGINBLADE',   'server-snapshot/files/usr/local/alphacp/panel/resources/views/auth/login.blade.php'),
    ('PANEL_PORTSVIEW',    'server-snapshot/files/usr/local/alphacp/panel/resources/views/ports/index.blade.php'),
    ('PANEL_BOOTSTRAP',    'server-snapshot/files/usr/local/alphacp/panel/bootstrap/app.php'),
    ('PANEL_ACPCONFIG',    'server-snapshot/files/usr/local/alphacp/panel/config/acp.php'),
    ('PANEL_DASHWHM',      'server-snapshot/files/usr/local/alphacp/panel/resources/views/dashboard-whm.blade.php'),
    ('PANEL_DASHCPANEL',   'server-snapshot/files/usr/local/alphacp/panel/resources/views/dashboard-cpanel.blade.php'),
    ('PANEL_DASHCTRL',     'server-snapshot/files/usr/local/alphacp/panel/app/Http/Controllers/DashboardController.php'),
    ('AGENT_PORTSNGINX',   'server-snapshot/files/usr/local/alphacp/agent/src/PortsNginx.php'),
    ('AGENT_PORTSTASK',    'server-snapshot/files/usr/local/alphacp/agent/src/Tasks/PortsApply.php'),
    ('AGENT_TASKS_ENTRY',  'installer/payloads/ports-apply.task.php'),
]

def main() -> int:
    s = SRC.read_text()
    for key, rel in PAYLOADS:
        body = (ROOT / rel).read_text()
        marker = f'@@{key}@@'
        if s.count(marker) != 1:
            print(f'ERR marker {marker} x{s.count(marker)}')
            return 1
        s = s.replace(marker, body)
    OUT.write_text(s)
    sha = hashlib.sha256(OUT.read_bytes()).hexdigest()
    print(f'[OK] built {OUT.relative_to(ROOT)}  ({OUT.stat().st_size} bytes)')
    print(f'     sha256: {sha}')
    return 0

if __name__ == '__main__':
    sys.exit(main())
