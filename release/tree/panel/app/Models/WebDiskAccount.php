<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** cPanel Web Disk account (WebDAV) — read-only ya read-write. */
final class WebDiskAccount extends Model
{
    protected $table = 'webdisk_accounts';

    protected $fillable = ['user_id', 'login', 'permissions'];
}
