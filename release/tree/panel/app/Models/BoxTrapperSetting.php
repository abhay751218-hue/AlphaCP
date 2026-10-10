<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoxTrapperSetting extends Model
{
    protected $fillable = [
        'account_id', 'enabled', 'allowlist',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'allowlist' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
