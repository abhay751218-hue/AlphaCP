<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FeatureList;
use App\Models\Package;
use App\Support\Audit;
use App\Support\PackageLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** WHM-style packages + feature lists (cPanel-compatible limit keys). */
class PackagesController extends Controller
{
    public function index(): View
    {
        return view('packages.index', [
            'packages' => Package::query()->with('featureList')->withCount('accounts')->orderBy('name')->get(),
            'lists' => FeatureList::query()->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('packages.edit', [
            'package' => new Package(PackageLimits::DEFAULTS + ['status' => 'active']),
            'lists' => FeatureList::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $package = Package::query()->create($data);
        $this->ensureSingleDefault($package);
        Audit::log('package.create', 'warning', 'package', $package->id, ['name' => $package->name]);

        return redirect()->route('packages.index')->with('success', "Package '{$package->name}' ban gaya.");
    }

    public function edit(Package $package): View
    {
        return view('packages.edit', [
            'package' => $package,
            'lists' => FeatureList::query()->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Package $package): RedirectResponse
    {
        $data = $this->validated($request, $package->id);
        $package->update($data);
        $this->ensureSingleDefault($package);
        Audit::log('package.update', 'warning', 'package', $package->id, ['name' => $package->name]);

        return redirect()->route('packages.index')->with('success', "Package '{$package->name}' update ho gaya.");
    }

    public function archive(Package $package): RedirectResponse
    {
        if ($package->is_default) {
            return back()->withErrors(['status' => 'Default package archive nahi hota.']);
        }
        if ($package->accounts()->whereNotIn('status', ['terminated'])->exists()) {
            return back()->withErrors(['status' => 'Is package par live accounts hain — pehle unhe upgrade karo.']);
        }
        $package->update(['status' => 'archived']);
        Audit::log('package.archive', 'warning', 'package', $package->id, ['name' => $package->name]);

        return redirect()->route('packages.index')->with('success', "Package '{$package->name}' archive ho gaya.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('packages', 'name')->ignore($ignoreId)],
            'description' => ['nullable', 'string', 'max:255'],
            'feature_list_id' => ['nullable', 'integer', Rule::exists('feature_lists', 'id')],
            'status' => ['required', Rule::in(['active', 'archived'])],
            'is_default' => ['sometimes', 'boolean'],
            'HASSHELL' => ['sometimes', 'boolean'],
            'DEDICATEDIP' => ['sometimes', 'boolean'],
            ...PackageLimits::rules(),
        ]);
        $data['is_default'] = $request->boolean('is_default');
        $data['HASSHELL'] = $request->boolean('HASSHELL');
        $data['DEDICATEDIP'] = $request->boolean('DEDICATEDIP');
        $data['description'] = $data['description'] ?? null;
        return $data;
    }

    private function ensureSingleDefault(Package $package): void
    {
        if (! $package->is_default) {
            return;
        }
        Package::query()->where('id', '!=', $package->id)->update(['is_default' => false]);
    }
}
