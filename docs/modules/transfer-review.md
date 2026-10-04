# Module: Review Transfers and Restores
- **Step:** S10   **Status:** live (panel 0.69.0 / agent 0.61.0)

## Purpose
WHM **Review Transfers and Restores** now shows the **real job history**: every
`backup.cpanel` / `backup.transfer` task from the panel's task queue with status
(queued/running/success/failed/cancelled), account, imported file/byte counts,
the skipped archive sections (mysql, userdata, …) and the agent's error text.
Data comes straight from `tasks` (`Paneld::recentJobs`), so it can never drift
from what the agent actually did.

The legacy manual review note (JSON
`/usr/local/alphacp/etc/backup/review.json`, task `backup.review`, statuses
pending/ok/failed) stays available on the same page for the audit trail.

## Agent
| type | payload |
|---|---|
| `backup.review` | username, status (legacy manual note — unchanged) |
| history | read-only: `backup.cpanel` + `backup.transfer` task rows |

## Permissions
accounts.view (WHM) — root + reseller. Customer 403.
