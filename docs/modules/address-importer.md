# Module: Address Importer

- **Step:** S7
- **Status:** 🟡 local candidate in panel **0.76.0**; live acceptance is still pending.
- **Permissions:** `email.view` / `email.manage` — customer + mail roles; customer cPanel only, not WHM.

## Purpose

Bulk-create virtual mailboxes using the existing `mail.set` provisioning path. The importer accepts
pasted CSV or a `.csv`/`.txt` upload; a request must use one input method, not both.

## CSV

`local,domain,password[,quota_mb]` or `email,password`.

- Pasted content is capped at 32,000 bytes; uploaded files are capped at 31 KiB.
- Domains must belong to the account; shell/pipe syntax, invalid rows, normalized duplicates within
the file, and addresses that already exist are rejected.
- `MAXPOP` is checked before queuing. Passwords are bcrypt-hashed and never placed in the task
payload as plaintext.
- Mailbox rows are inserted in one database transaction. Hashing or a database conflict cannot
leave a partially imported batch.
- Invalid requests do not flash form input to the session, and the view never repopulates CSV text,
so a password is not retained in `_old_input` or rendered back after an error.

## Verification and remaining live work

The final panel **0.76.0** candidate passed the complete importer feature file: **10 tests / 55
assertions**. The full panel suite passed **456 / 0 / 6 wasm-skip** before the final added view
regression test; the final 10-test importer file was rerun against the rebuilt 0.76.0 artifact.
These are local tests, not live verification. The S7 row remains 🟡 until the deployed agent/Exim/
Dovecot path and end-to-end mailbox authentication/delivery are verified on the target server.
No live-server command is published for this item yet.
