# Panel Experience: WHM, Reseller, and Customer Workspaces

**Status:** v0.76.0 source work on branch `arena/aeae010f-alphacp`; not deployed. The latest deployed snapshot remains v0.75.0 in `server-snapshot/`.

## Goal and design boundary

AlphaCP provides distinct, role-aware workspaces for server operators (WHM-like), resellers, and hosting customers. The views can share a Laravel application, but navigation is not an authorization boundary: every route, controller query, and mutation must enforce permissions and ownership server-side. The visual system uses AlphaCP's own palette, name, icons, and layout; cPanel/WHM are references for feature organization only. Do not copy their logo, protected branding, icons, or colors.

This release establishes differentiated workspace shells in one application. It does **not** create three separate deployments, hosts, databases, or session stores, and it does not claim complete WHM/cPanel parity.

## Research and design inputs

Official product documentation reviewed on 2026-10-07:

- [The cPanel Interface](https://docs.cpanel.net/cpanel/the-cpanel-interface/the-cpanel-interface/) groups tools in a navigation/main-tools view and presents General Information and Statistics. This informed AlphaCP's grouped task navigation and dashboard summaries; cPanel-specific visuals and branding were not copied.
- [WHM Add a Package](https://docs.cpanel.net/whm/packages/add-a-package/) exposes resource and service limits across disk/bandwidth, mail, FTP, databases and domains. AlphaCP package creation remains governed by the existing account/package model and allowlisted agent tasks.
- [WHM Feature Manager](https://docs.cpanel.net/whm/packages/feature-manager/) uses feature lists to determine which tools accounts can see; it does not replace server permissions. AlphaCP follows the same security principle: hiding a module is never a grant of authority.
- [WHM reseller privileges](https://docs.cpanel.net/whm/resellers/edit-reseller-nameservers-and-privileges/) include limits on account creation, resources, and package availability. Full ACL/quotas and reseller-tree management remain follow-up scope; the current implementation supplies ownership scoping, not a complete WHM reseller ACL system.
- [WHM API 1 introduction](https://api.docs.cpanel.net/whm/introduction) and [cPanel UAPI introduction](https://api.docs.cpanel.net/cpanel/introduction) distinguish server administration from account-level operations. Keep these surfaces and their authorization contexts distinct as API coverage grows.

## Implemented in v0.76.0 source

- `app/Support/ModuleCatalog.php` resolves display mode in the order root/operator → reseller role → account customer; navigation sections are selected for that mode. A module's presence in the catalog does not grant access.
- `resources/views/layouts/panel.blade.php` and `public/assets/panel.css` provide distinct AlphaCP-styled WHM/operator, reseller, and customer navigation and color palettes.
- `DashboardController.php` and `resources/views/dashboard.blade.php` show reseller-scoped account/package counts and recent client accounts instead of server-wide WHM data.
- `AccountsController.php` scopes reseller lists, counts, details, and mutations to `accounts.reseller_id`; account creation records the reseller, and the account-detail package picker plus upgrade endpoint accept only global or reseller-owned packages.
- `PackagesController.php` exposes only global and current-reseller packages, scopes package account counts, records package ownership on create, prevents resellers from making a package the global default, and rejects cross-owner edits/archive operations.
- The `GET /resellers` listing requires `roles.manage`.
- Alternate reseller navigation is not counted as completion of the cPanel/WHM feature-parity checklist; no checklist rows were removed.

## Security invariants

1. Reseller account queries and record actions must be scoped by `accounts.reseller_id` at the server, including direct-ID requests.
2. A reseller can provision or upgrade an account using global packages and packages with `packages.owner_id` equal to that reseller only. Do not treat hidden links or dropdown filtering as access control.
3. Reseller-created packages inherit the authenticated reseller as owner and cannot replace the global default.
4. WHM/operator-only listings and operations retain explicit permissions. In particular, `/resellers` requires `roles.manage`.
5. Customer users remain constrained to accounts assigned through the existing `account_users` relationship and permissions.
6. All provisioning/privileged operations continue through allowlisted `paneld` tasks; no web request performs privileged Linux work directly.

## Verification

Focused tests in the active panel suite:

- `tests/Feature/ModuleCatalogTest.php` — catalog mode/audience and progress invariants.
- `tests/Feature/DashboardShellTest.php` — customer, mail-only, WHM and reseller navigation; reseller data isolation.
- `tests/Feature/AccountsTest.php` — reseller account scoping, package selection and cross-reseller denial.
- `tests/Feature/PackagesTest.php` — global/owned visibility and cross-reseller edit/archive denial.

The complete v0.76.0 panel test matrix covered all 77 PHPUnit files: **481 pass, 0 fail, 6 wasm-skip**, using PHP 8.5 through php-wasm and four isolated workers. The local host does not provide system PHP. The six skips are five `PendingCommand` tests and one child-php config test; browser/server acceptance remains separate. See `START-HERE.md` for the harness details.

## Remaining work

- Complete and test reseller ACLs (account/resource caps, allowed feature lists, package policy, nested-reseller rules) and any role-management UX.
- Decide and implement white-label branding controls separately from this fixed role palette.
- Add favorites/reordering and user-selected theme/dark-mode behavior if required by the product plan.
- Audit every reseller-sensitive endpoint, including APIs and asynchronous tasks, against the same ownership model.
- Continue the feature-parity checklist without deleting or collapsing its existing rows.
- Do browser-level accessibility/responsive review and secure production acceptance before any deployment.
