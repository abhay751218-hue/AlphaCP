# Module: Scheduled Backups (S10)

- **Step:** S10   **Status:** live (panel 0.68.0 / agent 0.60.0)

## Purpose
Make the WHM **Backup Configuration** schedule actually run. cPanel's "Backup
Configuration" only declares *when* backups happen; AlphaCP does the same thing
cPanel does — one cron entry that ticks a scheduler, plus a command that decides
whether the configured window is open.

The archives themselves are unchanged: `alphacp:scheduled-backups` only inserts
`backup.archive` task rows; paneld (root agent) creates the tar.gz, verifies it,
and prunes expired archives using the retention from the same WHM config
(see `docs/modules/file-directory-restoration.md` and the backup archive store).

## Pieces
| Piece | Where | Job |
|---|---|---|
| cron entry | `/etc/cron.d/alphacp-panel` (installer/panel-update.sh) | `* * * * * <panel user> … php artisan schedule:run` |
| schedule | `routes/console.php` | runs `alphacp:scheduled-backups` **hourly**, `withoutOverlapping(180)` |
| command | `app/Console/Commands/ScheduledBackupsCommand.php` | window check → queue one archive per selected active account |
| window logic | `app/Support/BackupSchedule.php` | window keys, marker file, claim/finish, admin state |
| marker | `storage/app/private/backup-schedule.json` (`ACP_BACKUP_SCHEDULE_FILE`) | which day / ISO week / month already ran |
| config | `acp.backup_schedule.file` | marker path override (tests) |

## Windows
| WHM schedule | Window key | Meaning |
|---|---|---|
| `daily` | `Y-m-d` | once per calendar day (server/app timezone) |
| `weekly` | `o-W` (ISO year-week) | once per ISO week — a Sunday-night catch-up still counts as that week |
| `monthly` | `Y-m` | once per month |
| `disabled` | — | nothing is queued (unless an operator runs `--force`) |

The command ticks hourly, so a server that was down at the nominal time catches
up on the next tick **the same day**, and a day/week/month can never produce two
real passes: the marker is claimed before the first archive is queued.

## Which accounts
1. If **WHM → Backup User Selection** has rows, only those usernames.
2. Otherwise every `status = active` account (suspended/terminated/pending are skipped).
3. Accounts that already have a `queued` or `running` `backup.archive` task are
   skipped — a slow or offline agent can never make daily backups pile up.

## Operator commands
```bash
sudo -u alphacp php /usr/local/alphacp/panel/artisan alphacp:scheduled-backups --dry-run
sudo -u alphacp php /usr/local/alphacp/panel/artisan alphacp:scheduled-backups --force
sudo -u alphacp php /usr/local/alphacp/panel/artisan schedule:list
```
`--dry-run` lists the accounts that *would* be queued and writes nothing;
`--force` runs one pass without consuming the window (useful after widening the
user selection). Exit code `1` means at least one account failed to enqueue, so
cron mail / logs flag it; per-account failures are also written to the marker
(shown on the WHM Backup Config page) and to the audit log (`backup.schedule`).

## Logs / troubleshooting
- Laravel scheduler output: `/usr/local/alphacp/panel/storage/logs/panel-schedule.log`.
- Cron file must stay `0644 root:root` — Ubuntu's cron ignores group-writable
  files in `/etc/cron.d`; the updater rewrites it on every release and preserves
  any foreign file at that path as `*.bak-<stamp>`.
- `cron` package/service is checked (and installed when apt is available).

## Still missing (S10 remainder)
Remote destinations (S3/FTP/rsync off-site copies), mail/MySQL archives (depends
on S7/S8 backends), and real cPanel transfer/import. Rows 176/178 stay 🟡 for
those reasons.
