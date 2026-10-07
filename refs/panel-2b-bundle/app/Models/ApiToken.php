<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bearer API token for the WHM-compatible API (billing integration). */
final class ApiToken extends Model
{
    protected $table = 'api_tokens';

    protected $fillable = ['user_id', 'name', 'token_hash', 'last_used_at'];

    protected $casts = ['last_used_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
