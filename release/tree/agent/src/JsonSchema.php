<?php
declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * Minimal JSON-Schema validator (draft-7 subset).
 *
 * Why hand-rolled? The agent runs as root and must stay dependency-free
 * (no Composer). We only ever validate our OWN task payload schemas, so a
 * small, auditable subset is enough — and it fails CLOSED.
 *
 * PHP honesty note: json_decode('{}', true) and json_decode('[]', true) both
 * give []. An empty array therefore counts as BOTH object and array (that is
 * the least surprising behaviour for payload validation). Everything else is
 * decided by array_is_list().
 *
 * Supported: type (string|list of types), required, properties,
 * additionalProperties (bool), enum, pattern, min/maxLength, minimum/maximum,
 * items, min/maxItems.
 */
final class JsonSchema
{
    /**
     * @param  array<string, mixed> $schema
     * @param  mixed                $data   any decoded JSON value
     * @return list<string> validation errors (empty = valid)
     */
    public static function validate(array $schema, mixed $data, string $path = '$'): array
    {
        $errors = [];

        // ---- type ------------------------------------------------------------
        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            if (!self::matchesType($types, $data)) {
                $errors[] = "{$path}: expected type " . implode('|', $types) . ', got ' . get_debug_type($data);
                return $errors; // deeper checks are meaningless on a type mismatch
            }
        }

        // ---- enum ------------------------------------------------------------
        if (array_key_exists('enum', $schema) && !self::inEnum((array) $schema['enum'], $data)) {
            $errors[] = "{$path}: value not in enum ["
                . implode(', ', array_map(static fn ($v) => is_scalar($v) ? (string) $v : get_debug_type($v), (array) $schema['enum']))
                . ']';
        }

        // ---- strings ---------------------------------------------------------
        if (is_string($data)) {
            if (isset($schema['pattern']) && @preg_match('~' . $schema['pattern'] . '~', $data) !== 1) {
                $errors[] = "{$path}: does not match pattern {$schema['pattern']}";
            }
            if (isset($schema['minLength']) && self::len($data) < $schema['minLength']) {
                $errors[] = "{$path}: shorter than minLength {$schema['minLength']}";
            }
            if (isset($schema['maxLength']) && self::len($data) > $schema['maxLength']) {
                $errors[] = "{$path}: longer than maxLength {$schema['maxLength']}";
            }
        }

        // ---- numbers ---------------------------------------------------------
        if (is_int($data) || is_float($data)) {
            if (isset($schema['minimum']) && $data < $schema['minimum']) {
                $errors[] = "{$path}: below minimum {$schema['minimum']}";
            }
            if (isset($schema['maximum']) && $data > $schema['maximum']) {
                $errors[] = "{$path}: above maximum {$schema['maximum']}";
            }
        }

        if (!is_array($data)) {
            return $errors; // scalars are fully validated by now
        }

        // ---- arrays ----------------------------------------------------------
        if ($data === [] || array_is_list($data)) {
            if (isset($schema['minItems']) && count($data) < $schema['minItems']) {
                $errors[] = "{$path}: fewer than minItems {$schema['minItems']}";
            }
            if (isset($schema['maxItems']) && count($data) > $schema['maxItems']) {
                $errors[] = "{$path}: more than maxItems {$schema['maxItems']}";
            }
            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($data as $i => $item) {
                    $errors = [...$errors, ...self::validate($schema['items'], $item, "{$path}[{$i}]")];
                }
            }
        }

        // ---- objects ---------------------------------------------------------
        if ($data === [] || !array_is_list($data)) {
            foreach ((array) ($schema['required'] ?? []) as $key) {
                if (!array_key_exists($key, $data)) {
                    $errors[] = "{$path}: missing required property '{$key}'";
                }
            }

            $props     = (array) ($schema['properties'] ?? []);
            $allowMore = $schema['additionalProperties'] ?? true;

            foreach ($data as $key => $value) {
                if (isset($props[$key]) && is_array($props[$key])) {
                    $errors = [...$errors, ...self::validate($props[$key], $value, "{$path}.{$key}")];
                } elseif ($allowMore === false) {
                    $errors[] = "{$path}: unknown property '{$key}'";
                }
            }
        }

        return $errors;
    }

    /** Character length without hard-depending on mbstring (byte length as fallback). */
    private static function len(string $s): int
    {
        return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
    }

    /** @param list<string> $types */
    private static function matchesType(array $types, mixed $data): bool
    {
        foreach ($types as $type) {
            $ok = match ($type) {
                // [] is ambiguous in PHP — accept it as object AND as array.
                'object'  => is_array($data) && ($data === [] || !array_is_list($data)),
                'array'   => is_array($data) && ($data === [] || array_is_list($data)),
                'string'  => is_string($data),
                'integer' => is_int($data),
                'number'  => is_int($data) || is_float($data),
                'boolean' => is_bool($data),
                'null'    => $data === null,
                default   => false,
            };
            if ($ok) {
                return true;
            }
        }
        return false;
    }

    /** @param list<mixed> $enum */
    private static function inEnum(array $enum, mixed $data): bool
    {
        foreach ($enum as $candidate) {
            if ($candidate === $data) {
                return true;
            }
        }
        return false;
    }
}
