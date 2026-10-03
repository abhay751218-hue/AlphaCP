# Module: MultiPHP + Cron
- **Step:** S5   **Status:** in-progress (account PHP + crontab)

## Purpose
cPanel MultiPHP Manager (account PHP-FPM pool) and Cron Jobs. Customer only —
WHM account page se bhi PHP change ho sakta hai (`accounts.modify`).

## Agent tasks
| type | payload |
|---|---|
| `php.setVersion` | username, php_version (7.4 / 8.1–8.4) |
| `cron.set` | username, jobs[] (full crontab replace) |

## Permissions
software.view/manage · cron.view/manage (customer + reseller)
