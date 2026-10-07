<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Section → icon glyph (offline; koi CDN/font package nahi).
 *
 * Icon set theme-agnostic hai: sidebar, tiles aur section headers sab yahi
 * glyph use karte hain, taaki ek hi jagah badalne se poori UI badal jaye
 * (docs/10-ui-parity-design.md §5 — Phase P-UI-5 me SVG set aayega).
 */
final class NavIcon
{
    /** @var array<string,string> */
    private const GLYPHS = [
        'folder'   => "\u{1F4C1}",   // Files
        'mail'     => "\u{2709}",    // Email
        'globe'    => "\u{1F310}",   // Domains / DNS
        'database' => "\u{1F5C4}",   // Databases
        'chart'    => "\u{1F4C8}",   // Metrics
        'shield'   => "\u{1F6E1}",   // Security
        'box'      => "\u{1F4E6}",   // Software / Packages
        'cog'      => "\u{2699}",    // Advanced
        'user'     => "\u{1F464}",   // Preferences
        'users'    => "\u{1F465}",   // Resellers
        'server'   => "\u{1F5A5}",   // Account Functions / server
        'search'   => "\u{1F50D}",   // Account Information
        'backup'   => "\u{1F4BE}",   // Backup
        'transfer' => "\u{1F501}",   // Transfers
        'pulse'    => "\u{1F4E1}",   // Server Status
        'license'  => "\u{1F511}",   // License & Updates
    ];

    public static function glyph(string $icon): string
    {
        return self::GLYPHS[$icon] ?? self::GLYPHS['cog'];
    }
}
