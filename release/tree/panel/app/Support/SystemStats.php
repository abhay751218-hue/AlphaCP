<?php

declare(strict_types=1);

namespace App\Support;

/**
 * WHM-style server resource stats (disk / memory / load / cpu).
 * Reads /proc + PHP disk functions with safe fallbacks (no crash anywhere).
 */
final class SystemStats
{
    /** @return array{total_mb:int,used_mb:int,pct:int} */
    public static function memory(): array
    {
        $total = 0;
        $avail = 0;
        $lines = @file('/proc/meminfo') ?: [];
        foreach ($lines as $line) {
            if (str_starts_with($line, 'MemTotal:')) {
                $total = (int) (preg_replace('/\D/', '', $line) ?: 0);
            }
            if (str_starts_with($line, 'MemAvailable:')) {
                $avail = (int) (preg_replace('/\D/', '', $line) ?: 0);
            }
        }
        $totalMb = intdiv($total, 1024);
        $availMb = intdiv($avail, 1024);
        $usedMb  = max(0, $totalMb - $availMb);
        $pct     = $totalMb > 0 ? (int) round($usedMb * 100 / $totalMb) : 0;

        return ['total_mb' => $totalMb, 'used_mb' => $usedMb, 'pct' => $pct];
    }

    /** @return array{1:float,5:float,15:float} */
    public static function load(): array
    {
        $raw = trim((string) @file_get_contents('/proc/loadavg'));
        $parts = preg_split('/\s+/', $raw) ?: [];

        return [
            1  => (float) ($parts[0] ?? 0),
            5  => (float) ($parts[1] ?? 0),
            15 => (float) ($parts[2] ?? 0),
        ];
    }

    /** @return array{total_gb:int,used_gb:int,pct:int} */
    public static function disk(string $path = '/'): array
    {
        $total = @disk_total_space($path);
        $free  = @disk_free_space($path);
        if ($total === false || $free === false || $total <= 0) {
            return ['total_gb' => 0, 'used_gb' => 0, 'pct' => 0];
        }
        $used = $total - $free;
        $toGb = static fn (float $b): int => (int) round($b / 1024 ** 3);

        return ['total_gb' => $toGb($total), 'used_gb' => $toGb($used), 'pct' => (int) round($used * 100 / $total)];
    }

    public static function cpus(): int
    {
        $cpu = (string) @file_get_contents('/proc/cpuinfo');
        $n   = substr_count($cpu, 'processor');

        return max(1, $n);
    }
}
