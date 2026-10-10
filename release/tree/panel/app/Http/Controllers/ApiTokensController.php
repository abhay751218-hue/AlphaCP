<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ApiToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** cPanel "Manage API Tokens" — panel UI se Bearer token generate/revoke (billing ke liye). */
final class ApiTokensController extends Controller
{
    public function index(Request $request): View
    {
        $tokens = ApiToken::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get();

        return view('api-tokens.index', [
            'tokens'   => $tokens,
            'newToken' => session('new_token'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);

        $plain = 'acp_' . Str::random(40);

        ApiToken::query()->create([
            'user_id'    => $request->user()->id,
            'name'       => $data['name'],
            'token_hash' => hash('sha256', $plain),
        ]);

        return redirect()->route('api-tokens.index')
            ->with('new_token', $plain)
            ->with('success', 'Token ban gaya — abhi copy karo, dobara NAHI dikhega.');
    }

    public function destroy(Request $request, ApiToken $apiToken): RedirectResponse
    {
        if ((int) $apiToken->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $apiToken->delete();

        return redirect()->route('api-tokens.index')->with('success', 'Token revoke ho gaya.');
    }
}
