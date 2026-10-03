<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackupUserSelection extends Model
{
    protected $fillable = [
        'username',
    ];
}
