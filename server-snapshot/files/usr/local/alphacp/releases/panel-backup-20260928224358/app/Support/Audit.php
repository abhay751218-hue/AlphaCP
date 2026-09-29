<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Writes to `audit_logs` (immutable, append-only — docs/03-security-matrix.md).
 * Every panel action that changes state MUST call this.
 */
final class Audit
{
    /** @param array<string, mixed> $meta */
    public static function log(
        string $action,
        string $severity = 'info',
        ?string $targetType = null,
        ?int $targetId = null,
        array $meta = [],
    ): void {
        $user = Auth::user();

        try {
            DB::table('audit_logs')->insert([
                'actor_type'  => $user ? 'user' : 'system',
                'actor_id'    => $user?->id,
                'actor_ip'    => request()->ip(),
                'action'      => $action,
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'severity'    => $severity,
                'meta'        => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            report($e); // auditing must never break the action
        }
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public static function recent(int $limit = 20, ?string $action = null, ?string $severity = null)
    {
        $query = DB::table('audit_logs')->orderByDesc('id')->limit($limit);
        if ($action) {
            $query->where('action', 'like', $action . '%');
        }
        if ($severity) {
            $query->where('severity', $severity);
        }
        return $query->get();
    }
}
