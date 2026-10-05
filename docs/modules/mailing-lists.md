# Module: Mailing Lists

- **Step:** S7 #18
- **Status:** local implementation tested; **not live-verified**
- **Product shape:** static Exim distribution lists, not a Mailman service

## Behavior

Customers can create a list address on an account-owned domain, set an administrative owner email, and manage 1–200 subscriber addresses. Each message addressed to the list is expanded by Exim to the stored subscriber addresses. The owner is a contact only; include the owner in the subscriber set if they should receive posts. Members can be local or external email addresses. Duplicate addresses are normalized and removed.

The feature does not provide Mailman moderation queues, subscription confirmation/self-service, bounce processing, or public archives. Do not describe it as full Mailman parity.

## Storage and task contract

- Panel membership is stored in `mailing_lists.members` as nullable JSON; the nullable migration keeps existing rows upgrade-safe.
- The provisioner sends the full list set through the existing `mail.list` agent task.
- The agent persists normalized rows in `~/etc/mail/lists.json` and regenerates the managed Exim alias map at `/etc/exim4/alphacp-aliases`.

| Task | Payload |
|---|---|
| `mail.list` | `{username, lists:[{local, domain, owner, members:[email, ...]}]}` |

Validation is fail-closed for invalid owner/member addresses, pipes/shell syntax, empty or over-cap membership, self-subscription, and list addresses already used by a mailbox or forwarder. The agent also omits mailbox/forwarder conflicts and nested-list targets during sync, and reports `list_errors`; a list route is not published in those cases. Lists are capped at 50 per account (`MAXLST`); subscriber membership is capped at 200 per list.

For legacy task rows without `members`, the owner becomes the initial subscriber so existing lists remain deliverable after upgrade.

## UI and permissions

- Route: `/mailing-lists`
- Permissions: `email.view` to view, `email.manage` to create/update/remove; customer and mail-role tile, not WHM.
- Subscriber create/edit/delete operations are queued to the account agent; success messaging explicitly says the Exim change is queued.

## Verification record

- Agent candidate `agent-0.82.0.tar.gz` (SHA-256 `b3cb65c694d28f7d0361baac616ea47e1308c87826079238d3582a8e32142b77`): extracted-artifact suite **209 passed / 0 failed** (2 PHP-WASM platform skips).
- Mailing-list feature tests against `panel-code-0.75.0.tar.gz` (SHA-256 `53bcfb3b59add88bbad39df076b4e25a6f1164e0c0b8cc4331959b8780e5cc2c`): **8 passed / 0 failed**; full panel suite: **453 passed / 0 failed / 6 wasm-skip**.
- `tools/sim/s7-mail-sim.sh`: **14 passed / 0 failed**, including the list route, fan-out-to-subscriber Maildir, invalid-pipe/self-member rejection, cleanup, and expected break modes.
- Updater candidate **0.82.0** points to artifact commit `312f21cbc1a2cecde786f8ebbff6f9e814a25253`; both artifact SHA-256 pins match that commit. `sudo -n bash tools/sim/update-sim.sh`: **247 passed / 0 failed**.
- No real Exim installation is available in the development sandbox. The dedicated `tools/verify/s7-mailing-list-check.sh` has only been exercised against the simulator; it has **not** been run on the live server. Keep checklist #18 yellow until the deployed release reports a clean real-Exim route and subscriber delivery.
