<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupWizard extends Model
{
    protected $fillable = [
        'account_id', 'action', 'scope',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
