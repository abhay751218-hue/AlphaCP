<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferRestore extends Model
{
    protected $fillable = [
        'username', 'action',
    ];
}
