<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** cPanel IP Blocker — an IP denied for this hosting account. */
final class BlockedIp extends Model
{
    protected $table = 'blocked_ips';

    protected $fillable = ['account_id', 'ip', 'note'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
