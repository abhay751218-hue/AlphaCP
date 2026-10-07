<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** cPanel-style FTP account (Pure-FTPd virtual user) owned by a hosting Account. */
final class FtpAccount extends Model
{
    protected $table = 'ftp_accounts';

    protected $fillable = ['account_id', 'username', 'home_path', 'quota_mb', 'status'];

    protected $casts = ['quota_mb' => 'integer'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
