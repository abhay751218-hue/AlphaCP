<?php

declare(strict_types=1);

namespace App\Support;

/** Allowlisted MultiPHP INI keys shown in the editor (agent re-validates). */
final class PhpIni
{
    /** @var array<string, array{label:string, kind:string, options?:list<string>}> */
    public const FIELDS = [
        'memory_limit' => ['label' => 'memory_limit', 'kind' => 'text'],
        'max_execution_time' => ['label' => 'max_execution_time', 'kind' => 'text'],
        'max_input_time' => ['label' => 'max_input_time', 'kind' => 'text'],
        'max_input_vars' => ['label' => 'max_input_vars', 'kind' => 'text'],
        'post_max_size' => ['label' => 'post_max_size', 'kind' => 'text'],
        'upload_max_filesize' => ['label' => 'upload_max_filesize', 'kind' => 'text'],
        'display_errors' => ['label' => 'display_errors', 'kind' => 'flag'],
        'log_errors' => ['label' => 'log_errors', 'kind' => 'flag'],
        'allow_url_fopen' => ['label' => 'allow_url_fopen', 'kind' => 'flag'],
        'short_open_tag' => ['label' => 'short_open_tag', 'kind' => 'flag'],
        'date.timezone' => ['label' => 'date.timezone', 'kind' => 'text'],
        'error_reporting' => ['label' => 'error_reporting', 'kind' => 'reporting'],
        'session.gc_maxlifetime' => ['label' => 'session.gc_maxlifetime', 'kind' => 'text'],
        'default_charset' => ['label' => 'default_charset', 'kind' => 'charset'],
    ];

    /** @param array<string, mixed> $input @return array<string, string> */
    public static function fromRequest(array $input): array
    {
        $out = [];
        foreach (array_keys(self::FIELDS) as $key) {
            $v = trim((string) ($input[$key] ?? ''));
            if ($v === '') {
                continue;
            }
            if (strpbrk($v, "\r\n\0") !== false) {
                continue;
            }
            $out[$key] = $v;
        }
        return $out;
    }
}
