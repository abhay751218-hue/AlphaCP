<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackupConfig extends Model
{
    protected $fillable = [
        'schedule', 'retention',
    ];

    protected function casts(): array
    {
        return [
            'retention' => 'integer',
        ];
    }
}
