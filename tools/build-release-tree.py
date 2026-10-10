#!/usr/bin/env python3
"""Build release/tree — repo ka single-source-of-truth product bundle.

Base: server-snapshot (live 0.75.x full panel+agent app) + sare deployed waves
(theme v1.2 + D1-D18) ke 166 overlay files, deploy ORDER me applied.
Output: release/tree/{panel,agent} + release/MANIFEST.json (per-file sha256).
Fresh install = is tree ko /usr/local/alphacp par sync karna (B4 installer).
System-level components (nginx vhost, phpMyAdmin, php ini) wave installers
se aate hain — MANIFEST me 'system_components' me listed.
"""
import hashlib, json, pathlib, re, shutil, sys, datetime

ROOT = pathlib.Path(__file__).resolve().parents[1]
SNAP = ROOT / 'server-snapshot/files/usr/local/alphacp'
REFS = ROOT / 'refs/live-theme-fix'
OUT = ROOT / 'release'
TREE = OUT / 'tree'

ORDER = ['theme-fix','d1-email','d2-domains','d3-mysql','d4-whm','d5-tools',
         'd6a-email-ui','d6b-files-security','d6c-software-whm','d7-metrics-tools',
         'd8-mailqueue-secpol','d9-terminal-webmail','d10-final-tiles',
         'd11-restart-services','d12-phpmyadmin-sso','d13-nodejs-apps',
         'd14-parity-gaps','d15-filemanager-plus','d16-branding-sweep',
         'd17-look-parity','d18-navbar-parity','d19-license-api',
         'd22-license-nav','d23-license-expiry','d24-webmail-sso-table',
         'd25-maildir-owner','d26-mailbox-status','d27-home-nav','d28-filemanager-parity','d29-fm-look-parity','d30-fm-cpanel-exact','d31-ssl-symlink','d32-panel-hardening','d33-fm-pro','d34-fm-rebuild']

def sha(p):
    return hashlib.sha256(p.read_bytes()).hexdigest()

def main():
    if TREE.exists():
        shutil.rmtree(TREE)
    (TREE / 'panel').parent.mkdir(parents=True, exist_ok=True)
    shutil.copytree(SNAP / 'panel', TREE / 'panel')
    shutil.copytree(SNAP / 'agent', TREE / 'agent')

    tools = {p.name: p for p in (ROOT / 'tools').glob('build-*-installer.py')}
    waves, overlays, skipped = [], [], []
    for key in ORDER:
        match = [n for n in tools if key in n]
        assert len(match) == 1, (key, match)
        txt = tools[match[0]].read_text()
        m = re.search(r'FILES = \[(.*?)\]', txt, re.S)
        pairs = re.findall(r'\(\s*"([^"]+)"\s*,\s*"([^"]+)"\s*\)', m.group(1))
        applied = 0
        for src, dst in pairs:
            sp = REFS / src
            assert sp.exists(), f"{key}: missing staged {src}"
            if dst.startswith(('NGX:', 'PMA:', 'PMAETC:')):
                skipped.append({'wave': key, 'src': src, 'target': dst})
                continue
            if dst.startswith('PANEL:'):
                rel = 'panel/' + dst[6:]
            elif dst.startswith('AGENT:'):
                rel = 'agent/' + dst[6:]
            elif dst.startswith('../agent/'):
                rel = 'agent/' + dst[9:]
            else:
                rel = 'panel/' + dst
            tp = TREE / rel
            tp.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(sp, tp)
            overlays.append({'wave': key, 'src': src, 'path': rel, 'sha256': sha(sp)})
            applied += 1
        waves.append({'wave': key, 'files': applied})

    # verify: har path ka AAKHRI overlay hi final content hai (baad ke waves jeet-te hain)
    last = {}
    for o in overlays:
        last[o['path']] = o['sha256']
    for path, h in last.items():
        assert sha(TREE / path) == h, path

    # .env template (no secrets) — fresh install ke liye
    envt = ROOT / 'release' / 'env.example.in'
    if envt.exists():
        shutil.copy2(envt, TREE / 'panel' / '.env.example')

    files = sorted(p for p in TREE.rglob('*') if p.is_file())
    manifest = {
        'product': 'AlphaCP',
        'version': '1.0.0-rc1',
        'built': datetime.date.today().isoformat(),
        'base': 'server-snapshot live 0.75.x',
        'waves': waves,
        'overlay_count': len(overlays),
        'overlays': overlays,
        'system_components_from_wave_installers': skipped,
        'total_files': len(files),
        'tree_sha256': {str(p.relative_to(TREE)): sha(p) for p in files},
    }
    (OUT / 'MANIFEST.json').write_text(json.dumps(manifest, indent=1))
    print(f"release/tree: {len(files)} files, overlays applied: {len(overlays)}, "
          f"system comps (installer-managed): {len(skipped)}")
    print("MANIFEST.json written")

if __name__ == '__main__':
    main()
