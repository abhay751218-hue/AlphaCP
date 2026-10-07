#!/usr/bin/env python3
"""installer/dns-cluster.sh — feature files + dashboard tile self-flip (no separate dashboard-sync)."""
import pathlib

repo = pathlib.Path(__file__).resolve().parents[2]
f = repo / "features/dnscluster"
files = {
    "app/Models/DnsClusterNode.php": (f / "app/Models/DnsClusterNode.php").read_text(),
    "app/Http/Controllers/DnsClusterController.php": (f / "app/Http/Controllers/DnsClusterController.php").read_text(),
    "resources/views/dns-cluster/index.blade.php": (f / "resources/views/dns-cluster/index.blade.php").read_text(),
    "database/migrations/2026_10_07_000009_create_dns_cluster_nodes_table.php": (f / "database/migrations/2026_10_07_000009_create_dns_cluster_nodes_table.php").read_text(),
}
routes = (f / "routes-dnscluster.php").read_text()

def block(dest, content):
    return (f'cat > "$PANEL/{dest}" <<\'ACP_FILE_EOF\'\n'
            + content.rstrip("\n") + "\n"
            + "ACP_FILE_EOF\n"
            + f'echo "  + {dest}"\n')

out = ["#!/usr/bin/env bash\n",
       "# AlphaCP — DNS Cluster (WHM) portable installer  v1.0\n",
       "# NOTE: ye installer apna dashboard tile KHUD live kar deta hai — dashboard-sync alag se NAHI chahiye.\n",
       "set -euo pipefail\n",
       "PANEL=/usr/local/alphacp/panel\n",
       'echo "=================================================="\n',
       'echo " AlphaCP DNS Cluster installer  v1.0"\n',
       'echo "=================================================="\n',
       'echo "== Step 1: panel feature files =="\n',
       'mkdir -p "$PANEL/resources/views/dns-cluster"\n']
for dest, content in files.items():
    out.append(block(dest, content))
out.extend(['\n',
            'echo "== Step 2: routes (idempotent) =="\n',
            'if ! grep -q "DNS Cluster (WHM)" "$PANEL/routes/web.php"; then\n',
            "cat >> \"$PANEL/routes/web.php\" <<'ACP_ROUTES_EOF'\n",
            routes.rstrip("\n") + "\n",
            "ACP_ROUTES_EOF\n",
            'echo "[OK] routes appended"\n',
            "else\n",
            'echo "[OK] routes already present"\n',
            "fi\n",
            '\n',
            'echo "== Step 3: dashboard tile self-flip (DNS Cluster -> live) =="\n',
            "sed -i -E \"s/('name' => 'DNS Cluster',.*'status' => ')step(')/\\1live', 'route' => 'dns-cluster.index\\2/\" \"$PANEL/app/Support/ModuleCatalog.php\"\n",
            'echo "[OK] tile flipped to live"\n',
            '\n',
            'echo "== Step 4: migrate + cache clear =="\n',
            'cd "$PANEL"\n',
            "php artisan migrate --force\n",
            "php artisan route:clear || true\n",
            "php artisan config:clear || true\n",
            'echo "[OK] migrated"\n',
            '\n',
            'echo "=================================================="\n',
            'echo " ==> DNS CLUSTER v1.0 INSTALLED  (panel: /dns-cluster)"\n',
            'echo "=================================================="\n',
            "alphacp-sync || true\n"])

(repo / "installer/dns-cluster.sh").write_text("".join(out))
print("WROTE installer/dns-cluster.sh", (repo / "installer/dns-cluster.sh").stat().st_size, "bytes")
