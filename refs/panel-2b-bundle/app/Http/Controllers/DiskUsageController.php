<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\Files;
use App\Support\ModuleCatalog;
use App\Support\Paneld;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** cPanel Disk Usage — folder sizes via paneld files.usage. */
class DiskUsageController extends Controller
{
    public function index(Request $request): View
    {
        $account = $this->accountFor($request);
        $path = Files::tryRel((string) $request->query('path', ''));
        if ($path === null) {
            $path = '';
        }
        $bytes = 0;
        $truncated = false;
        $entries = [];
        if ($account !== null && ! app()->environment('testing')) {
            $result = Paneld::run('files.usage', [
                'username' => $account->username,
                'path' => $path,
            ], 12);
            $bytes = (int) ($result['bytes'] ?? 0);
            $truncated = (bool) ($result['truncated'] ?? false);
            $entries = is_array($result['entries'] ?? null) ? $result['entries'] : [];
        }

        return view('disk.index', [
            'account' => $account,
            'path' => $path,
            'parent' => Files::parent($path),
            'bytes' => $bytes,
            'truncated' => $truncated,
            'entries' => $entries,
            'quotaMb' => (int) ($account?->quota_mb ?? 0),
            'panelMode' => ModuleCatalog::modeFor($request->user()),
        ]);
    }

    private function accountFor(Request $request): ?Account
    {
        if (ModuleCatalog::modeFor($request->user()) === 'whm') {
            return null;
        }

        return $request->user()->hostingAccount;
    }
}
