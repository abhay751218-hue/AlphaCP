#!/usr/bin/env bash
# ============================================================================
# AlphaCP — RESELLER SCOPING (WHM parity) installer  v1.0
# Base app me reseller ko SAB accounts/users dikhte the (cPanel me sirf apne).
# Ye installer ek additive ServiceProvider lagata hai jo:
#   * reseller ke accounts sirf uske apne karta hai (URL-binding samet),
#   * users list scoped karta hai (apne customers + khud),
#   * reseller ko reseller/root role banane se rokta hai.
# Root/CLI par zero asar. Base controllers untouched. Idempotent + backup.
# ============================================================================
set -euo pipefail
PANEL="${ACP_PANEL:-/usr/local/alphacp/panel}"
echo "=================================================="
echo " AlphaCP Reseller Scoping installer  v1.0"
echo "=================================================="

echo "== Step 1: provider file =="
mkdir -p "$PANEL/app/Providers"
if [[ -f "$PANEL/app/Providers/ResellerScopeProvider.php" && ! -f "$PANEL/app/Providers/ResellerScopeProvider.php.bak" ]]; then
  cp "$PANEL/app/Providers/ResellerScopeProvider.php" "$PANEL/app/Providers/ResellerScopeProvider.php.bak"
fi
cat > "$PANEL/app/Providers/ResellerScopeProvider.php" <<'ACP_FILE_EOF'
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
ACP_FILE_EOF
echo "  + app/Providers/ResellerScopeProvider.php"

echo "== Step 2: bootstrap/providers.php me register (idempotent) =="
ACP_PROVIDERS="$PANEL/bootstrap/providers.php" python3 - <<'PY'
import os, sys
path = os.environ['ACP_PROVIDERS']
src = open(path).read()
if 'ResellerScopeProvider' in src:
    print('[OK] provider pehle se registered')
    sys.exit(0)
if 'return [' not in src:
    print('[FAIL] bootstrap/providers.php ka format samajh nahi aaya')
    sys.exit(1)
src = src.replace('return [', 'return [\n    App\\Providers\\ResellerScopeProvider::class,', 1)
open(path, 'w').write(src)
print('[OK] ResellerScopeProvider registered')
PY

echo "== Step 3: autoload + caches =="
cd "$PANEL"
if command -v composer >/dev/null 2>&1; then
  composer dump-autoload -o || true
elif [[ -x /usr/local/bin/composer ]]; then
  /usr/local/bin/composer dump-autoload -o || true
else
  php /usr/local/alphacp/tools/composer.phar dump-autoload -o 2>/dev/null || echo "[WARN] composer nahi mila — agla deploy/update dump karega"
fi
php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true

echo "=================================================="
echo " ==> RESELLER SCOPING v1.0 APPLIED  (reseller ab sirf apne accounts dekhta hai)"
echo "=================================================="
alphacp-sync || true
