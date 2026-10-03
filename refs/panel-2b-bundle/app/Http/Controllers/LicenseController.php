<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Support\License\LicenseClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Admin license/trial status. License failures never stop customer services. */
final class LicenseController extends Controller
{
    public function index(LicenseClient $client): View
    {
        return view('license.index', [
            'status' => $client->ensureTrial(),
        ]);
    }

    public function activate(Request $request, LicenseClient $client): RedirectResponse
    {
        $data = $request->validate([
            'license_key' => ['required', 'string', 'max:160'],
        ]);

        $result = $client->activate($data['license_key']);
        if (($result['ok'] ?? false) === true) {
            Audit::log('license.activated', 'warning', 'license', null, [
                'state' => $result['status']['state'] ?? 'unknown',
            ]);
            return redirect()->route('license.index')->with('success', (string) $result['message']);
        }

        Audit::log('license.activation_failed', 'warning', 'license', null, [
            'message' => (string) ($result['message'] ?? 'unknown'),
        ]);
        return redirect()->route('license.index')->withErrors([
            'license_key' => (string) ($result['message'] ?? 'License was not activated.'),
        ]);
    }
}
