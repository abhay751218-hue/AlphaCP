<?php
declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/** Append-only audit trail (docs/03-security-matrix.md). Never throws. */
final class Audit
{
    /** @param array<string,mixed> $meta */
    public static function log(
        string $action,
        string $actorType = 'user',
        ?int $actorId = null,
        ?string $targetType = null,
        ?int $targetId = null,
        string $severity = 'info',
        array $meta = [],
        ?string $ip = null,
    ): void {
        try {
            DB::table('audit_logs')->insert([
                'actor_type'  => $actorType,
                'actor_id'    => $actorId,
                'actor_ip'    => $ip ?? request()->ip(),
                'action'      => $action,
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'severity'    => $severity,
                'meta'        => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at'  => now(),
            ]);
        } catch (Throwable $e) {
            // audit must never break a request, but it must be visible in logs
            logger()->error('audit write failed: ' . $e->getMessage(), ['action' => $action]);
        }
    }
}
