<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mailbox extends Model
{
    protected $fillable = [
        'account_id', 'localpart', 'domain', 'quota_mb', 'password_hash', 'status',
    ];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'quota_mb' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function address(): string
    {
        return $this->localpart . '@' . $this->domain;
    }
}
