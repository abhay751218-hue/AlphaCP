<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;

    protected $fillable = [
        'username', 'email', 'password_hash', 'full_name', 'role_id', 'status',
        'force_password_change', 'two_factor_secret', 'two_factor_enabled',
        'two_factor_confirmed_at', 'two_factor_last_step', 'locale', 'theme', 'created_by',
    ];

    protected $hidden = ['password_hash', 'two_factor_secret'];

    protected function casts(): array
    {
        return [
            'two_factor_enabled' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_step' => 'integer',
            'force_password_change' => 'boolean',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
        ];
    }

    /** Laravel's auth expects this method — we store the hash in `password_hash`. */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function isRoot(): bool
    {
        return $this->role?->level === 1;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /** Has this user got the given permission key? (root bypasses everything) */
    public function hasPermission(string $key): bool
    {
        if ($this->isRoot()) {
            return true;
        }
        return $this->role?->permissions()->where('permission_key', $key)->exists() ?? false;
    }

    /** @return array<int, string> */
    public function permissionKeys(): array
    {
        if ($this->isRoot()) {
            return Permission::query()->pluck('key')->all();
        }
        return $this->role?->permissions()->pluck('permission_key')->all() ?? [];
    }
}
