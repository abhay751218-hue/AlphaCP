<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsDynamicHost extends Model
{
    protected $fillable = [
        'account_id', 'domain', 'name', 'token', 'ip',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
