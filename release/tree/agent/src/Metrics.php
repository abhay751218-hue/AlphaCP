<?php

declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * cPanel Metrics — Apache access-log parser (root agent side, audit B2).
 *
 * Panel web-FPM `open_basedir` me `/var/log` nahi hai, isliye Metrics page live
 * par khali/error tha. Ab log agent padhta hai aur stats return karta hai:
 *   bytes    = total response bytes ('-' = 0)
 *   visitors = unique client IPs
 *   requests = total log lines (valid)
 *   errors   = status >= 400
 *   top      = path => hits (top 10)
 *
 * Badi logs ke liye: 8 MB se badi file sirf AAKHRI 8 MB parse hoti hai (tail),
 * warna memory blow ho jati. Har line stream hoti hai (fgets), poora file
 * memory me kabhi nahi aata.
 */
final class Metrics
{
    private const TAIL_WINDOW = 8 * 1024 * 1024;
    private const LOG_RE = '/^(\S+)\s+\S+\s+\S+\s+\[[^\]]+\]\s+"[A-Z]+\s+(\S+)[^"]*"\s+(\d{3})\s+(\d+|-)/';

    /**
     * Parse an access-log file (streamed).
     *
     * @return array{bytes:int,visitors:int,requests:int,errors:int,top:array<string,int>,tail:bool}
     */
    public static function parseFile(string $path): array
    {
        $size = (int) @filesize($path);
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return ['bytes' => 0, 'visitors' => 0, 'requests' => 0, 'errors' => 0, 'top' => [], 'tail' => false];
        }

        $tail = false;
        if ($size > self::TAIL_WINDOW) {
            fseek($fh, $size - self::TAIL_WINDOW);
            fgets($fh); // adhoori line skip
            $tail = true;
        }

        $bytes = 0;
        $requests = 0;
        $errors = 0;
        $ips = [];
        $top = [];
        $lines = 0;

        while (($line = fgets($fh)) !== false) {
            $lines++;
            if ($lines > 2_000_000) {
                break; // safety cap
            }
            if (preg_match(self::LOG_RE, $line, $m) !== 1) {
                continue;
            }
            $requests++;
            $ips[$m[1]] = true;
            $status = (int) $m[3];
            if ($status >= 400) {
                $errors++;
            }
            $bytes += $m[4] === '-' ? 0 : (int) $m[4];
            $pathKey = $m[2];
            $top[$pathKey] = ($top[$pathKey] ?? 0) + 1;
        }
        fclose($fh);

        arsort($top);

        return [
            'bytes'    => $bytes,
            'visitors' => count($ips),
            'requests' => $requests,
            'errors'   => $errors,
            'top'      => array_slice($top, 0, 10, true),
            'tail'     => $tail,
        ];
    }
}
