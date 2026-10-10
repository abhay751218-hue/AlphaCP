<?php

declare(strict_types=1);

namespace App\Support\License;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Offline-first license client for the panel.
 *
 * The client only changes panel state. It never stops customer websites, mail,
 * DNS or backups. A signed payload is verified locally when a public key is
 * configured; the local trial is deliberately usable without the license API
 * so a fresh install is not bricked by an unavailable license server.
 */
final class LicenseClient
{
    private const PRODUCT = 'alphacp';
    private const TRIAL_DAYS = 15;

    /** @return array<string, mixed> */
    public function ensureTrial(): array
    {
        $record = $this->readRecord();
        if ($record !== null) {
            return $this->statusFromRecord($record);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expires = $now->modify('+' . self::TRIAL_DAYS . ' days');
        $payload = [
            'license_uid' => 'TRIAL-' . strtoupper(substr($this->fingerprint(), 0, 16)),
            'product' => self::PRODUCT,
            'tier' => 'trial',
            'features' => ['core'],
            'max_accounts' => 20,
            'max_servers' => 1,
            'issued_at' => $now->format(DATE_ATOM),
            'expires_at' => $expires->format(DATE_ATOM),
            'grace_days' => 0,
            'bindings' => ['fingerprint'],
        ];

        $record = [
            'source' => 'local_trial',
            'fingerprint' => $this->fingerprint(),
            'payload' => $payload,
            'signature' => null,
            'stored_at' => $now->format(DATE_ATOM),
        ];
        try {
            $this->writeRecord($record);
        } catch (\Throwable $exception) {
            // A read-only store must not turn the license page into HTTP 500.
            // The trial remains a panel-only degraded state until storage is fixed.
            report($exception);
        }

        return $this->statusFromRecord($record);
    }

    /** Re-issue a fresh local trial (+$days), overwriting any existing record.
     *  Owner-server keep-alive: local_trial is offline-valid (fingerprint-bound). */
    public function renewTrial(int $days = 15): array
    {
        $now     = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expires = $now->modify('+' . max(1, $days) . ' days');
        $payload = [
            'license_uid'  => 'TRIAL-' . strtoupper(substr($this->fingerprint(), 0, 16)),
            'product'      => self::PRODUCT,
            'tier'         => 'trial',
            'features'     => ['core'],
            'max_accounts' => 20,
            'max_servers'  => 1,
            'issued_at'    => $now->format(DATE_ATOM),
            'expires_at'   => $expires->format(DATE_ATOM),
            'grace_days'   => 0,
            'bindings'     => ['fingerprint'],
        ];
        $record = [
            'source'      => 'local_trial',
            'fingerprint' => $this->fingerprint(),
            'payload'     => $payload,
            'signature'   => null,
            'stored_at'   => $now->format(DATE_ATOM),
        ];
        $this->writeRecord($record);

        return $this->statusFromRecord($record);
    }

    /**
     * Owner-server lifetime + unlimited license — fingerprint-bound offline
     * record (source `owner_local`). Owner ka apna server kabhi trial/cap me
     * nahi phasna chahiye; customer keys alag se license-server se issue hoti hain.
     *
     * @return array<string, mixed>
     */
    public function installOwnerLicense(): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $payload = [
            'license_uid'  => 'OWNER-' . strtoupper(substr($this->fingerprint(), 0, 12)),
            'product'      => self::PRODUCT,
            'tier'         => 'owner',
            'features'     => ['core'],
            'max_accounts' => -1,
            'max_servers'  => 1,
            'issued_at'    => $now->format(DATE_ATOM),
            'expires_at'   => null,
            'grace_days'   => 0,
            'bindings'     => ['fingerprint'],
        ];

        $record = [
            'source'      => 'owner_local',
            'fingerprint' => $this->fingerprint(),
            'payload'     => $payload,
            'signature'   => null,
            'stored_at'   => $now->format(DATE_ATOM),
        ];
        $this->writeRecord($record);

        return $this->statusFromRecord($record);
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $record = $this->readRecord();
        return $record === null
            ? $this->emptyStatus()
            : $this->statusFromRecord($record);
    }

    /**
     * Activate a key with the central license API.
     *
     * @return array<string, mixed>
     */
    public function activate(string $licenseKey): array
    {
        $licenseKey = trim($licenseKey);
        if ($licenseKey === '' || strlen($licenseKey) > 160) {
            return $this->operationFailure('License key required hai.');
        }

        $api = rtrim((string) config('acp.license.api_url', ''), '/');
        // URL chahe base ho (https://host:2083) ya /api/v1 ke saath — dono chalenge.
        $api = preg_replace('#/api/v1$#', '', $api) ?? $api;
        if ($api === '') {
            return $this->operationFailure('License server is not configured yet. Local trial stays active.');
        }

        try {
            $http = Http::acceptJson()->timeout((int) config('acp.license.timeout', 8));
            if ((bool) config('acp.license.insecure', false)) {
                // Self-signed license server (apna hi master panel) — TLS verify off,
                // security signature-verify se aati hai (Ed25519 public key pinned).
                $http = $http->withoutVerifying();
            }
            $response = $http->post($api . '/api/v1/activate', [
                    'license_key' => $licenseKey,
                    'fingerprint' => $this->fingerprint(),
                    'hostname' => gethostname() ?: 'unknown',
                    'panel_version' => (string) config('acp.version', '0.0.0'),
                ]);
        } catch (\Throwable $exception) {
            report($exception);
            return $this->operationFailure('License server is not reachable. Trial/website services stay up.');
        }

        if (! $response->successful()) {
            return $this->operationFailure($this->responseMessage($response, 'License activation was rejected.'));
        }

        $body = $response->json();
        $payload = is_array($body) && isset($body['payload']) && is_array($body['payload'])
            ? $body['payload']
            : null;
        $signature = is_array($body) && is_string($body['signature'] ?? null) ? $body['signature'] : '';

        if ($payload === null || ! $this->verifyPayload($payload, $signature)) {
            return $this->operationFailure('License response signature did not verify.');
        }

        $record = [
            'source' => 'license_server',
            'fingerprint' => $this->fingerprint(),
            'payload' => $payload,
            'signature' => $signature,
            'stored_at' => gmdate(DATE_ATOM),
        ];
        try {
            $this->writeRecord($record);
        } catch (\Throwable $exception) {
            report($exception);
            return $this->operationFailure('License verified, but the local store did not save.');
        }

        return [
            'ok' => true,
            'message' => 'License activated.',
            'status' => $this->statusFromRecord($record),
        ];
    }

    /**
     * Verify an Ed25519 signature over canonical JSON without contacting the API.
     *
     * @param array<string, mixed> $payload
     */
    public function verifyPayload(array $payload, string $signature): bool
    {
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }

        $publicKey = $this->publicKeyBytes();
        $signatureBytes = base64_decode($signature, true);
        if ($publicKey === null || $signatureBytes === false || strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached(
                $signatureBytes,
                self::canonicalPayload($payload),
                $publicKey,
            );
        } catch (\Throwable $exception) {
            report($exception);
            return false;
        }
    }

    /**
     * Canonical JSON for the signed payload. License payloads contain only
     * objects, lists, strings, integers, booleans and nulls.
     *
     * @param array<string, mixed> $payload
     */
    public static function canonicalPayload(array $payload): string
    {
        return json_encode(
            self::sortObject($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    public function fingerprint(): string
    {
        $material = implode("\n", array_filter([
            trim((string) @file_get_contents('/etc/machine-id')),
            $this->macAddresses(),
            $this->cpuModel(),
        ]));
        $material = $material !== '' ? $material : 'alphacp-unknown-hardware';

        $appKey = (string) config('app.key', 'alphacp-development-key');
        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            $appKey = $decoded !== false ? $decoded : $appKey;
        }

        return hash_hmac('sha256', $material, $appKey);
    }

    /** @return array<string, mixed> */
    private function statusFromRecord(array $record): array
    {
        $payload = $record['payload'] ?? null;
        if (! is_array($payload) || ($payload['product'] ?? null) !== self::PRODUCT) {
            return $this->invalidStatus('License payload invalid hai.');
        }

        $boundFingerprint = (string) ($record['fingerprint'] ?? '');
        $bindings = is_array($payload['bindings'] ?? null) ? $payload['bindings'] : [];
        if (in_array('fingerprint', $bindings, true) && ! hash_equals($boundFingerprint, $this->fingerprint())) {
            return $this->invalidStatus('License kisi doosre server se bind hai.');
        }

        $signature = $record['signature'] ?? null;
        // local_trial + owner_local: offline-valid, fingerprint-bound records
        // (owner ka apna server — signature ki zaroorat nahi).
        $localSource = in_array($record['source'] ?? '', ['local_trial', 'owner_local'], true);
        if (! $localSource && (! is_string($signature) || ! $this->verifyPayload($payload, $signature))) {
            return $this->invalidStatus('License signature invalid hai.');
        }

        $now     = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $isTrial = ($payload['tier'] ?? '') === 'trial';

        $common = fn (array $extra): array => array_merge([
            'license_uid'  => (string) ($payload['license_uid'] ?? ''),
            'tier'         => (string) ($payload['tier'] ?? ''),
            'features'     => is_array($payload['features'] ?? null) ? $payload['features'] : [],
            'max_accounts' => isset($payload['max_accounts']) ? (int) $payload['max_accounts'] : null,
            'issued_at'    => (string) ($payload['issued_at'] ?? ''),
            'source'       => $localSource ? 'local' : 'license_server',
            'fingerprint'  => substr($this->fingerprint(), 0, 16) . '…',
        ], $extra);

        // Lifetime: expires_at null/empty = koi expiry nahi (owner tier).
        $rawExpiry = $payload['expires_at'] ?? null;
        if ($rawExpiry === null || (is_string($rawExpiry) && trim($rawExpiry) === '')) {
            return $common([
                'state'       => 'active',
                'label'       => 'LIFETIME',
                'message'     => 'Lifetime license — koi expiry nahi, accounts unlimited.',
                'expires_at'  => 'lifetime',
                'grace_until' => null,
                'days_left'   => null,
            ]);
        }

        $expiresAt = (string) $rawExpiry;
        try {
            $expires = new DateTimeImmutable($expiresAt, new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return $this->invalidStatus('License expiry date invalid hai.');
        }

        $graceDays  = max(0, (int) ($payload['grace_days'] ?? 0));
        $graceUntil = $expires->modify('+' . $graceDays . ' days');
        $daysLeft   = (int) $now->diff($expires)->format('%r%a');

        if ($now <= $expires) {
            $state = $isTrial ? 'trial' : ($daysLeft <= 15 ? 'notice' : 'active');
            $message = $isTrial
                ? 'Trial active hai. License server connect karke paid key activate kar sakte hain.'
                : ($daysLeft <= 15 ? 'License expiry nazdeek hai.' : 'License active hai.');
        } elseif ($now <= $graceUntil) {
            $state = 'grace';
            $message = 'License grace period me hai; panel me naye privileged actions limited ho sakte hain.';
        } else {
            $state = 'locked';
            $message = 'Renew the license. Customer websites, email, DNS and backups stay up.';
        }

        return $common([
            'state'       => $state,
            'label'       => strtoupper($state),
            'message'     => $message,
            'expires_at'  => $expires->format(DATE_ATOM),
            'grace_until' => $graceUntil->format(DATE_ATOM),
            'days_left'   => $daysLeft,
        ]);
    }

    /** @return array<string, mixed> */
    private function emptyStatus(): array
    {
        return [
            'state' => 'uninitialized',
            'label' => 'UNINITIALIZED',
            'message' => 'License/trial is not initialized yet.',
            'license_uid' => '',
            'tier' => '',
            'features' => [],
            'max_accounts' => null,
            'issued_at' => '',
            'expires_at' => '',
            'grace_until' => '',
            'days_left' => null,
            'source' => 'none',
            'fingerprint' => substr($this->fingerprint(), 0, 16) . '…',
        ];
    }

    /** @return array<string, mixed> */
    private function invalidStatus(string $message): array
    {
        $status = $this->emptyStatus();
        $status['state'] = 'invalid';
        $status['label'] = 'INVALID';
        $status['message'] = $message;
        return $status;
    }

    /** @return array<string, mixed> */
    private function operationFailure(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'status' => $this->status()];
    }

    /** @return array<string, mixed>|null */
    private function readRecord(): ?array
    {
        $path = $this->storePath();
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $record */
    private function writeRecord(array $record): void
    {
        $path = $this->storePath();
        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('License store directory was not created.');
        }

        $temporary = $directory . '/.license-' . bin2hex(random_bytes(8)) . '.tmp';
        $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (@file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('License store write failed.');
        }
        @chmod($temporary, 0600);
        if (! @rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('License store atomic replace fail hua.');
        }
        @chmod($path, 0600);
    }

    private function storePath(): string
    {
        return (string) config('acp.license.store_path', storage_path('app/private/license.json'));
    }

    private function publicKeyBytes(): ?string
    {
        $pem = (string) config('acp.license.public_key', '');
        $path = (string) config('acp.license.public_key_path', '');
        if ($pem === '' && $path !== '' && is_file($path)) {
            $pem = (string) @file_get_contents($path);
        }
        if ($pem === '') {
            return null;
        }

        $body = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $pem);
        if (! is_string($body)) {
            return null;
        }
        $decoded = base64_decode($body, true);
        if ($decoded === false) {
            return null;
        }
        if (strlen($decoded) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return $decoded;
        }

        // Most tools export Ed25519 keys as SubjectPublicKeyInfo PEM. Its
        // fixed DER prefix wraps the 32-byte raw key; accept both formats.
        $spkiPrefix = hex2bin('302a300506032b6570032100');
        if ($spkiPrefix !== false && str_starts_with($decoded, $spkiPrefix)
            && strlen($decoded) === strlen($spkiPrefix) + SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return substr($decoded, strlen($spkiPrefix));
        }

        return null;
    }

    private function macAddresses(): string
    {
        $addresses = [];
        foreach (glob('/sys/class/net/*/address') ?: [] as $path) {
            $address = strtolower(trim((string) @file_get_contents($path)));
            if ($address !== '' && $address !== '00:00:00:00:00:00') {
                $addresses[] = $address;
            }
        }
        sort($addresses);
        return implode(',', $addresses);
    }

    private function cpuModel(): string
    {
        $cpuInfo = (string) @file_get_contents('/proc/cpuinfo');
        if (preg_match('/^(?:model name|Hardware)\s*:\s*(.+)$/mi', $cpuInfo, $match) === 1) {
            return trim($match[1]);
        }
        return '';
    }

    /** @return array<string, mixed> */
    private static function sortObject(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(static function (mixed $item): mixed {
                return is_array($item) ? self::sortObject($item) : $item;
            }, $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortObject($item);
            }
        }
        return $value;
    }

    private function responseMessage(Response $response, string $fallback): string
    {
        $message = $response->json('message');
        return is_string($message) && $message !== '' ? $message : $fallback;
    }
}
