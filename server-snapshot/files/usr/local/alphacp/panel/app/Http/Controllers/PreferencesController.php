<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ModuleCatalog;
use App\Support\Theme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Preferences — cPanel ka "Preferences" section (docs/10-ui-parity-design.md §3).
 *
 * Pehla feature: **Change Style** — user apne panel ka look chun sakta hai
 * (jupiter / whm / webmail me se sirf wahi jo uski role ke mode me valid ho).
 * Choice session me rehti hai (DB column add karne se pehle — Phase P-UI-5 me
 * `users.theme` me persist karenge).
 */
class PreferencesController extends Controller
{
    /** POST /preferences/style */
    public function style(Request $request): RedirectResponse
    {
        $user = $request->user();
        $allowed = array_keys(Theme::allowedFor($user));

        $data = $request->validate([
            'theme' => ['required', 'string', 'in:' . implode(',', Theme::ALL)],
        ]);

        // Sirf apne mode ka theme (customer WHM look nahi le sakta).
        $mode = $user ? ModuleCatalog::modeFor($user) : 'cpanel';
        if (Theme::mode($data['theme']) !== $mode) {
            return back()->with('error', 'Ye style aapke panel ke liye available nahi hai.');
        }

        $request->session()->put('acp_theme', $data['theme']);

        return back()->with('success', 'Style badal gaya — ' . Theme::label($data['theme']));
    }
}
