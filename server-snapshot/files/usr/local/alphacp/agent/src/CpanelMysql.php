<?php
declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * MySQL part of a cPanel archive (S10).
 *
 * `cpmove-<user>/mysql/*.sql` holds one mysqldump per database. This class only
 * *plans* and *prepares* those dumps — the actual client run lives in
 * MysqlServer/BackupArchiveStore so the SQL path stays in one place.
 *
 * Everything fails closed:
 *  - a dump file name must map onto the account (`<user>_<suffix>.sql`);
 *  - statements that name the *target* database (`USE`, `DROP/CREATE DATABASE` —
 *    mysqldump --add-drop-database emits them) are dropped: the agent creates
 *    the database itself and prepends its own `USE`;
 *  - a statement that names **any other** database, or touches the filesystem
 *    (`INTO OUTFILE/DUMPFILE`, `LOAD DATA`, `LOAD_FILE`) refuses the whole dump
 *    before a single statement runs.
 */
final class CpanelMysql
{
    /** Dumps bigger than this are refused before a single statement runs. */
    public const MAX_DUMP_BYTES = 4_294_967_296; // 4 GiB

    /** How much of a line's start we inspect for hostile statements. */
    private const SCAN_BYTES = 65_536;

    /** @var list<string> case-insensitive tokens that never belong in a customer dump */
    private const FORBIDDEN = [
        'INTO OUTFILE',
        'INTO DUMPFILE',
        'LOAD DATA',
        'LOAD_FILE',
        'GRANT ',
        'CREATE USER',
        'DROP USER',
        'SET GLOBAL',
    ];

    /** Statements that are dropped when they name the target database. */
    private const SELF_SCOPED = '/(?:DROP|CREATE|ALTER)\s+(?:DATABASE|SCHEMA)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?[`\'"]?([A-Za-z0-9_$]+)/i';

    /**
     * List the restorable dumps inside a `mysql/` section listing.
     *
     * @param  list<string> $only suffixes the caller wants (empty = all)
     * @return array{dumps: list<array{member: string, suffix: string, file: string, gzip: bool}>, skipped: list<array{member: string, reason: string}>}
     */
    public static function plan(string $listing, string $username, array $only = []): array
    {
        $only = array_map(static fn ($s): string => strtolower(trim((string) $s)), $only);
        $dumps = [];
        $skipped = [];
        $seen = [];

        foreach (preg_split('/\r\n|\n|\r/', $listing) ?: [] as $raw) {
            $member = trim($raw);
            if ($member === '' || str_ends_with($member, '/')) {
                continue;
            }
            if (str_contains($member, "\0") || str_starts_with($member, '/') || str_contains($member, '..')) {
                throw new TaskRejectedException("hostile path inside the archive's mysql section: {$member}");
            }
            $base = basename($member);
            $gzip = str_ends_with($base, '.sql.gz');
            if (!$gzip && !str_ends_with($base, '.sql')) {
                $skipped[] = ['member' => $member, 'reason' => 'not a .sql dump'];
                continue;
            }
            $stem = $gzip ? substr($base, 0, -7) : substr($base, 0, -4);
            if (str_starts_with($stem, $username . '_')) {
                $stem = substr($stem, strlen($username) + 1);
            }
            if (preg_match('/^[a-z][a-z0-9_]{0,15}$/', $stem) !== 1) {
                $skipped[] = ['member' => $member, 'reason' => 'dump name does not map to this account'];
                continue;
            }
            if ($only !== [] && !in_array($stem, $only, true)) {
                $skipped[] = ['member' => $member, 'reason' => 'not requested'];
                continue;
            }
            if (isset($seen[$stem])) {
                $skipped[] = ['member' => $member, 'reason' => 'duplicate for database ' . $stem];
                continue;
            }
            $seen[$stem] = true;
            $dumps[] = ['member' => $member, 'suffix' => $stem, 'file' => $base, 'gzip' => $gzip];
        }

        return ['dumps' => $dumps, 'skipped' => $skipped];
    }

    /**
     * Write the dump that the client will actually run:
     * `USE \`db\`;` + the archive's dump with any `USE` line removed.
     *
     * @return array{bytes: int, lines: int}
     */
    public static function prepare(string $source, string $target, string $database): array
    {
        $size = filesize($source);
        if (!is_int($size) || $size < 1) {
            throw new TaskRejectedException('cPanel MySQL dump is empty');
        }
        if ($size > self::MAX_DUMP_BYTES) {
            throw new TaskRejectedException('cPanel MySQL dump is too large to restore');
        }

        $in = str_ends_with($source, '.gz') ? @gzopen($source, 'rb') : @fopen($source, 'rb');
        if ($in === false) {
            throw new TaskRejectedException('cPanel MySQL dump could not be read');
        }
        $out = @fopen($target, 'wb');
        if ($out === false) {
            if (str_ends_with($source, '.gz')) {
                gzclose($in);
            } else {
                fclose($in);
            }

            throw new TaskRejectedException('prepared dump could not be written');
        }

        $bytes = 0;
        $lines = 0;
        try {
            fwrite($out, 'USE ' . MysqlServer::quoteIdentifier($database) . ";\n");
            while (true) {
                $line = str_ends_with($source, '.gz') ? gzgets($in, self::SCAN_BYTES) : fgets($in, self::SCAN_BYTES);
                if ($line === false || $line === '') {
                    break;
                }
                $lines++;
                $sanitized = self::sanitize($line, $database);
                if ($sanitized === null) {
                    continue; // statement about our own database (we manage it)
                }
                fwrite($out, $sanitized);
                $bytes += strlen($sanitized);
            }
        } finally {
            if (str_ends_with($source, '.gz')) {
                gzclose($in);
            } else {
                fclose($in);
            }
            fclose($out);
        }

        if ($bytes === 0) {
            throw new TaskRejectedException('cPanel MySQL dump contained no statements');
        }

        return ['bytes' => $bytes, 'lines' => $lines];
    }

    /**
     * Returns the line to write, or null when the whole line must be dropped
     * (a statement about the target database, which the agent owns).
     */
    private static function sanitize(string $line, string $database): ?string
    {
        $head = substr($line, 0, self::SCAN_BYTES);
        $upper = strtoupper($head);
        foreach (self::FORBIDDEN as $token) {
            if (str_contains($upper, $token)) {
                throw new TaskRejectedException('cPanel MySQL dump refused: contains ' . trim($token));
            }
        }

        // Any DROP/CREATE/ALTER DATABASE or USE statement in this line must name
        // OUR database; ours are dropped (the agent creates + selects it), any
        // other name refuses the dump.
        if (preg_match_all(self::SELF_SCOPED, $head, $matches) > 0) {
            foreach ($matches[1] as $name) {
                if (strcasecmp($name, $database) !== 0) {
                    throw new TaskRejectedException('cPanel MySQL dump refused: it names another database');
                }
            }
        }
        if (preg_match_all('/\bUSE\s+[`\'"]?([A-Za-z0-9_$]+)/i', $head, $uses) > 0) {
            foreach ($uses[1] as $name) {
                if (strcasecmp($name, $database) !== 0) {
                    throw new TaskRejectedException('cPanel MySQL dump refused: it selects another database');
                }
            }
        }

        $trimmed = ltrim($line);
        if (stripos($trimmed, 'USE ') === 0) {
            return null; // our own database — already selected
        }
        if (preg_match(self::SELF_SCOPED, $trimmed) === 1 && strpos($trimmed, ';') !== false) {
            return null; // whole-line DROP/CREATE DATABASE <ours>;
        }

        return $line;
    }
}
