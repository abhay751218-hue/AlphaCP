<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HostnameA extends Model
{
    protected $table = 'hostname_a_entries';

    protected $fillable = [
        'hostname', 'ip',
    ];
}
