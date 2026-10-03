<?php

declare(strict_types=1);

namespace App\Support;

/** Allowlisted Apache error codes (agent re-validates HTML). */
final class ErrorPages
{
    /** @var list<string> */
    public const CODES = ['400', '401', '403', '404', '500', '503'];

    /** @param array<string, mixed> $input @return array<string, string> */
    public static function fromRequest(array $input): array
    {
        $out = [];
        foreach (self::CODES as $code) {
            $html = (string) ($input['pages'][$code] ?? $input[$code] ?? '');
            $html = str_replace("\0", '', $html);
            $html = trim($html);
            if ($html === '') {
                continue;
            }
            if (strlen($html) > 16384) {
                continue;
            }
            if (preg_match('/<\\?(php|=)?/i', $html) === 1 || str_contains($html, '<!--#')) {
                continue;
            }
            $out[$code] = $html;
        }
        return $out;
    }
}
