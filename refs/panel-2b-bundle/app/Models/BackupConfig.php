<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackupConfig extends Model
{
    protected $fillable = [
        'enabled', 'schedule', 'retention_days', 'destination', 'remote_host', 'remote_user', 'remote_path',
    ];

    protected $casts = [
        'enabled'        => 'boolean',
        'retention_days' => 'integer',
    ];
}
