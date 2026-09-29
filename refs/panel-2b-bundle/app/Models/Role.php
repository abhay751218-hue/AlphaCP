<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Role extends Model
{
    protected $fillable = ['name', 'label', 'level', 'is_system', 'description'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'level' => 'integer'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    public function isRoot(): bool
    {
        return $this->level === 1;
    }
}
