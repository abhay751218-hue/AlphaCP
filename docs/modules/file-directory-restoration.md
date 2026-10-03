# Module: File and Directory Restoration
- **Step:** S10   **Status:** live (panel 0.67.0 / agent 0.60.0)

## Purpose
WHM File and Directory Restoration slice 1. Writes
`/usr/local/alphacp/etc/backup/filedir.json` (username + relative path).
No tar, no shell, no pipe. Hostile username/path fail closed.
Customer cPanel hides the tile (has File Restoration). Tar extract later.

## Agent
| type | payload |
|---|---|
| `backup.filedir` | username, path |

Username: 3–16 `^[a-z][a-z0-9]{2,15}$`, not reserved. Path: relative under home.

## Permissions
accounts.view (WHM) — root + reseller. Customer 403.

## Slice 2 — real restore (panel 0.67.0 / agent 0.60.0)
Takes a previously published, SHA-256-verified home archive (`backup.archive`)
and puts it back — whole home, or one subtree (`path`), like cPanel. Destructive:
the panel requires an explicit confirmation checkbox and the agent requires
`_confirm = backup.extract`.

| type | payload |
|---|---|
| `backup.extract` | `username`, `archive_id`, optional `path`, `_confirm` |

Safety rules (all fail closed):
1. manifest + size + SHA-256 are re-verified **before** anything moves;
2. every tar entry must live under `<username>/` — `..`, absolute paths, NUL and
   backslashes are refused;
3. hardlink / device / FIFO / socket entries are refused (a hardlink to
   `/etc/shadow` would otherwise land inside a customer home as root);
4. extraction runs in a root-owned staging dir outside the account with
   `--no-same-owner` and `--one-file-system`, then the tree is chowned to the
   account (symlinks are skipped, never followed);
5. the swap uses same-filesystem renames: the current tree moves to
   `/home/.acp-prerestore-<user>-<stamp>` first, the staged tree moves in; any
   failure renames the old tree back. The last pre-restore copy per account is
   kept (older ones pruned), the archive itself is never deleted;
6. a per-account `flock` makes concurrent restores impossible.

Customer Backup page (`/backup`) gets the "Restore a home archive" card: archive
dropdown limited to that account's own completed archives, optional relative
path, required confirm checkbox. Invalid/foreign ids, hostile paths and missing
confirmation are rejected before any task is queued. Mail accounts 403.

### Tests
- provision-sim: verified-archive restore + pre-restore copy kept; subtree
  restore; hostile archives (path escape, hardlink) and unknown archive rejected.
- panel (`BackupTest`): queue + foreign/unknown archive id + hostile path +
  missing confirmation + mail 403.
- Real GNU tar round-trip in the sandbox: create → literal list → numeric verbose
  list → full + subtree extract → symlink kept as a link.
