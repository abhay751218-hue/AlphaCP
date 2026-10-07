<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Account;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

/**
 * WHM-reseller parity (cPanel jaisa scoping):
 *
 *  1. Reseller ko Accounts pages par SIRF apne accounts dikhte hain
 *     (route-model-binding samet — doosre ka account URL se bhi 404).
 *  2. Reseller ko Users pages par sirf apne accounts ke owner-users +
 *     users jo usne khud banaye (+ khud) dikhte hain. Self include zaroori
 *     hai warna session auth (retrieveById) global scope me phas jaata hai.
 *  3. Reseller apne barabar ya upar wala role (reseller/root) create
 *     nahi kar sakta — sirf user/mail jaise niche wale roles.
 *
 * Root par koi asar nahi (isRoot bypass). CLI (actor null) par bhi nahi —
 * isliye demo/seeder commands normal chalte hain.
 *
 * Additive install: sirf ye provider file + bootstrap/providers.php entry.
 * Base controllers ko haath nahi lagaya gaya.
 */
final class ResellerScopeProvider extends ServiceProvider
{
    public function boot(): void
    {
        Account::addGlobalScope('reseller_scope', function ($query): void {
            $user = Auth::user();

            if ($user instanceof User && ! $user->isRoot() && $user->hasPermission('accounts.view')) {
                $query->where('reseller_id', $user->id);
            }
        });

        User::addGlobalScope('reseller_scope', function ($query): void {
            $user = Auth::user();

            if ($user instanceof User && ! $user->isRoot() && $user->hasPermission('users.view')) {
                $query->where(function ($q) use ($user): void {
                    $q->where('users.id', $user->id)
                        ->orWhere('users.created_by', $user->id)
                        ->orWhereHas('hostingAccount', fn ($s) => $s->where('reseller_id', $user->id));
                });
            }
        });

        User::creating(function (User $new): void {
            $actor = Auth::user();

            if (! $actor instanceof User || $actor->isRoot()) {
                return;
            }

            $role       = Role::query()->find($new->role_id);
            $actorLevel = (int) ($actor->role?->level ?? 1);

            if ($role !== null && (int) $role->level <= $actorLevel) {
                abort(403, 'Aap apne barabar ya upar wala role create nahi kar sakte.');
            }
        });
    }
}
