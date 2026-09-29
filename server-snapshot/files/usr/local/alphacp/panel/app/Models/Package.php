<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Package extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'owner_id', 'name', 'description', 'QUOTA', 'BWLIMIT', 'MAXPOP', 'MAXFTP',
        'MAXSQL', 'MAXSUB', 'MAXPARK', 'MAXADDON', 'MAXCRON', 'MAXINODE',
        'HASSHELL', 'is_default', 'status',
    ];

    protected function casts(): array
    {
        return [
            'QUOTA' => 'integer',
            'BWLIMIT' => 'integer',
            'is_default' => 'boolean',
            'HASSHELL' => 'boolean',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function quotaMb(): int
    {
        return (int) $this->QUOTA;
    }
}
