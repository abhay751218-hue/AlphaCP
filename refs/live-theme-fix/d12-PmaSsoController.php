<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Panel-internal endpoint: phpMyAdmin ka acp-signon.php shim yahan one-time
 * token verify karta hai (shared secret /usr/local/alphacp/etc/pma-sso.secret,
 * root:www-data 0640). Public internet se expose nahi — nginx cpanel vhost ka
 * `location /internal/` sirf 127.0.0.1 allow karta hai (webmail-sso jaisa).
 */
final class PmaSsoController extends Controller
{
    public function verify(Request $request): JsonResponse
    {
        $secretFile = rtrim((string) config('acp.home'), '/') . '/etc/pma-sso.secret';
        $secret = is_file($secretFile) ? trim((string) file_get_contents($secretFile)) : '';
        $given = (string) $request->header('X-ACP-Secret', '');
        if ($secret === '' || !hash_equals($secret, $given)) {
            return response()->json(['error' => 'bad secret'], 403);
        }

        $token = (string) $request->query('token', '');
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return response()->json(['error' => 'bad token'], 422);
        }

        $path = 'pma-sso/' . $token . '.json';
        if (! Storage::exists($path)) {
            return response()->json(['error' => 'token invalid/expired'], 410);
        }
        $data = json_decode((string) Storage::get($path), true);
        Storage::delete($path); // one-time — turant jala do

        if (! is_array($data) || (int) ($data['expires'] ?? 0) < time()) {
            return response()->json(['error' => 'token invalid/expired'], 410);
        }

        try {
            $password = Crypt::decryptString((string) ($data['password'] ?? ''));
        } catch (\Throwable) {
            return response()->json(['error' => 'token corrupt'], 410);
        }

        return response()->json([
            'user'     => (string) ($data['user'] ?? ''),
            'password' => $password,
        ]);
    }
}
