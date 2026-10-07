#!/usr/bin/env bash
# Read-only readiness report. Does NOT enable entries or read credentials.
set -euo pipefail
printf 'AlphaCP entry-preflight v0.1.0 — READ ONLY; no deployment\n'
[[ $EUID -eq 0 ]] || { echo 'Run with sudo.' >&2; exit 1; }
for tool in nginx curl ss php python3; do command -v "$tool" >/dev/null || { echo "Missing: $tool"; exit 1; }; done
nginx -t
php -r 'printf("CLI PHP %s\n", PHP_VERSION); foreach (["pdo_mysql", "openssl", "mbstring"] as $e) { printf("%s: %s\n", $e, extension_loaded($e) ? "yes" : "NO"); }'
for port in 8090 2087; do
 body=$(curl --noproxy '*' -fsSk --connect-timeout 5 --max-time 20 "https://127.0.0.1:$port/login")
 grep -q AlphaCP <<<"$body" || { echo "Login body check failed: $port"; exit 1; }
 echo "Local login $port: OK (certificate trust NOT verified)"
done
for port in 2083 2096; do
 if [[ -n $(ss -Hltn "sport = :$port") ]]; then echo "Port $port: OCCUPIED — review before rollout"; else echo "Port $port: not bound"; fi
done
python3 - <<'PY'
from pathlib import Path
p=Path('/etc/nginx/sites-available/alphacp-panel.conf')
s=p.read_text()
for label,needle in [('FastCGI server port','$server_port'),('FastCGI full host','$http_host'),('Strict entry marker','fastcgi_param ACP_ENTRY_PORT')]:
 print(label+': '+('present (manual verification required)' if needle in s else 'absent'))
print('REPORT COMPLETE — no port, auth, config or firewall change made.')
PY
