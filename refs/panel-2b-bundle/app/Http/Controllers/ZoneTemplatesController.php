<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DnsTemplate;
use App\Support\Audit;
use App\Support\Dns;
use App\Support\DnsProvisioner;
use App\Support\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** WHM Edit Zone Templates — JSON via paneld dns.templates. No BIND rewrite. */
class ZoneTemplatesController extends Controller
{
    public function index(Request $request): View
    {
        $this->requireWhm($request);

        return view('zone-templates.index', [
            'rows' => DnsTemplate::query()->orderBy('id')->get(),
            'panelMode' => 'whm',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireWhm($request);
        if (DnsTemplate::query()->count() >= 10) {
            return back()->withErrors(['name' => 'Zone template limit reached (10).']);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:32'],
            'body' => ['required', 'string', 'max:2000'],
        ]);
        $name = Dns::tryTemplateName($data['name']);
        $body = Dns::tryTemplateBody($data['body']);
        if ($name === null || $body === null) {
            return back()->withErrors(['name' => 'Invalid template. Name a-z0-9-. Body letters/numbers/%._:@ space newline. No pipe/path.'])->withInput();
        }
        if (DnsTemplate::query()->where('name', $name)->exists()) {
            return back()->withErrors(['name' => 'This template name already exists.'])->withInput();
        }
        DnsTemplate::query()->create(['name' => $name, 'body' => $body]);
        DnsProvisioner::enqueueTemplates();
        Audit::log('dns.templates.add', 'info', 'system', null, ['name' => $name]);

        return redirect()->route('zone-templates.index')->with('success', 'Zone template is queued.');
    }

    public function destroy(Request $request, DnsTemplate $dns_template): RedirectResponse
    {
        $this->requireWhm($request);
        $name = $dns_template->name;
        $dns_template->delete();
        DnsProvisioner::enqueueTemplates();
        Audit::log('dns.templates.remove', 'warning', 'system', null, ['name' => $name]);

        return redirect()->route('zone-templates.index')->with('success', 'Zone template is queued for removal.');
    }

    private function requireWhm(Request $request): void
    {
        if (ModuleCatalog::modeFor($request->user()) !== 'whm') {
            abort(403, 'Zone templates are a WHM tool.');
        }
    }
}
