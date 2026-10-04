# Module: cPanel archive import (S10 engine)

- **Step:** S10   **Status:** built + tested (panel 0.69.0 / agent 0.61.0) — deploy COMMANDS.md se

## Purpose
Turn a real cPanel account tarball that already sits on this server into an
AlphaCP account home — the engine behind WHM **Transfer Tool** and
**Transfer or Restore a cPanel Account**.

The archive never travels through the panel: a real `cpmove` file is far larger
than any PHP upload limit, so the operator places it on the box (SFTP/root) and
the panel only queues the path. Everything privileged happens in the root agent.

```
panel (WHM page)                 paneld (root)
─────────────────                ─────────────────────────────────────────────
validate username/action         path guard + realpath + extension
validate archive path            sha256 (optional, panel-provided)
enqueue backup.cpanel    ───────▶ CpanelArchive::structure()   (layout + path safety)
  _confirm=backup.cpanel         member listing + verbose      (types, counts, bytes)
                                 CpanelArchive::assertNoSymlinkTraversal()
                                 tar --extract <root>/homedir → staging
                                 chown staged tree to the account
                                 rename swap + /home/.acp-prerestore-<user>-<stamp>
                                 result JSON (files/dirs/bytes/sections)
```

## Layouts understood
| Archive | Detected as | Home member |
|---|---|---|
| `cpmove-<user>.tar.gz` (`/scripts/pkgacct`) | `direct`, root `cpmove-<user>` | `cpmove-<user>/homedir` |
| legacy `backup-*.tar.gz` (full backup) | `direct`, root `` | `homedir` |
| older pkgacct with a nested home | `nested` | `cpmove-<user>/homedir/homedir.tar` |

Compression is auto-detected by tar (`.tar`, `.tar.gz`, `.tgz`) — the importer
never passes a compression flag, so both compressed and plain archives import.

## Safety rules (all fail closed)
1. Archive must be an absolute path inside an allowlisted root, a real regular
   file, and end in `.tar` / `.tar.gz` / `.tgz`.
2. SHA-256 is recomputed; a mismatch with the panel-provided hash refuses the
   import (no partial write).
3. Every member name is inspected: no absolute paths, no `..`, no backslashes,
   no NUL, entry count capped (500 000).
4. Entry types are checked — hardlinks, device nodes, FIFOs and sockets are
   refused (a hardlink to `/etc/shadow` would otherwise land in a customer home
   as root).
5. Archives that *write through a symlink* (a `link -> /etc`-style entry with
   members below it) are refused. Symlinks themselves are kept as links and
   never followed — an absolute target is fine as long as nothing is extracted
   under it.
6. Only the `homedir` subtree is extracted. `dnszones/`, `cp/`, `userdata/`,
   `sslkeys/`, `logs/`, … are **reported**, never extracted. `mysql/` ko `db.restore`
   alag task me import karta hai (neeche "MySQL dumps" dekho).
7. Extraction happens into a fresh staging dir under the accounts root with
   `--no-same-owner --one-file-system`; the staged tree is chowned to the
   account, then swapped in with same-filesystem renames.
8. The replaced home is kept as `/home/.acp-prerestore-<user>-<stamp>` (one per
   account) and any failure puts the old home back before the error leaves.
9. The account must already exist (create it on the Accounts page first) — the
   engine refuses to create Linux users from archive metadata.
10. A per-account lock (`backups/locks/<user>.lock`) means an import can never
    race a restore of the same home.

## Agent task
| type | payload |
|---|---|
| `backup.cpanel` | username, action (`transfer`/`restore`), archive_path, sha256?, `_confirm` |
| `backup.transfer` | username, source (FQDN), archive_path, sha256?, `_confirm` |

Both are `destructive` (they replace a home) with a 3600 s timeout and keep the
pre-restore copy. `backup.transfer` additionally records the source host in the
job result — an authenticated pull straight from the old server (SSH/API) is
still a later S10 step.

## MySQL dumps (`mysql/` section) — task `db.restore`

cPanel ka `mysql/<user>_<suffix>.sql` (ya `.sql.gz`) ab **asli MariaDB** me import hota hai
(`<account>_<suffix>` database banta hai, utf8mb4). Transfer or Restore page par checkbox
"MySQL dumps bhi restore karo" (default on) — home import queue hone ke **baad** wahi archive
`db.restore` task se dobara padha jata hai, aur optional "sirf ye databases" list se selection
hoti hai.

Guards (sab fail closed):

| Check | Behaviour |
|---|---|
| archive path / sha256 / hostile members | wahi guards jo home import me hain (allowlist, sha256 verify, `..`/absolute/NUL refuse) |
| dump ka naam | `<user>_<suffix>.sql` hi chalta hai; non-`.sql`, duplicate ya doosre account ka dump **skip** hota hai |
| apne database ke `USE` / `DROP|CREATE DATABASE` | line drop (mysqldump `--add-drop-database` normal hai); DB agent khud banata hai |
| doosre database ka naam, `INTO OUTFILE/DUMPFILE`, `LOAD DATA`, `LOAD_FILE`, `GRANT`, `CREATE/DROP USER`, `SET GLOBAL` | poora dump refuse — **ek bhi statement chalta nahi** |
| atomicity | pehle *saare* dumps stage + sanitise, phir MariaDB ko chhua jata hai |
| dumps ka size | 4 GiB per dump; content PHP memory me load nahi hota (chunked stdin stream) |
| staging | `/home/.acp-mysql-<user>-<stamp>` hamesha delete (failure par bhi) |

`db.restore` bhi `destructive` hai (`_confirm='db.restore'`, 3600 s). Result me per-database
report aati hai: `database`, `dump`, `bytes`, `statements_lines`, `database_created`, `status`
— aur `skipped` me wo dumps jinhe chhoda gaya (reason ke saath).

## Result (stored in `tasks.result`, shown on Review Transfers)
```json
{
  "username": "alicehost", "action": "restore", "archive": "cpmove-alicehost.tar.gz",
  "sha256": "…", "size_bytes": 12345678, "layout": "direct", "root": "cpmove-alicehost",
  "files": 4211, "dirs": 380, "bytes": 87654321,
  "sections": ["cp", "mysql", "userdata"], "section_entries": {"mysql": 3, "userdata": 4},
  "prerestore": ".acp-prerestore-alicehost-20261004101112", "status": "imported"
}
```

## Remaining S10 gaps
- MySQL dumps, mail (valiases/cur), DNS zones, SSL keys and cron from the
  archive are **not imported yet** (they are listed in `sections`).
- Remote pull (authenticated transfer from the old server) is not implemented;
  the archive must be placed on this server first.
- Panel-side HTTP upload for large archives is intentionally absent (PHP upload
  limits); use SFTP/`scp` or the drop dir `<ACP home>/incoming/`.

## Tests
- `agent/tests/run-tests.php` — cpmove/legacy/nested layouts, sha256 mismatch,
  foreign archive, path escape, hardlink, symlink traversal, missing account,
  empty home, transfer source recording.
- `tools/sim/cpanel-import-sim.sh` — real GNU tar + the real `CpanelArchive`
  class under php-wasm: genuine archive accepted, hostile ones refused.
- `tools/sim/cpanel-import-e2e.sh` — the real `BackupArchiveStore` + real
  CommandRunner + real tar against a throwaway accounts root: home swapped,
  old home kept as `.acp-prerestore-*`, legitimate symlink kept, staging
  cleaned, and seven hostile archives (escape / write-through symlink /
  hardlink / foreign cpmove / bad sha256 / missing file / not-a-tar) refused
  without touching the live home.
