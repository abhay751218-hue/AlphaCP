<?php
declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * Allowlisted custom error pages. HTML only — no PHP, no SSI.
 */
final class ErrorPages
{
    /** @var list<string> */
    public const CODES = ['400', '401', '403', '404', '500', '503'];

    public const MAX_BYTES = 16384;

    /**
     * @param  array<string, mixed> $raw
     * @return array<string, string> code => html (empty omitted)
     */
    public static function sanitize(array $raw): array
    {
        $out = [];
        foreach ($raw as $code => $html) {
            $code = (string) $code;
            if (!in_array($code, self::CODES, true)) {
                throw new TaskRejectedException('unknown error page code: ' . $code);
            }
            if (!is_scalar($html)) {
                throw new TaskRejectedException("invalid html for {$code}");
            }
            $text = self::cleanHtml((string) $html);
            if ($text === '') {
                continue;
            }
            $out[$code] = $text;
        }
        return $out;
    }

    public static function cleanHtml(string $html): string
    {
        $html = str_replace("\0", '', $html);
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        if (strlen($html) > self::MAX_BYTES) {
            throw new TaskRejectedException('error page too large (16 KiB max)');
        }
        if (preg_match('/<\\?(php|=)?/i', $html) === 1) {
            throw new TaskRejectedException('PHP tags are not allowed in error pages');
        }
        if (str_contains($html, '<!--#')) {
            throw new TaskRejectedException('SSI is not allowed in error pages');
        }
        return $html;
    }
}
