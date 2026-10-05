# Module: MultiPHP + Cron
- **Step:** S5   **Status:** live (account + per-domain PHP, crontab)

## Purpose
cPanel MultiPHP Manager (account PHP-FPM pool) and Cron Jobs. Customer only —
WHM account page se bhi PHP change ho sakta hai (`accounts.modify`).

## Agent tasks
| type | payload |
|---|---|
| `php.setVersion` | username, php_version (7.4 / 8.1–8.4); optional `domain` = sirf us domain ka apna pool + vhost socket |
| `cron.set` | username, jobs[] (full crontab replace) |

## Per-domain PHP (cPanel MultiPHP Manager)

Same task `php.setVersion`, optional `domain` field:
* pool file: `/etc/php/<version>/fpm/pool.d/acp-<user>-<domain-slug>.conf` (section `acp_<user>_<domain>`),
* socket: `/run/php/acp-<user>-<domain-slug>.sock` — sirf usi domain ke vhost me `SetHandler` badalta hai,
* slot badalne par purane version ka pool hata diya jata hai (leftover nahi),
* fail closed: domain ka koi AlphaCP vhost (`ServerName`) na mile, ya vhost me AlphaCP handler na ho to task reject.

Panel: `POST /php/domain/{domain}` (`php.domain.update`, permission `software.manage`, ownership check).

## Permissions
software.view/manage · cron.view/manage (customer + reseller)
