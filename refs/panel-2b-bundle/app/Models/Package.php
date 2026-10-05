<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\PackageLimits;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Package extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'owner_id', 'name', 'description', 'feature_list_id',
        'QUOTA', 'BWLIMIT', 'MAXPOP', 'MAXFWD', 'MAXRESP', 'MAXPASS', 'MAXLST',
        'MAXFTP', 'MAXSQL', 'MAXSUB', 'MAXPARK', 'MAXADDON', 'MAXCRON', 'MAXINODE',
        'MAILBOXQUOTA', 'DBQUOTA', 'MAXEMAILPERHOUR', 'MAXMSGSIZE',
        'HASSHELL', 'DEDICATEDIP', 'CPULIMIT', 'RAMLIMIT', 'IOLIMIT', 'NPROCLIMIT',
        'EPLIMIT', 'is_default', 'status',
    ];

    protected function casts(): array
    {
        $casts = [
            'is_default' => 'boolean',
            'HASSHELL' => 'boolean',
            'DEDICATEDIP' => 'boolean',
        ];
        foreach (PackageLimits::KEYS as $key) {
            $casts[$key] = 'integer';
        }
        return $casts;
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function featureList(): BelongsTo
    {
        return $this->belongsTo(FeatureList::class);
    }

    public function quotaMb(): int
    {
        return (int) $this->QUOTA;
    }

    public function formatLimit(string $key): string
    {
        $value = (int) $this->getAttribute($key);
        return $value < 0 ? 'unlimited' : (string) $value;
    }
}
