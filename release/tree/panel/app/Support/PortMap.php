<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Port ↔ panel map ("ek panel = ek port" rule, OWNER-CTRL slice).
 *
 * Source of truth: etc/ports.json (single JSON object), written by the
 * panel (/ports page) and by the installer/agent (apply_ports). Shape:
 *   {"whm":2087,"cpanel":2083,"webmail":2096,"link":8090,"link_enabled":true}
 *
 * The legacy multi-port shape ({"ssl":[...],"http":[...],...}) is intentionally
 * NOT honoured — it opened one panel on many ports, which the product rule
 * forbids; such a file falls back to DEFAULTS until the owner saves again.
 */
final class PortMap
{
    public const DEFAULTS = [
        'whm'          => 2087,
        'cpanel'       => 2083,
        'webmail'      => 2096,
        'link'         => 8090,
        'link_enabled' => true,
    ];

    /** @var array{whm:int,cpanel:int,webmail:int,link:int,link_enabled:bool}|null */
    private static ?array $cache = null;

    /**
     * @return array{whm:int,cpanel:int,webmail:int,link:int,link_enabled:bool}
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        /** @var array{whm:int,cpanel:int,webmail:int,link:int,link_enabled:bool} $map */
        $map  = self::DEFAULTS;
        $file = (string) (config('acp.ports_file') ?: '/usr/local/alphacp/etc/ports.json');
        $raw  = @file_get_contents($file);

        if ($raw !== false) {
            $j = json_decode($raw, true);
            if (is_array($j) && ! isset($j['ssl'])) {           // new shape only
                foreach (['whm', 'cpanel', 'webmail', 'link'] as $k) {
                    if (isset($j[$k]) && is_numeric($j[$k])) {
                        $map[$k] = (int) $j[$k];
                    }
                }
                if (array_key_exists('link_enabled', $j)) {
                    $map['link_enabled'] = (bool) $j['link_enabled'];
                }
            }
        }

        return self::$cache = $map;
    }

    /** WHM/cPanel/Webmail/link-page me se kaun sa panel is port par khulta hai. */
    public static function familyFor(int $port): ?string
    {
        $m = self::all();
        foreach (['whm', 'cpanel', 'webmail', 'link'] as $f) {
            if ($m[$f] === $port) {
                return $f;
            }
        }

        return null;
    }

    public static function portFor(string $family): int
    {
        return self::all()[$family] ?? self::DEFAULTS[$family];
    }

    public static function linkEnabled(): bool
    {
        return self::all()['link_enabled'];
    }

    /** Login URL of the given family on the current host (port-redirects ke liye). */
    public static function loginUrl(string $family): string
    {
        $host = (string) (request()?->getHost() ?: 'localhost');

        return sprintf('https://%s:%d/login', $host, self::portFor($family));
    }

    /** Tests / post-save refresh. */
    public static function flush(): void
    {
        self::$cache = null;
    }
}

