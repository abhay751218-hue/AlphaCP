# Module: MultiPHP INI Editor
- **Step:** S5   **Status:** live (account + per-domain)

## Purpose
cPanel MultiPHP INI Editor. Customer sets allowlisted php.ini keys; paneld
writes `~/etc/php.ini` and `php_admin_value` / `php_admin_flag` on the FPM
pool. `open_basedir` stays locked. PHP version switch re-applies the same INI.

## Agent
| type | payload |
|---|---|
| `php.setIni` | username, directives{allowlisted keys}; optional `domain` + `php_version` = sirf us domain ka pool + `~/etc/php.<domain-slug>.ini` |

Hostile keys (`auto_prepend_file`, `disable_functions`, `open_basedir` override)
are rejected.

Per-domain: `domain` + `php_version` dono chahiye (warna fail closed). Panel
`POST /php/ini/domain/{domain}` (`php.ini.domain.update`, permission
`software.manage`, ownership check) se aata hai; saare fields khali = domain
account pool par wapas.

## Permissions
software.view / software.manage — customer (cPanel), not WHM tile
