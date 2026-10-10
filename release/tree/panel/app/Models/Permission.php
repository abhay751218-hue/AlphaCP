<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = ['key', 'module', 'label', 'sort'];

    protected function casts(): array
    {
        return ['sort' => 'integer'];
    }
}
