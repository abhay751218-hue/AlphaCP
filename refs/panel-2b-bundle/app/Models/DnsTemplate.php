<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DnsTemplate extends Model
{
    protected $fillable = [
        'name', 'body',
    ];
}
