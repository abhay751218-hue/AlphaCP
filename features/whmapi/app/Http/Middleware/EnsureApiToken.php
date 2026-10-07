<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Bearer-token auth for the WHM-compatible API. Resolves the owning user. */
final class EnsureApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = (string) $request->bearerToken();
        if ($bearer === '') {
            abort(401, 'API token required (Authorization: Bearer <token>).');
        }

        $token = ApiToken::query()->where('token_hash', hash('sha256', $bearer))->first();
        if ($token === null) {
            abort(401, 'Invalid API token.');
        }

        $token->forceFill(['last_used_at' => now()])->save();

        $user = $token->user;
        Auth::setUser($user);
        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }
}
