# Module: SSH Access
- **Step:** S6   **Status:** live (panel 0.18.0 / agent 0.15.0)

## Purpose
cPanel SSH Access. Import OpenSSH public keys into `~/.ssh/authorized_keys`.
Optional `/bin/bash` only when package `HASSHELL` is on (else nologin).
Private keys, key options (`command=`, `from=`), and `ssh-dss` fail closed.
No virtfs jail / Terminal in this slice.

## Agent
| type | payload |
|---|---|
| `ssh.set` | username, keys=[{type,key,comment}], shell=nologin\|bash |

## Permissions
ssh.view / ssh.manage — customer + reseller (cPanel tile, not WHM)
