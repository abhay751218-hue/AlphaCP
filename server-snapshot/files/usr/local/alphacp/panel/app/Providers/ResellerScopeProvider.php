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
 *     users jo usne khud banaye (+ khud) dikhte hain.
 *  3. Reseller apne barabar ya upar wala role (reseller/root) create
 *     nahi kar sakta — sirf user/mail jaise niche wale roles.
 *
 * Root par koi asar nahi (isRoot bypass). CLI (actor null) par bhi nahi —
 * isliye demo/seeder commands normal chalti hain.
 *
 * ---------------------------------------------------------------------------
 * v2 — FATAL RECURSION FIX (login-fix v1.0)
 * ---------------------------------------------------------------------------
 * v1 har global scope me seedha `Auth::user()` call karta tha. Laravel ka
 * SessionGuard::user() apna `$this->user` **retrieveById() return hone ke BAAD**
 * set karta hai, aur EloquentUserProvider::retrieveById() `newQuery()` se query
 * banata hai — matlab GLOBAL SCOPES ke saath. Isliye:
 *
 *   GET /dashboard
 *     -> Auth::user()                    (guard: user abhi null)
 *     -> SessionGuard::user()
 *     -> EloquentUserProvider::retrieveById($id)
 *     -> User query -> global scope
 *     -> Auth::user()  <-- guard ka user ABHI BHI null
 *     -> retrieveById() ... INFINITE LOOP
 *
 * Nateeja: login POST to 302 de deta tha, par uske baad ka pehla hi
 * authenticated request PHP fatal (memory/nesting) -> HTTP 500. Browser me
 * yahi "login nahi ho raha, error aa raha" dikhta tha. Panel ke tests isko
 * pakad nahi paate the kyunki `actingAs()` guard par user SEEDHA set kar deta
 * hai (retrieveById chalta hi nahi). Reproduce: tools/sim/login-entry-sim.sh P9.
 *
 * Fix: `actor()` ek recursion-breaker flag ke saath actor nikaalta hai. Auth khud
 * user load kar raha ho tab scope CHUP rehta hai (filter nahi lagta) — jo zaroori
 * bhi hai, warna session-auth ki query hi scope me phas jaati. Guard ek baar user
 * cache kar leta hai, uske baad har query par sahi scope lagta hai.
 * `finally` ki wajah se flag kabhi stuck nahi hota (exception par bhi reset).
 */
final class ResellerScopeProvider extends ServiceProvider
{
    /** Recursion breaker — sirf auth ke apne retrieveById() call ke dauran true. */
    private static bool $resolvingActor = false;

    public function boot(): void
    {
        Account::addGlobalScope('reseller_scope', function ($query): void {
            $user = self::actor();

            if ($user !== null && ! $user->isRoot() && $user->hasPermission('accounts.view')) {
                $query->where('reseller_id', $user->id);
            }
        });

        User::addGlobalScope('reseller_scope', function ($query): void {
            $user = self::actor();

            if ($user !== null && ! $user->isRoot() && $user->hasPermission('users.view')) {
                $query->where(function ($q) use ($user): void {
                    $q->where('users.id', $user->id)
                        ->orWhere('users.created_by', $user->id)
                        ->orWhereHas('hostingAccount', fn ($s) => $s->where('reseller_id', $user->id));
                });
            }
        });

        User::creating(function (User $new): void {
            $actor = self::actor();

            if ($actor === null || $actor->isRoot()) {
                return;
            }

            $role       = Role::query()->find($new->role_id);
            $actorLevel = (int) ($actor->role?->level ?? 1);

            if ($role !== null && (int) $role->level <= $actorLevel) {
                abort(403, 'Aap apne barabar ya upar wala role create nahi kar sakte.');
            }
        });
    }

    /**
     * Request ka actor — recursion-safe. Auth khud user resolve kar raha ho to
     * null (scope us query par filter nahi lagayega).
     */
    private static function actor(): ?User
    {
        if (self::$resolvingActor) {
            return null;
        }

        self::$resolvingActor = true;

        try {
            $user = Auth::user();
        } catch (\Throwable) {
            $user = null;   // scoping kabhi request ko todta nahi
        } finally {
            self::$resolvingActor = false;
        }

        return $user instanceof User ? $user : null;
    }
}
