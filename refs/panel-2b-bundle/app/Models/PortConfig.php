<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Owner-defined panel ports (single row). */
final class PortConfig extends Model
{
    protected $table = 'port_configs';

    protected $fillable = ['data'];

    protected $casts = ['data' => 'array'];
}
