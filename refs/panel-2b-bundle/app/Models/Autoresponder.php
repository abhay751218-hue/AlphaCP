<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Autoresponder extends Model
{
    protected $fillable = [
        'account_id', 'localpart', 'domain', 'subject', 'body', 'interval_h',
    ];

    protected function casts(): array
    {
        return [
            'interval_h' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function source(): string
    {
        return $this->localpart . '@' . $this->domain;
    }
}
