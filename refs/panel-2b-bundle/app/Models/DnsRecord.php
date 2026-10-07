<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DnsRecord extends Model
{
    protected $fillable = [
        'account_id', 'domain', 'name', 'type', 'value',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
