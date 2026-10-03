<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Catchall extends Model
{
    protected $fillable = [
        'account_id', 'domain', 'dest',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function source(): string
    {
        return '*@' . $this->domain;
    }
}
