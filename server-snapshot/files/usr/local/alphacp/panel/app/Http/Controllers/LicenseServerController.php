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
 * Sellable License Server — signed keys issue/verify/revoke.
 * Owner (aap) yahan se customer licenses nikalte ho; customer panel offline verify karta hai.
 */
final class LicenseServerController extends Controller
{
    private function secret(): string
    {
        return (string) (config('acp.license_secret') ?: config('app.key'));
    }

    public function index(): View
    {
        return view('license-server.index', [
            'keys'    => LicenseKey::query()->orderByDesc('id')->get(),
            'newKey'  => session('new_key'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'server_id' => 'required|string|max:120',
            'plan'      => 'required|string|in:starter,pro,business',
            'days'      => 'required|integer|min:1|max:3650',
        ]);

        $payload = [
            'sub'  => $data['server_id'],
            'plan' => $data['plan'],
            'iat'  => time(),
            'exp'  => time() + ((int) $data['days'] * 86400),
        ];

        $key = LicenseSigner::issue($payload, $this->secret());

        LicenseKey::query()->create([
            'server_id'  => $data['server_id'],
            'plan'       => $data['plan'],
            'expires_at' => date('Y-m-d H:i:s', $payload['exp']),
            'key_hash'   => hash('sha256', $key),
            'revoked'    => false,
        ]);

        return redirect('/license-server')->with('new_key', $key);
    }

    public function destroy(LicenseKey $licenseKey): RedirectResponse
    {
        $licenseKey->update(['revoked' => true]);

        return redirect('/license-server');
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
