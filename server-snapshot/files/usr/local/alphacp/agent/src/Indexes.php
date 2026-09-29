<?php
declare(strict_types=1);

namespace Alphacp\Agent;

/** Allowlisted Apache Indexes modes (cPanel-style). */
final class Indexes
{
    /** @var list<string> */
    public const MODES = ['off', 'simple', 'fancy'];

    public static function normalize(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, self::MODES, true)) {
            throw new TaskRejectedException('invalid indexes mode');
        }
        return $mode;
    }
}
