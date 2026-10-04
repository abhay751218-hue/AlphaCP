# Module: Transfer Tool
- **Step:** S10   **Status:** live (panel 0.69.0 / agent 0.61.0)

## Purpose
WHM **Transfer Tool** (cPanel→AlphaCP migration): import a cpmove archive the
operator placed on this server into an existing account, recording the source
host FQDN in the job result.

Local import is real; pulling the archive straight off the old server over
SSH/API is still pending — the page says so explicitly.

## Agent
| type | payload |
|---|---|
| `backup.transfer` | username, source (FQDN), archive_path, sha256?, `_confirm` |

Same engine and safety rules as `backup.cpanel`
(`docs/modules/cpanel-import.md`): verified path + checksum, entry-type and
symlink-traversal checks, staging extract, rename swap, pre-restore copy.
`destructive`, 3600 s timeout. Username: 3–16 `^[a-z][a-z0-9]{2,15}$`, not
reserved. Source: FQDN (no pipe/path). Archive path: absolute
`.tar`/`.tar.gz`/`.tgz`, no `..`/pipe/escape.

## Permissions
accounts.view (WHM) — root + reseller. Customer 403.
