<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlobalEmailRoute extends Model
{
    protected $fillable = [
        'domain', 'mode',
    ];
}
