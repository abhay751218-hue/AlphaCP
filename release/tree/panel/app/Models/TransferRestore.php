<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferRestore extends Model
{
    protected $fillable = [
        'username', 'action', 'mysql', 'mysql_only',
    ];

    protected $casts = [
        'mysql' => 'boolean',
    ];

    /** @return list<string> database suffixes requested for the MySQL restore */
    public function mysqlOnlyList(): array
    {
        $raw = (string) ($this->mysql_only ?? '');
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $part): string => strtolower(trim($part)),
            explode(',', $raw),
        ), static fn (string $part): bool => $part !== ''));
    }
}
