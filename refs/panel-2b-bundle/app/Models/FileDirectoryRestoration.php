<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FileDirectoryRestoration extends Model
{
    protected $fillable = [
        'username', 'path',
    ];
}
