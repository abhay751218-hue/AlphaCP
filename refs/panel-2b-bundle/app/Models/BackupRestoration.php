<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackupRestoration extends Model
{
    protected $fillable = [
        'mode', 'username',
    ];
}
