<?php

declare(strict_types=1);

namespace App\Support;

/**
 * cPanel-style Metrics helpers.
 *
 * B2: asli log-parsing ab ROOT AGENT karta hai (`metrics.access` task) kyunki
 * web-FPM ka open_basedir `/var/log` allow nahi karta — panel ke paas sirf
 * display helpers hain (koi file I/O nahi).
 */
final class Metrics
{
    /** Bytes ko human-readable unit me (1.5 MB etc.). */
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
