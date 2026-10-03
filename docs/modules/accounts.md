# Module: Accounts
- **Step:** S3   **Feature flag:** core   **Owner:** AlphaCP
- **Status:** in-progress (create / suspend / unsuspend / terminate + Accounts UI)

## Purpose
Hosting account lifecycle: Linux user, home (`public_html`), Apache vhost, PHP-FPM
pool, disk quota. Panel never runs as root — `paneld` executes allowlisted tasks
with compensating rollback on create failure.

## Tables
| Table | Notes |
|---|---|
| `packages` | Minimal default package (`default`, 1024 MB). Full UI is S4. |
| `accounts` | One row per Linux user. Soft-delete; terminate suffixes username/domain. |
| `account_events` | Lifecycle log (queued/synced). |
| `account_users` | Panel login ↔ account (owner). |

## Permissions
| slug | description | privileged | destructive |
|---|---|---|---|
| accounts.view | List/view accounts | no | no |
| accounts.create | Create hosting accounts | yes | no |
| accounts.suspend | Suspend/unsuspend | yes | no |
| accounts.terminate | Terminate accounts | yes | yes |
| accounts.modify | Modify (S4+) | yes | no |

## Agent Tasks
| type | safety | payload | rollback |
|---|---|---|---|
| `account.create` | mutating | username, domain, shadow_hash, quota_mb, php_version | userdel -r, remove vhost/pool, quota 0, reload |
| `account.suspend` | mutating | username, domain, reason | n/a (unsuspend reverses) |
| `account.unsuspend` | mutating | username, domain | n/a |
| `account.terminate` | destructive | username, `_confirm=account.terminate` | n/a (idempotent if gone) |
| `account.setQuota` | mutating | username, quota_mb (-1 unlimited) | n/a |

Username/domain rules live in `agent/src/AccountIdentity.php` **and**
`panel/app/Support/AccountIdentity.php` — keep them identical.

## Routes / Pages
| method+path | page | permission |
|---|---|---|
| GET /accounts | List | accounts.view |
| GET /accounts/create | Form | accounts.create |
| POST /accounts | Enqueue create | accounts.create |
| GET /accounts/{id} | Show + actions | accounts.view |
| POST /accounts/{id}/suspend | Enqueue suspend | accounts.suspend |
| POST /accounts/{id}/unsuspend | Enqueue unsuspend | accounts.suspend |
| POST /accounts/{id}/terminate | Enqueue terminate (typed username) | accounts.terminate |

## API (if exposed)
WHM `createacct` / `suspendacct` / `unsuspendacct` / `removeacct` → S12.

## Error Codes
ACP-ACCT-001 reserved/invalid username · ACP-ACCT-002 domain taken ·
ACP-ACCT-003 license locked · ACP-ACCT-004 max_accounts · ACP-ACCT-005 provision fail

## Test Checklist
- [x] Feature: permission, validation, enqueue (no plaintext password in task payload)
- [x] Agent: create/suspend/unsuspend/terminate/quota + rollback + PathGuard
- [ ] Manual on dev-srv1 after panel-update 0.4.0

## Known Limits / TODO(step-S4)
- Packages manager UI, per-account quota edit, dedicated IP, shell access.
- SSL / addon domains are S5. Email is S7.
