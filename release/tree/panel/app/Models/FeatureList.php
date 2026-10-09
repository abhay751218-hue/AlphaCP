<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeatureList extends Model
{
    protected $fillable = ['name', 'features', 'is_default'];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }
}
