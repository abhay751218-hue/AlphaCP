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
```

> ⚠️ **`provision-sim.sh` ko sudo ke BINA chalao.** Uske mail tests mailbox UID/GID = **1001** (sandbox user)
> ke hisaab se ownership/search checks karte hain; root se chalane par 2 tests jhoothi FAIL deti hain
> (209/0 ke bajaye 207/2). Ye test ka rule hai, code ka bug nahi.

## Sandbox environment ke do jaal (6 Oct, verified)

1. **`/usr/bin/php8.4` stub** — `update-sim.sh` khud ek chhota `php8.4` stub banata hai (php-wasm CLI
   ki taraf point karta hai). Wo stub **`s7-mail-sim.sh` ko todta hai**: wahan ke verifier
   `command -v php8.4` ko asli PHP maan lete hain aur fake (Python) paneld ko PHP se chalane lagte hain →
   `Parse error ... in paneld on line 351` → 7/14 fail. Mail sim chalane se pehle:
   `sudo rm -f /usr/bin/php8.4` (agli update-sim use dobara bana degi).
2. **`panel-tests.sh` aur `provision-sim.sh` ek saath na chalao** — dono php-wasm boot karte hain; 2 vCPU par
   timeouts de sakte hain. Ek ke baad ek chalao.

Chalana (sudo chahiye, sirf throwaway sandbox/container me — `/usr/local/alphacp` banata hai):

```bash
sudo bash tools/sim/doctor-sim.sh            # scenario A + B + C
```
