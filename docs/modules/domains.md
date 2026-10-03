# Module: Domains
- **Step:** S5   **Feature flag:** core   **Owner:** AlphaCP
- **Status:** in-progress (cPanel Domains tool: main/addon/sub/parked/redirect)

## Purpose
Customer (role `user`) apne hosting account ke domains manage karta hai — WHM Create Account
nahi. Extra vhost paneld `domain.add` / `domain.remove` se likhta hai.

## Shell split
| Role | Panel | Dekhta hai |
|---|---|---|
| root / reseller | WHM | Accounts, packages, server |
| user (shared hosting) | cPanel | Files/email/domains/… tiles. **Create Account nahi** |
| mail | cPanel (email only) | Email + security |

## Permissions
| slug | who |
|---|---|
| domains.view | customer + reseller + root |
| domains.manage | customer (own account) + reseller + root |

## Agent Tasks
| type | when |
|---|---|
| `domain.add` | addon / sub / parked / redirect vhost |
| `domain.remove` | extra vhost hatao (files rehte hain) |

## Test Checklist
- [x] Customer dashboard me Create Account nahi
- [x] Root dashboard WHM — File Manager nahi
- [x] Addon/sub enqueue domain.add; main delete nahi; MAXADDON limit
