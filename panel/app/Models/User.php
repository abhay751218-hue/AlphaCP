<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Panel login (all personas: superadmin / admin / reseller / support / customer).
 * Table: `users` (see docs/02-database-schema.sql).
 */
class User extends Authenticatable
{
    protected $table = 'users';

    protected $fillable = [
        'username', 'email', 'password', 'display_name', 'phone',
        'role_id', 'reseller_id', 'status', 'locale', 'timezone',
    ];

    protected $hidden = ['password', 'twofa_secret', 'twofa_recovery'];

    protected function casts(): array
    {
        return [
            'password'      => 'hashed',
            'twofa_enabled' => 'boolean',
            'last_login_at' => 'datetime',
            'locked_until'  => 'datetime',
        ];
    }

    public function roleSlug(): string
    {
        return (string) (\Illuminate\Support\Facades\DB::table('roles')
            ->where('id', $this->role_id)->value('slug') ?? 'user');
    }

    public function isAdmin(): bool
    {
        return in_array($this->roleSlug(), ['superadmin', 'admin'], true);
    }
}
