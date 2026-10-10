<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\LicenseKey;
use App\Support\LicenseSigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Sellable License Server — plan-based signed keys.
 *
 * Owner (aap) yahan se customer licenses nikalte ho:
 *   starter=10 accounts · pro=50 · business=200 · owner=UNLIMITED+lifetime
 * Customer panel /api/v1/activate se payload+signature lekar offline store
 * karta hai (Ed25519 verify; sodium na ho to hmac fallback = online-verified).
 */
final class LicenseServerController extends Controller
{
    /** Plan → account cap (-1 = unlimited). */
    public const PLAN_CAPS = ['starter' => 10, 'pro' => 50, 'business' => 200, 'owner' => -1];

    private function secret(): string
    {
        return (string) (config('acp.license_secret') ?: config('app.key'));
    }

    public function index(): View
    {
        return view('license-server.index', [
            'keys'     => LicenseKey::query()->orderByDesc('id')->get(),
            'newKey'   => session('new_key'),
            'caps'     => self::PLAN_CAPS,
            'publicPem' => LicenseSigner::publicPem(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'server_id' => 'required|string|max:120',
            'plan'      => 'required|string|in:starter,pro,business,owner',
            'days'      => 'required|integer|min:0|max:36500',
        ]);

        $plan = (string) $data['plan'];
        $days = (int) $data['days'];

        if ($days === 0 && $plan !== 'owner') {
            return back()->withErrors(['days' => 'Lifetime (0 din) sirf owner plan ke liye hai.'])->withInput();
        }

        if ($days > 3650 && $plan !== 'owner') {
            return back()->withErrors(['days' => 'Customer plans zyada se zyada 3650 din.'])->withInput();
        }

        $payload = [
            'license_uid'  => 'ACP-' . strtoupper(substr(hash('sha256', uniqid('', true)), 0, 12)),
            'product'      => 'alphacp',
            'tier'         => $plan,
            'features'     => ['core'],
            'max_accounts' => self::PLAN_CAPS[$plan],
            'max_servers'  => $plan === 'owner' ? 99 : 1,
            'issued_at'    => gmdate(DATE_ATOM),
            'expires_at'   => $days === 0 ? null : gmdate(DATE_ATOM, time() + $days * 86400),
            'grace_days'   => 7,
            'bindings'     => ['fingerprint'],
        ];

        $signed = LicenseSigner::issueSigned($payload, $this->secret());

        LicenseKey::query()->create([
            'server_id'  => $data['server_id'],
            'plan'       => $plan,
            'license_uid' => $payload['license_uid'],
            'expires_at' => $days === 0 ? null : date('Y-m-d H:i:s', time() + $days * 86400),
            'key_hash'   => hash('sha256', $payload['license_uid']),
            'payload'    => $payload,
            'signature'  => $signed['signature'],
            'sig_algo'   => $signed['algo'],
            'revoked'    => false,
        ]);

        return redirect('/license-server')->with('new_key', $payload['license_uid']);
    }

    public function destroy(LicenseKey $licenseKey): RedirectResponse
    {
        $licenseKey->update(['revoked' => true]);

        return redirect('/license-server');
    }

    /**
     * Customer panel ka activation endpoint (public).
     * Panel yahan se payload+signature lekar signed record store karta hai.
     */
    public function activate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'license_key'   => ['required', 'string', 'max:160'],
            'fingerprint'   => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,128}$/'],
            'hostname'      => ['nullable', 'string', 'max:160'],
            'panel_version' => ['nullable', 'string', 'max:40'],
        ]);

        $key = trim((string) $data['license_key']);
        $fp  = (string) $data['fingerprint'];

        $record = LicenseKey::query()->where('key_hash', hash('sha256', $key))->first();

        if ($record === null || $record->revoked) {
            return response()->json(['message' => 'License key invalid ya revoked hai.'], 422);
        }

        if ($record->expires_at !== null && $record->expires_at->isPast()) {
            return response()->json(['message' => 'License expire ho chuki hai — renew karo.'], 422);
        }

        // Fingerprint binding (anti key-sharing): pehli activation server se
        // bind hoti hai; doosre server par wahi key reject (owner plan chhod ke).
        $slot = trim((string) $record->server_id);
        $unbound = $slot === '' || in_array(strtolower($slot), ['auto', 'unbound', 'any'], true);

        if ($record->plan !== 'owner') {
            if ($unbound) {
                $record->update(['server_id' => $fp]);
            } elseif (! hash_equals($slot, $fp)) {
                \App\Support\Audit::log('license.activation_blocked', 'warning', 'license', null, [
                    'license_uid' => (string) $record->license_uid,
                    'reason'      => 'fingerprint_mismatch',
                ]);

                return response()->json([
                    'message' => 'Ye license kisi aur server par already active hai. Transfer ke liye support se contact karo.',
                ], 423);
            }
        }

        \App\Support\Audit::log('license.remote_activation', 'info', 'license', null, [
            'license_uid'   => (string) $record->license_uid,
            'hostname'      => (string) ($data['hostname'] ?? ''),
            'panel_version' => (string) ($data['panel_version'] ?? ''),
        ]);

        return response()->json([
            'payload'   => $record->payload,
            'signature' => (string) $record->signature,
        ]);
    }

    /** Customer panel ka online check (public, read-only). */
    public function verify(Request $request): JsonResponse
    {
        $key     = (string) $request->input('key', '');
        $payload = LicenseSigner::verify($key, $this->secret());

        $record = LicenseKey::query()->where('key_hash', hash('sha256', $key))->first();

        $valid = $payload !== null && ($record === null || ! $record->revoked);

        return response()->json([
            'valid'   => $valid,
            'payload' => $valid ? $payload : null,
            'revoked' => $record?->revoked ?? false,
        ]);
    }
}
