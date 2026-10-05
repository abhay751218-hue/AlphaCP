<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A remote backup destination (S10).
 *
 * This row is deliberately secret-free: it says WHERE archives go and which
 * host key we pinned — never the private key or the password. Those live in
 * 0600 files inside the agent's own state directory.
 */
class BackupDestination extends Model
{
    protected $fillable = [
        'name', 'type', 'host', 'port', 'username', 'path', 'auth_type',
        'retention_days', 'host_fingerprint', 'public_key', 'enabled',
        'last_test_at', 'last_test_ok', 'last_test_message',
        'last_push_at', 'last_push_message',
    ];

    protected function casts(): array
    {
        return [
            'port'           => 'integer',
            'retention_days' => 'integer',
            'enabled'        => 'boolean',
            'last_test_ok'   => 'boolean',
            'last_test_at'   => 'datetime',
            'last_push_at'   => 'datetime',
        ];
    }

    /** @return list<string> */
    public static function names(): array
    {
        return self::query()->orderBy('name')->pluck('name')
            ->filter(static fn ($name): bool => is_string($name) && $name !== '')
            ->values()->all();
    }
}
