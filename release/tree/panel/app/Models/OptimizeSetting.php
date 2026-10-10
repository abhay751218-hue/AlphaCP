<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** cPanel "Optimize Website" — compression setting per user. */
final class OptimizeSetting extends Model
{
    protected $table = 'optimize_settings';

    protected $fillable = ['user_id', 'level'];
}
