# License & Trial Module (S2C)

## Scope

The panel license client is offline-first and Ed25519-aware. It provides:

- A 15-day local trial on a fresh panel install.
- Stable machine fingerprinting from machine-id, non-zero MAC addresses and CPU model.
- Atomic, mode `0600` local license store under `storage/app/private/license.json` by default.
- Offline state evaluation: `trial`, `active`, `notice`, `grace`, `locked`, `invalid`.
- Optional activation against `ACP_LICENSE_API_URL/api/v1/activate`.
- Canonical JSON + Ed25519 signature verification when the license server public key is configured.
- Admin-only `/license` status page and activation form.

## Golden rule

License failures degrade only the panel. The client never stops customer websites, email, DNS,
backups or other services. This is enforced by the client design: it has no agent task and never
changes service/account state.

## Configuration

```dotenv
ACP_LICENSE_API_URL=https://license.example.invalid
ACP_LICENSE_TIMEOUT=8
ACP_LICENSE_STORE_PATH=/usr/local/alphacp/panel/storage/app/private/license.json
ACP_LICENSE_PUBLIC_KEY_PATH=/usr/local/alphacp/etc/license_public.pem
```

The public key is non-secret. The Ed25519 private key belongs only on the license server and must
never be committed or copied to a panel node.

## Routes and permissions

- `GET /license` → `license.view`
- `POST /license/activate` → `license.manage`, audited as `license.activated` or
  `license.activation_failed`

The permission catalog already contains both keys. Root bypasses permissions; reseller and client
roles do not receive license management by default.

## Tests

`tests/Unit/LicenseClientTest.php` covers local trial creation/reuse, canonical payload ordering,
and fingerprint shape. Before running the Laravel suite, clear any cached production config and
use the dedicated test database:

```bash
php artisan config:clear
php artisan test --filter=LicenseClientTest
```
