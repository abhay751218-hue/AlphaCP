<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MysqlDatabase extends Model
{
    protected $fillable = [
        'account_id', 'name',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function fullName(?string $username = null): string
    {
        $prefix = $username ?? (string) $this->account?->username;

        return $prefix . '_' . $this->name;
    }
}
