<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EncryptionKey extends Model
{
    protected $fillable = [
        'account_id', 'localpart', 'domain', 'comment',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function address(): string
    {
        return $this->localpart . '@' . $this->domain;
    }
}
