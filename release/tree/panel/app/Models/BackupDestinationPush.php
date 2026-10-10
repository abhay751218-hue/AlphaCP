<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One archive's journey to one destination (idempotency ledger for cron). */
class BackupDestinationPush extends Model
{
    protected $table = 'backup_destination_pushes';

    protected $fillable = [
        'destination_id', 'username', 'archive_id', 'file', 'status', 'task_id', 'message',
    ];

    protected function casts(): array
    {
        return [
            'destination_id' => 'integer',
            'task_id'        => 'integer',
        ];
    }

    public function destination(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BackupDestination::class, 'destination_id');
    }
}
