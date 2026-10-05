<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DomainForward extends Model
{
    protected $fillable = [
        'domain', 'url', 'code',
    ];

    protected function casts(): array
    {
        return [
            'code' => 'integer',
        ];
    }
}
