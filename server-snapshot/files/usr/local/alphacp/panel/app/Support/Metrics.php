<?php

declare(strict_types=1);

namespace App\Support;

/**
 * cPanel-style Metrics (Visitors / Errors / Bandwidth) parsed from an
 * Apache/nginx combined-format access log. No DB needed — computed on read.
 */
final class Metrics
{
    /**
     * Parse a combined-format access log and aggregate stats.
     *
     * @return array{requests:int,bytes:int,visitors:int,errors:int,top:array<string,int>}
     */
    public static function parse(string $logPath): array
    {
        $stats = ['requests' => 0, 'bytes' => 0, 'visitors' => 0, 'errors' => 0, 'top' => []];

        if (! is_file($logPath) || ! is_readable($logPath)) {
            return $stats;
        }

        $ips  = [];
        $top  = [];
        $fh   = fopen($logPath, 'r');
        if ($fh === false) {
            return $stats;
        }

        while (($line = fgets($fh)) !== false) {
            if (! preg_match('/^(\S+) \S+ \S+ \[[^\]]*\] "([A-Z]+) (\S+)[^"]*" (\d{3}) (\d+|-)/', $line, $m)) {
                continue;
            }
            $stats['requests']++;
            $stats['bytes'] += ($m[5] === '-') ? 0 : (int) $m[5];
            $ips[$m[1]] = true;
            if ((int) $m[4] >= 400) {
                $stats['errors']++;
            }
            $path = strtok($m[3], '?');
            $top[$path] = ($top[$path] ?? 0) + 1;
        }
        fclose($fh);

        arsort($top);
        $stats['top']      = array_slice($top, 0, 10, true);
        $stats['visitors'] = count($ips);

        return $stats;
    }

    public static function human(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1) . ' ' . $unit;
            }
            $bytes /= 1024;
        }

        return round($bytes, 1) . ' PB';
    }
}
