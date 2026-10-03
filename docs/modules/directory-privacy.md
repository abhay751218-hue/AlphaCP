# Module: Directory Privacy
- **Step:** S6   **Status:** live (panel 0.16.0 / agent 0.13.0)

## Purpose
cPanel Directory Privacy. Password-protect folders under the account home
with Apache Basic Auth. paneld writes `~/etc/privacy.conf` + htpasswd files.
Plaintext passwords never reach the agent (bcrypt hashes only).

## Agent
| type | payload |
|---|---|
| `privacy.set` | username, entries=[{path, realm, users:[{name, hash}]}] (empty clears) |

## Permissions
privacy.view / privacy.manage — customer + reseller (cPanel tile, not WHM)
