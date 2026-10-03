<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZoneTtl extends Model
{
    protected $fillable = [
        'domain', 'ttl',
    ];

    protected function casts(): array
    {
        return [
            'ttl' => 'integer',
        ];
    }
}
