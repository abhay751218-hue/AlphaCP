<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Forwarder extends Model
{
    protected $fillable = [
        'account_id', 'localpart', 'domain', 'dest',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function source(): string
    {
        return $this->localpart . '@' . $this->domain;
    }
}
