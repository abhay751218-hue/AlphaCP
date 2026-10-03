# Module: MultiPHP INI Editor
- **Step:** S5   **Status:** live (panel 0.10.0 / agent 0.7.0)

## Purpose
cPanel MultiPHP INI Editor. Customer sets allowlisted php.ini keys; paneld
writes `~/etc/php.ini` and `php_admin_value` / `php_admin_flag` on the FPM
pool. `open_basedir` stays locked. PHP version switch re-applies the same INI.

## Agent
| type | payload |
|---|---|
| `php.setIni` | username, directives{allowlisted keys} |

Hostile keys (`auto_prepend_file`, `disable_functions`, `open_basedir` override)
are rejected.

## Permissions
software.view / software.manage — customer (cPanel), not WHM tile
