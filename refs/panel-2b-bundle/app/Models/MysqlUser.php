<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MysqlUser extends Model
{
    protected $fillable = [
        'account_id', 'name', 'host',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsToMany<MysqlDatabase> */
    public function databases(): BelongsToMany
    {
        return $this->belongsToMany(MysqlDatabase::class, 'mysql_user_grants', 'mysql_user_id', 'mysql_database_id')
            ->withTimestamps();
    }

    public function fullName(?string $username = null): string
    {
        $prefix = $username ?? (string) $this->account?->username;

        return $prefix . '_' . $this->name;
    }
}
