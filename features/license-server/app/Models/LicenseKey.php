<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Issued (sellable) license key — revocation ke liye record. */
final class LicenseKey extends Model
{
    protected $table = 'license_keys';

    protected $fillable = ['server_id', 'plan', 'expires_at', 'key_hash', 'revoked'];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked'    => 'boolean',
    ];
}
