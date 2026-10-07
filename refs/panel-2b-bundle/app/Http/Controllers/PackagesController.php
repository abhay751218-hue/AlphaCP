<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FeatureList;
use App\Models\Package;
use App\Models\User;
use App\Support\Audit;
use App\Support\PackageLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** WHM-style packages + feature lists (cPanel-compatible limit keys). */
class PackagesController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $this->assertCanView($user);

        $packages = Package::query()
            ->with('featureList')
            ->withCount(['accounts' => function ($query) use ($user): void {
                if (! $user->isRoot()) {
                    $query->where('reseller_id', $user->id);
                }
            }])
            ->when(! $user->isRoot(), fn ($query) => $query->where(
                fn ($visible) => $visible->whereNull('owner_id')->orWhere('owner_id', $user->id)
            ))
            ->orderBy('name')
            ->get();

        return view('packages.index', [
            'packages' => $packages,
            'lists' => FeatureList::query()->orderBy('name')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->assertCanManage($request->user());

        return view('packages.edit', [
            'package' => new Package(PackageLimits::DEFAULTS + [
                'status' => 'active',
                'owner_id' => $request->user()->isRoot() ? null : $request->user()->id,
            ]),
            'lists' => FeatureList::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $this->assertCanManage($user);
        $data = $this->validated($request);
        $data['owner_id'] = $user->isRoot() ? null : $user->id;
        if (! $user->isRoot()) {
            $data['is_default'] = false;
        }

        $package = Package::query()->create($data);
        $this->ensureSingleDefault($package);
        Audit::log('package.create', 'warning', 'package', $package->id, ['name' => $package->name]);

        return redirect()->route('packages.index')->with('success', "Package '{$package->name}' created.");
    }

    public function edit(Request $request, Package $package): View
    {
        $this->assertCanManage($request->user());
        $this->assertPackageScope($package, $request->user());

        return view('packages.edit', [
            'package' => $package,
            'lists' => FeatureList::query()->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Package $package): RedirectResponse
    {
        $this->assertCanManage($request->user());
        $this->assertPackageScope($package, $request->user());

        $data = $this->validated($request, $package->id);
        if (! $request->user()->isRoot()) {
            $data['is_default'] = false;
        }
        $package->update($data);
        $this->ensureSingleDefault($package);
        Audit::log('package.update', 'warning', 'package', $package->id, ['name' => $package->name]);

        return redirect()->route('packages.index')->with('success', "Package '{$package->name}' updated.");
    }

    public function archive(Request $request, Package $package): RedirectResponse
    {
        $this->assertCanManage($request->user());
        $this->assertPackageScope($package, $request->user());

        if ($package->is_default) {
            return back()->withErrors(['status' => 'The default package cannot be archived.']);
        }
        if ($package->accounts()->whereNotIn('status', ['terminated'])->exists()) {
            return back()->withErrors(['status' => 'This package has live accounts — upgrade them first.']);
        }
        $package->update(['status' => 'archived']);
        Audit::log('package.archive', 'warning', 'package', $package->id, ['name' => $package->name]);

        return redirect()->route('packages.index')->with('success', "Package '{$package->name}' archived.");
    }

    private function assertCanView(User $user): void
    {
        abort_unless($user->isRoot() || $user->role?->name === 'reseller', 403);
    }

    private function assertCanManage(User $user): void
    {
        abort_unless($user->isRoot() || $user->role?->name === 'reseller', 403);
    }

    private function assertPackageScope(Package $package, User $user): void
    {
        abort_unless(
            $user->isRoot() || ($user->role?->name === 'reseller' && (int) $package->owner_id === (int) $user->id),
            404,
        );
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
