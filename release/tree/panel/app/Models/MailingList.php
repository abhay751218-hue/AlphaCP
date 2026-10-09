<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailingList extends Model
{
    protected $fillable = [
        'account_id', 'localpart', 'domain', 'owner', 'members',
    ];

    protected function casts(): array
    {
        return ['members' => 'array'];
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
