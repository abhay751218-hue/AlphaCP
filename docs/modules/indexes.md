# Module: Indexes
- **Step:** S5   **Status:** live (panel 0.12.0 / agent 0.9.0)

## Purpose
cPanel Indexes. Account-level Apache directory listing: `off` (default),
`simple`, `fancy`. paneld writes `~/etc/indexes.conf` (DirectoryMatch).

## Agent
| type | payload |
|---|---|
| `indexes.set` | username, mode=off\|simple\|fancy |

## Permissions
indexes.view / indexes.manage — customer + reseller (cPanel tile, not WHM)
