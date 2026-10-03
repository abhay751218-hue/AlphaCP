<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DnsCleanup extends Model
{
    protected $fillable = [
        'domain',
    ];
}
