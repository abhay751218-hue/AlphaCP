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
