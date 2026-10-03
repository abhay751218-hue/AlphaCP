# Module: Packages
- **Step:** S4   **Feature flag:** core   **Owner:** AlphaCP
- **Status:** in-progress (CRUD + feature lists + account upgrade/quota)

## Purpose
WHM-style hosting packages with cPanel-compatible limit keys (`QUOTA`, `MAXPOP`,
`MAXSQL`, …). Changing an account's package or quota enqueues `account.setQuota`
(panel never runs as root).

## Tables
| Table | Notes |
|---|---|
| `packages` | Limit columns (-1 = unlimited) + `feature_list_id` |
| `feature_lists` | JSON map of client tools a package may use |

## Permissions
| slug | privileged |
|---|---|
| packages.view | no |
| packages.manage | yes (not granted to customer/mail) |
| accounts.modify | yes — upgrade + quota override |

## Agent Tasks
| type | when |
|---|---|
| `account.setQuota` | upgrade/downgrade package or quota override |

## Routes
| method+path | permission |
|---|---|
| GET /packages | packages.view |
| GET/POST /packages/create | packages.manage |
| GET/PUT /packages/{id}/edit | packages.manage |
| POST /packages/{id}/archive | packages.manage |
| POST /accounts/{id}/upgrade | accounts.modify |
| POST /accounts/{id}/quota | accounts.modify |

## Test Checklist
- [x] Feature: list/create, cannot archive default or in-use, upgrade+quota enqueue setQuota, customer 403
