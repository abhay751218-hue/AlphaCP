<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Domain extends Model
{
    public const TYPES = ['main', 'addon', 'sub', 'parked', 'redirect'];

    protected $fillable = [
        'account_id', 'type', 'domain', 'document_root', 'redirect_url',
        'redirect_code', 'php_version', 'status',
    ];

    protected function casts(): array
    {
        return [
            'redirect_code' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function isMain(): bool
    {
        return $this->type === 'main';
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['pending', 'removing'], true);
    }
}
