<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Per-user security add-on settings (hotlink / leech protection). */
final class SecurityExtra extends Model
{
    protected $table = 'security_extras';

    protected $fillable = ['user_id', 'kind', 'data'];

    protected $casts = ['data' => 'array'];
}
