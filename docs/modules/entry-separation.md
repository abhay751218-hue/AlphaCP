# Entry separation — implementation contract (2026-10-07)

Status: design + existing gate regression tested; **listeners not deployed**.
Owner explicitly requested cPanel-style separate service ports on 7 Oct.

Official reference reviewed:
https://docs.cpanel.net/knowledge-base/accounts/how-to-log-in-to-your-server-or-account/
(last modified 2026-07-24).

## Target services
| HTTPS port | Intended entry | Credentials / scope |
|---|---|---|
| 2087 | Server Manager / reseller | Panel username; server or owned-reseller permissions |
| 2083 | Account Panel | Hosting account credentials; account scope |
| 2096 | Webmail | **Email address + mailbox password**, mailbox-only scope |
| 8090 | Temporary recovery / current working panel | Preserve until new entries externally accepted |

Do not open plaintext counterparts 2086/2082/2095 by default.
Do not assign a separate reseller port: WHM uses the manager entry for resellers.

## Existing code — verified limits
`EntryLoginController` gates login POST using a root-produced truth file and allows single-entry
fallback when the alternate service is absent. Existing regression tests: **10 pass / 42 assertions**
in PHP 8.5 wasm. They do NOT establish full per-service authentication parity.

Current gaps (must resolve before claiming separation complete):
- 2083 and 2096 are combined into the same customer group; user/mail are not separate service identities.
- Login form uses panel username, not actual mailbox credentials on 2096.
- Same-host cookies are not isolated by TCP port. A POST-only entry gate cannot prevent an
  already-authenticated session from reaching another service. Need service-aware sessions and
  request authorization, including fresh browser requests and 2FA/logout flows.
- Existing truth-file collector intersects all nginx SSL listeners with bound ports, not an explicit
  verified panel-vhost identity. Do not let unrelated SSL services activate restrictions.
- Bound port != externally reachable entry; Lightsail/host firewall, TLS certificate, DNS and
  external login checks required before redirecting users or retiring 8090.
- Direct IP certificate warning observed in owner screenshots; port opening does not fix TLS trust.

## Safe execution order
1. Finish full panel-suite failure triage; preserve actual old login-fatal regression coverage.
2. Implement service identity/isolated session contract in a small tested change, no credential leakage.
3. Implement/test mailbox authentication independently; never substitute panel `mail` role for a mailbox.
4. Add nginx service templates and preview/simulation fixtures. Preserve 8090 and original configs.
5. Test nginx syntax, socket collisions, proxy-derived ports, absent listeners, failed reload and rollback.
6. Versioned commit-pinned checksum-verified installer; firewall additions separately approved/applied.
7. Owner browser checks each new HTTPS entry + role denials + fresh-session/2FA/logout tests.
8. Only then enable strict separation; retain recovery until acceptance is recorded.

No schema, new dependency, live port, auth policy or firewall change in this documentation step.

## Stage 1 rollout — manager-entry v0.1.0
`installer/manager-entry.sh` adds only IPv4 TLS 2087 to the already-loaded panel vhost.
8090, auth policy, PHP code, session settings, firewall and customer/Webmail ports are untouched.
Existing manager/customer truth-file cron remains unchanged. This is an additional entry,
**not strict service isolation**. Local curl uses `--insecure` solely for the existing self-signed
certificate health probe; it does not establish certificate trust or external reachability.

Preflight: loaded-vhost identity, nginx syntax, existing login health, port collision, root + lock.
Backup: root-private `/var/backups/alphacp-entry/manager-entry-v0.1.0.*`.
Failure after editing: restore original vhost, validate and reload; report explicitly if rollback reload fails.
End: attempt alphacp-sync. No full panel bundle deployed (its suite is still under review).

Tests: success + syntax failure + reload failure + new-port health failure + occupied-port scenarios
with simulated nginx/systemctl/ss/curl; 2 unittest methods cover 5 scenarios. Bash syntax pass.
Real nginx/TLS/firewall/browser acceptance must still be performed on owner server.
