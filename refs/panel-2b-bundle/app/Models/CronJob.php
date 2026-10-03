<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CronJob extends Model
{
    protected $fillable = [
        'account_id', 'minute', 'hour', 'day', 'month', 'weekday', 'command', 'enabled', 'status',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function schedule(): string
    {
        return "{$this->minute} {$this->hour} {$this->day} {$this->month} {$this->weekday}";
    }
}
