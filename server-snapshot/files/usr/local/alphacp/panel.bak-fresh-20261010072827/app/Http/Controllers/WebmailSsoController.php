<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Panel-internal endpoint: Roundcube ka acp_sso plugin yahan one-time token
 * verify karta hai (shared secret /usr/local/alphacp/etc/webmail-sso.secret,
 * root:www-data 0640). Public internet se expose nahi (nginx internal location
 * sirf 127.0.0.1 se allow karta hai — webmail-fix installer gate).
 */
final class WebmailSsoController extends Controller
{
    public function verify(Request $request): JsonResponse
    {
        $secretFile = rtrim((string) config('acp.home'), '/') . '/etc/webmail-sso.secret';
        $secret = is_file($secretFile) ? trim((string) file_get_contents($secretFile)) : '';
        $given = (string) $request->header('X-ACP-Secret', '');
        if ($secret === '' || !hash_equals($secret, $given)) {
            return response()->json(['error' => 'bad secret'], 403);
        }

        $token = (string) $request->query('token', '');
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return response()->json(['error' => 'bad token'], 422);
        }

        $row = DB::table('webmail_sso_tokens')->where('token', $token)->first();
        if ($row === null || (int) $row->used === 1 || strtotime((string) $row->expires_at) < time()) {
            return response()->json(['error' => 'token invalid/expired'], 410);
        }
        DB::table('webmail_sso_tokens')->where('id', $row->id)->update(['used' => 1, 'updated_at' => now()]);

        return response()->json(['mailbox' => (string) $row->mailbox]);
    }
}
