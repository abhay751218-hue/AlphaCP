<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * AlphaCP UI theme engine — cPanel-parity workstream (docs/10-ui-parity-design.md).
 *
 * cPanel company jaisa model: **ek hi backend, alag-alag panels** —
 *   jupiter  → customer panel (cPanel "Jupiter" jaisa: navy sidebar + orange accent + Tools grid)
 *   whm      → server manager / reseller (WHM jaisa: dark nav-tree + Favorites + Statistics)
 *   webmail  → mail-only user (Webmail jaisa: white + orange wordmark)
 *
 * Theme **sirf dikhta farq** hai — data, routes, permissions, DB sab wahi rehte hain.
 * Isliye ek page sabhi themes ke andar chalna chahiye (layout hi badalta hai).
 *
 * Custom color palette ke liye: `ACP_UI_THEME` env (whitelist) ya aage chal kar
 * `settings` table se per-server branding (Phase P-UI-5).
 */
final class Theme
{
    public const CPANEL  = 'jupiter';
    public const WHM     = 'whm';
    public const WEBMAIL = 'webmail';

    /** @var list<string> */
    public const ALL = [self::CPANEL, self::WHM, self::WEBMAIL];

    /** Kaunse theme ka mode + label (UI me dikhta hai). */
    public const META = [
        self::CPANEL  => ['label' => 'cPanel',   'mode' => 'cpanel',  'panel' => 'AlphaCP'],
        self::WHM     => ['label' => 'WHM',      'mode' => 'whm',     'panel' => 'AlphaCP Server Manager'],
        self::WEBMAIL => ['label' => 'Webmail',  'mode' => 'cpanel',  'panel' => 'AlphaCP Webmail'],
    ];

    /**
     * User ke liye theme.
     *
     * Priority: env force (whitelist) → mail role = webmail → WHM permission = whm → default cpanel.
     */
    public static function forUser(?User $user): string
    {
        $forced = strtolower(trim((string) config('acp.ui.theme', '')));
        if ($forced !== '' && in_array($forced, self::ALL, true)) {
            return $forced;
        }

        // User ka apna style choice (cPanel → Preferences → Change Style).
        // CLI/queue me session nahi hota — us waqt chup-chaap default par jao.
        try {
            $chosen = strtolower(trim((string) session('acp_theme', '')));
        } catch (\Throwable) {
            $chosen = '';
        }
        if ($chosen !== '' && in_array($chosen, self::ALL, true)) {
            return $chosen;
        }

        if (! $user instanceof User) {
            return self::CPANEL;
        }

        if ($user->role?->name === 'mail') {
            return self::WEBMAIL;
        }

        return ModuleCatalog::modeFor($user) === 'whm' ? self::WHM : self::CPANEL;
    }

    public static function label(string $theme): string
    {
        return self::META[$theme]['label'] ?? 'cPanel';
    }

    /** Theme ka panel mode (ModuleCatalog::modeFor() wahi values). */
    public static function mode(string $theme): string
    {
        return self::META[$theme]['mode'] ?? 'cpanel';
    }

    /** Brand line jo header me chhapti hai. */
    public static function panelName(string $theme): string
    {
        return self::META[$theme]['panel'] ?? 'AlphaCP';
    }

    /**
     * Style switcher (cPanel ka "Preferences → Change Style" jaisa).
     * Filhaal sirf wahi themes jo user ki role ke liye allowed hain.
     *
     * @return array<string,string> theme => label
     */
    public static function allowedFor(?User $user): array
    {
        if (! config('acp.ui.allow_style_switch', true) || ! $user instanceof User) {
            return [];
        }

        $out = [];
        $mode = ModuleCatalog::modeFor($user);
        foreach (self::ALL as $theme) {
            if (self::META[$theme]['mode'] !== $mode) {
                continue;
            }
            $out[$theme] = self::label($theme);
        }

        return $out;
    }

    /** Design tokens — CSS variables ke roop me layout me jaate hain. */
    public static function tokens(string $theme): array
    {
        return match ($theme) {
            self::WHM => [
                // WHM (cPanel brand navy + orange family)
                'nav'       => '#293a4a',
                'nav-dark'  => '#1f2d3a',
                'accent'    => '#ff6c2c',
                'accent-2'  => '#179bd7',
                'bg'        => '#eef1f4',
            ],
            self::WEBMAIL => [
                'nav'       => '#ffffff',
                'nav-dark'  => '#293a4a',
                'accent'    => '#ff6c2c',
                'accent-2'  => '#179bd7',
                'bg'        => '#f5f7f9',
            ],
            default => [
                // cPanel (customer) — paper-white + navy + orange
                'nav'       => '#293a4a',
                'nav-dark'  => '#22303f',
                'accent'    => '#ff6c2c',
                'accent-2'  => '#179bd7',
                'bg'        => '#eef1f4',
            ],
        };
    }

    /**
     * Client-side search ke liye flat index (cPanel ka "Find functions quickly…").
     *
     * @param  array<string, array{label:string, icon?:string, items:list<array<string,mixed>>}>  $sections
     * @return list<array{name:string, section:string, url:string|null}>
     */
    public static function searchIndex(array $sections): array
    {
        $out = [];
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                $out[] = [
                    'name'    => (string) ($item['name'] ?? ''),
                    'section' => (string) ($section['label'] ?? ''),
                    'url'     => ($item['status'] ?? 'step') === 'live' && isset($item['route'])
                        ? route($item['route'])
                        : null,
                ];
            }
        }

        return $out;
    }
}
