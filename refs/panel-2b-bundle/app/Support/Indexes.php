<?php

declare(strict_types=1);

namespace App\Support;

/** cPanel Indexes modes (agent re-validates). */
final class Indexes
{
    /** @var array<string, string> */
    public const MODES = [
        'off' => 'No indexing (default, secure)',
        'simple' => 'Simple filename listing',
        'fancy' => 'Fancy indexing (icons + sizes)',
    ];
}
