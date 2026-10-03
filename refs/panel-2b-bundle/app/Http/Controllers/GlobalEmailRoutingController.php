<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GlobalEmailRoute;
use App\Support\Audit;
use App\Support\Mail;
use App\Support\MailProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** WHM Email Routing Configuration — JSON via paneld mail.globalrouting. No Exim rewrite. */
class GlobalEmailRoutingController extends Controller
{
    public function index(Request $request): View
    {
        $this->requireWhm($request);

        return view('global-email-routing.index', [
            'rows' => GlobalEmailRoute::query()->orderBy('id')->get(),
            'modes' => ['auto', 'local', 'backup', 'remote'],
            'panelMode' => 'whm',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:190'],
            'mode' => ['required', 'string', 'max:16'],
        ]);
        $domain = Mail::tryDomain($data['domain']);
        $mode = Mail::tryRoutingMode($data['mode']);
        if ($domain === null || $mode === null) {
            return back()->withErrors(['domain' => 'Invalid domain/mode. Domain FQDN. Mode auto/local/backup/remote. No pipe/path.'])->withInput();
        }
        $row = GlobalEmailRoute::query()->where('domain', $domain)->first();
        if ($row === null) {
            if (GlobalEmailRoute::query()->count() >= 50) {
                return back()->withErrors(['domain' => 'Global routing limit reached (50).']);
            }
            GlobalEmailRoute::query()->create(['domain' => $domain, 'mode' => $mode]);
        } else {
            $row->update(['mode' => $mode]);
        }
        MailProvisioner::enqueueGlobalRouting();
        Audit::log('mail.globalrouting', 'info', 'system', null, ['domain' => $domain, 'mode' => $mode]);

        return redirect()->route('global-email-routing.index')->with('success', 'Global email routing is queued.');
    }

    private function requireWhm(Request $request): void
    {
        if (ModuleCatalog::modeFor($request->user()) !== 'whm') {
            abort(403, 'Email Routing Configuration is a WHM tool.');
        }
    }
}
