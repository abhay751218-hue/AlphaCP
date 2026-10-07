#!/usr/bin/env bash
# AlphaCP — WHM API 1 (billing integration) portable installer  v1.0
set -euo pipefail
PANEL=/usr/local/alphacp/panel
echo "=================================================="
echo " AlphaCP WHM API installer  v1.0 (billing: WHMCS/Blesta-ready)"
echo "=================================================="
echo "== Step 1: panel feature files =="
mkdir -p "$PANEL/app/Http/Middleware" "$PANEL/app/Console/Commands"
cat > "$PANEL/database/migrations/2026_10_07_000003_create_api_tokens_table.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
ACP_FILE_EOF
echo "  + database/migrations/2026_10_07_000003_create_api_tokens_table.php"
cat > "$PANEL/app/Models/ApiToken.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bearer API token for the WHM-compatible API (billing integration). */
final class ApiToken extends Model
{
    protected $table = 'api_tokens';

    protected $fillable = ['user_id', 'name', 'token_hash', 'last_used_at'];

    protected $casts = ['last_used_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
ACP_FILE_EOF
echo "  + app/Models/ApiToken.php"
cat > "$PANEL/app/Http/Middleware/EnsureApiToken.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Bearer-token auth for the WHM-compatible API. Resolves the owning user. */
final class EnsureApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = (string) $request->bearerToken();
        if ($bearer === '') {
            abort(401, 'API token required (Authorization: Bearer <token>).');
        }

        $token = ApiToken::query()->where('token_hash', hash('sha256', $bearer))->first();
        if ($token === null) {
            abort(401, 'Invalid API token.');
        }

        $token->forceFill(['last_used_at' => now()])->save();

        $user = $token->user;
        Auth::setUser($user);
        $request->setUserResolver(static fn () => $user);

        return $next($request);
    }
}
ACP_FILE_EOF
echo "  + app/Http/Middleware/EnsureApiToken.php"
cat > "$PANEL/app/Console/Commands/ApiTokenCommand.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Issue a Bearer API token for the WHM-compatible API (billing integration). */
final class ApiTokenCommand extends Command
{
    protected $signature = 'alphacp:api:token {username : panel user (root/reseller) to bind the token to} {--name=billing}';

    protected $description = 'Create a WHM-API Bearer token (plain token printed ONCE — store it in your billing software).';

    public function handle(): int
    {
        $user = User::query()->where('username', (string) $this->argument('username'))->first();
        if ($user === null) {
            $this->error('User not found: ' . (string) $this->argument('username'));

            return self::FAILURE;
        }

        $plain = 'acp_' . Str::random(40);

        ApiToken::query()->create([
            'user_id'  => $user->id,
            'name'     => (string) $this->option('name'),
            'token_hash' => hash('sha256', $plain),
        ]);

        $this->info('API token (save now, shown once): ' . $plain);

        return self::SUCCESS;
    }
}
ACP_FILE_EOF
echo "  + app/Console/Commands/ApiTokenCommand.php"
cat > "$PANEL/app/Http/Controllers/WhmApiController.php" <<'ACP_FILE_EOF'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Package;
use App\Models\Role;
use App\Models\User;
use App\Support\AccountIdentity;
use App\Support\AccountProvisioner;
use App\Support\Audit;
use App\Support\DomainProvisioner;
use App\Support\Panel;
use App\Support\PasswordGenerator;
use App\Support\ShadowHash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * WHM API 1 compatible endpoints (for billing software like WHMCS/Blesta).
 * Shape mirrors cPanel: /json-api/<func>?user=... returns {"result":[{status:...}]}.
 */
final class WhmApiController extends Controller
{
    public function listaccts(Request $request): JsonResponse
    {
        $this->requireManage($request);

        $accts = Account::query()->orderBy('username')->get()->map(fn (Account $a) => [
            'user'   => $a->username,
            'domain' => $a->main_domain,
            'status' => $a->status,
        ]);

        return response()->json(['acct' => $accts]);
    }

    public function accountsummary(Request $request): JsonResponse
    {
        $this->requireManage($request);
        $account = $this->findAccount($request);
        if ($account === null) {
            return $this->fail('Account not found.');
        }

        return response()->json(['acct' => [
            'user'        => $account->username,
            'domain'      => $account->main_domain,
            'email'       => $account->contact_email,
            'status'      => $account->status,
            'home'        => $account->home_path,
            'quota_mb'    => $account->quota_mb,
            'php_version' => $account->php_version,
        ]]);
    }

    public function createacct(Request $request): JsonResponse
    {
        $actor = $this->requireManage($request);

        $username = strtolower((string) $request->input('username', ''));
        $domain   = strtolower((string) $request->input('domain', ''));
        $email    = (string) $request->input('email', 'admin@' . ($domain ?: 'example.com'));
        $pkgName  = (string) $request->input('pkg', 'default');

        if ($username === '' || ! preg_match(AccountIdentity::USERNAME_PATTERN, $username)) {
            return $this->fail('Invalid username.');
        }
        if ($domain === '') {
            return $this->fail('Domain required.');
        }
        if (Account::query()->where('username', $username)->orWhere('main_domain', $domain)->exists()) {
            return $this->fail('Username/domain already exists.');
        }

        $package = Package::query()->where('name', $pkgName)->first() ?? Package::query()->first();
        if ($package === null) {
            return $this->fail('No package available.');
        }

        $plain = PasswordGenerator::generate(20);
        $role  = Role::query()->where('name', 'user')->firstOrFail();
        $home  = rtrim((string) config('acp.paths.accounts', '/home'), '/') . '/' . $username;

        $account = DB::transaction(function () use ($actor, $username, $domain, $email, $package, $plain, $role, $home): Account {
            $owner = User::query()->create([
                'username'              => $username,
                'email'                 => $email,
                'password_hash'         => Hash::make($plain),
                'role_id'               => $role->id,
                'status'                => 'active',
                'force_password_change' => true,
                'created_by'            => $actor->id,
            ]);

            $account = Account::query()->create([
                'server_id'     => Panel::serverId(),
                'package_id'    => $package->id,
                'owner_user_id' => $owner->id,
                'username'      => $username,
                'main_domain'   => $domain,
                'contact_email' => $email,
                'home_path'     => $home,
                'php_version'   => '8.4',
                'quota_mb'      => $package->quotaMb(),
                'status'        => 'pending',
                'created_by'    => $actor->id,
            ]);

            DB::table('account_users')->insert([
                'account_id' => $account->id,
                'user_id'    => $owner->id,
                'role'       => 'owner',
                'created_at' => now(),
            ]);

            DomainProvisioner::seedMain($account);

            return $account;
        });

        AccountProvisioner::enqueue($account, 'account.create', [
            'username'    => $username,
            'domain'      => $domain,
            'shadow_hash' => ShadowHash::make($plain),
            'quota_mb'    => $package->quotaMb(),
            'php_version' => '8.4',
        ]);

        Audit::log('api.createacct', 'warning', 'account', $account->id, ['username' => $username]);

        return response()->json(['result' => [[
            'status'   => 1,
            'statusmsg' => 'Account queued (panel password: ' . $plain . ')',
            'username' => $username,
            'domain'   => $domain,
        ]]]);
    }

    public function suspendacct(Request $request): JsonResponse
    {
        return $this->setStatus($request, 'suspended', 'account.suspend', 'Account suspended.');
    }

    public function unsuspendacct(Request $request): JsonResponse
    {
        return $this->setStatus($request, 'active', 'account.unsuspend', 'Account unsuspended.');
    }

    public function removeacct(Request $request): JsonResponse
    {
        $this->requireManage($request);
        $account = $this->findAccount($request);
        if ($account === null) {
            return $this->fail('Account not found.');
        }

        AccountProvisioner::markTerminated($account);
        AccountProvisioner::enqueue($account, 'account.terminate', ['username' => $account->username]);
        Audit::log('api.removeacct', 'warning', 'account', $account->id, ['username' => $account->username]);

        return response()->json(['result' => [['status' => 1, 'statusmsg' => 'Account termination queued.']]]);
    }

    private function setStatus(Request $request, string $status, string $task, string $msg): JsonResponse
    {
        $this->requireManage($request);
        $account = $this->findAccount($request);
        if ($account === null) {
            return $this->fail('Account not found.');
        }

        $account->update(['status' => $status]);
        AccountProvisioner::enqueue($account, $task, ['username' => $account->username]);

        return response()->json(['result' => [['status' => 1, 'statusmsg' => $msg]]]);
    }

    private function findAccount(Request $request): ?Account
    {
        $name = strtolower((string) ($request->input('user') ?: $request->input('username', '')));

        return Account::query()->where('username', $name)->first();
    }

    private function requireManage(Request $request): User
    {
        $user = $request->user();
        if ($user === null || ! $user->hasPermission('accounts.manage')) {
            abort(403, 'Token user lacks accounts.manage.');
        }

        return $user;
    }

    private function fail(string $message): JsonResponse
    {
        return response()->json(['result' => [['status' => 0, 'statusmsg' => $message]]]);
    }
}
ACP_FILE_EOF
echo "  + app/Http/Controllers/WhmApiController.php"

echo "== Step 2: routes (idempotent) =="
if ! grep -q "WHM API 1 compatible" "$PANEL/routes/web.php"; then
cat >> "$PANEL/routes/web.php" <<'ACP_ROUTES_EOF'
// ---- WHM API 1 compatible (billing integration, Bearer token) ----
Route::prefix('json-api')->middleware([\App\Http\Middleware\EnsureApiToken::class])->group(function (): void {
    Route::get('/listaccts', [\App\Http\Controllers\WhmApiController::class, 'listaccts']);
    Route::get('/accountsummary', [\App\Http\Controllers\WhmApiController::class, 'accountsummary']);
    Route::post('/createacct', [\App\Http\Controllers\WhmApiController::class, 'createacct']);
    Route::get('/suspendacct', [\App\Http\Controllers\WhmApiController::class, 'suspendacct']);
    Route::get('/unsuspendacct', [\App\Http\Controllers\WhmApiController::class, 'unsuspendacct']);
    Route::get('/removeacct', [\App\Http\Controllers\WhmApiController::class, 'removeacct']);
});
// ---- /WHM API ----
ACP_ROUTES_EOF
echo "[OK] routes appended"
else
echo "[OK] routes already present"
fi

echo "== Step 3: migrate + cache clear =="
cd "$PANEL"
php artisan migrate --force
php artisan route:clear || true
php artisan config:clear || true
echo "[OK] migrated"
echo "Token banane ke liye:  sudo -u www-data php artisan alphacp:api:token <root-user>  (plain token ek baar dikhega)"

echo "=================================================="
echo " ==> WHM API v1.0 INSTALLED  (base: /json-api/*)"
echo "=================================================="
alphacp-sync || true
