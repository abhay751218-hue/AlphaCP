<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SecurityExtra;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * cPanel Security extras — Hotlink Protection + Leech Protection.
 * Settings per-user store hoti hain (DB), config generation server-side.
 */
final class SecurityExtrasController extends Controller
{
    private const DEFAULTS = [
        'hotlink' => ['enabled' => false, 'allowed' => [], 'allow_direct' => true],
        'leech'   => ['enabled' => false, 'max_logins' => 4, 'action' => 'block'],
    ];

    public function hotlink(Request $request): View
    {
        return view('secextra.hotlink', ['s' => $this->get($request, 'hotlink')]);
    }

    public function leech(Request $request): View
    {
        return view('secextra.leech', ['s' => $this->get($request, 'leech')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $kind = (string) $request->input('kind', '');
        if (! array_key_exists($kind, self::DEFAULTS)) {
            return redirect('/hotlink-protection');
        }

        $data = $kind === 'hotlink'
            ? [
                'enabled'      => $request->boolean('enabled'),
                'allowed'      => array_values(array_filter(array_map('trim', explode("\n", (string) $request->input('allowed', ''))))),
                'allow_direct' => $request->boolean('allow_direct'),
            ]
            : [
                'enabled'    => $request->boolean('enabled'),
                'max_logins' => (int) min(20, max(1, (int) $request->input('max_logins', 4))),
                'action'     => in_array($request->input('action'), ['block', 'redirect'], true) ? $request->input('action') : 'block',
            ];

        SecurityExtra::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'kind' => $kind],
            ['data' => $data],
        );

        return redirect($kind === 'hotlink' ? '/hotlink-protection' : '/leech-protection');
    }

    private function get(Request $request, string $kind): array
    {
        $rec = SecurityExtra::query()
            ->where('user_id', $request->user()->id)
            ->where('kind', $kind)
            ->first();

        return array_merge(self::DEFAULTS[$kind], $rec?->data ?? []);
    }
}
