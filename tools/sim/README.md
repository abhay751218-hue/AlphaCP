# tools/sim — panel-doctor ka local simulation test

Server (Ubuntu 24.04 + Ondrej php8.4-fpm) jaisi **asli** condition bana kar doctor test karta hai:

* `ProtectSystem=full` ko **sach me** simulate kiya jata hai — har panel request ek alag
  mount-namespace me chalti hai jahan `/usr` read-only bind-mount hai (bilkul wahi jo systemd
  karta hai). Drop-in (`ReadWritePaths=-/usr/local/alphacp`) + `daemon-reload` ke baad
  `/usr/local/alphacp` rw bind hota hai — jaise systemd karta hai.
* Panel = asli `panel-bundle-0.3.0` (Laravel 13.33.0, vendor ke saath), asli migrations +
  seeders, asli `alphacp:admin-password` command. PHP 8.4 = php-wasm (`@php-wasm/cli`).
* Sirf `systemctl` aur `curl` stub hain (sandbox me systemd/nginx nahi chalta).

Agent provisioning (no sudo, php-wasm):

```bash
bash tools/sim/provision-sim.sh          # account create/suspend/terminate/rollback + agent task suite
bash tools/sim/backup-tar-sim.sh          # real GNU tar create/list/extract + symlink/hash smoke test
sudo bash tools/sim/sync-sim.sh           # alphacp-sync v1.3 server -> GitHub snapshot tests (66 checks)
sudo bash tools/sim/update-sim.sh         # panel-update 0.65.0 tests (185 checks)
```

Chalana (sudo chahiye, sirf throwaway sandbox/container me — `/usr/local/alphacp` banata hai):

```bash
sudo bash tools/sim/doctor-sim.sh            # scenario A + B + C
```
