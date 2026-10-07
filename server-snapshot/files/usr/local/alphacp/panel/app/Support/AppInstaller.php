<?php

declare(strict_types=1);

namespace App\Support;

/**
 * cPanel "Site Software" — app catalog.
 *
 * B1: asli install kaam (public_html, MariaDB DB/user/grant, tarball download +
 * extract, wp-config, chown) ab root agent karta hai (`apps.install` task) —
 * web-FPM me `proc_open` disabled hone se Process-based install HTTP 500 deta tha.
 * Panel ke paas sirf catalog bacha hai (koi Process/File usage nahi).
 */
final class AppInstaller
{
    /** @return array<int, array{id:string,name:string,desc:string}> */
    public static function catalog(): array
    {
        return [
            ['id' => 'wordpress', 'name' => 'WordPress', 'desc' => 'Blog / CMS / WooCommerce'],
            ['id' => 'joomla',    'name' => 'Joomla',    'desc' => 'CMS (jald aa raha hai)'],
            ['id' => 'drupal',    'name' => 'Drupal',    'desc' => 'CMS (jald aa raha hai)'],
        ];
    }
}
