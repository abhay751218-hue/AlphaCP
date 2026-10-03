<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpamSetting extends Model
{
    protected $fillable = [
        'account_id', 'required_score', 'blacklist', 'whitelist',
    ];

    protected function casts(): array
    {
        return [
            'required_score' => 'integer',
            'blacklist' => 'array',
            'whitelist' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
