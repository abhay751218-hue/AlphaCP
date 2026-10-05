<?php
declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * Pure (no filesystem, no shell) analysis of a cPanel account archive listing.
 *
 * Two real cPanel layouts are understood:
 *
 *   cpmove-<user>.tar.gz   →  cpmove-<user>/homedir/public_html/…
 *   backup-*.tar.gz        →  homedir/public_html/…            (legacy full backup)
 *
 * and both direct homes and the older nested `homedir/homedir.tar` form.
 *
 * Everything here only *reads* a `tar --list` output: the caller keeps the
 * privilege (extraction/swapping) in BackupArchiveStore. Path safety rules
 * mirror the restore path: no absolute paths, no `..`, no backslashes, no
 * NUL, nothing outside the account root.
 */
final class CpanelArchive
{
    public const HOME = 'homedir';
    public const NESTED = 'homedir.tar';
    public const MAX_ENTRIES = 500_000;

    /**
     * @return array{layout:string,root:string,member:string,nested_member:?string,entries:int,sections:list<string>,section_entries:array<string,int>}
     */
    public static function structure(string $listing, string $username): array
    {
        $entries = self::entries($listing);

        $rootName = 'cpmove-' . $username;
        $firsts = [];
        foreach ($entries as $entry) {
            $segments = $entry['segments'];
            if ($segments === []) {
                continue; // the archive root entry itself (`./` or `cpmove-user/`)
            }
            $firsts[$segments[0]] = true;
        }
        if ($firsts === []) {
            throw new TaskRejectedException('cPanel archive is empty');
        }

        $root = '';
        if (isset($firsts[$rootName])) {
            if (count($firsts) > 1) {
                throw new TaskRejectedException('cPanel archive mixes several account roots');
            }
            $root = $rootName;
            foreach (array_keys($firsts) as $first) {
                if (str_starts_with($first, 'cpmove-') && $first !== $rootName) {
                    throw new TaskRejectedException("cPanel archive belongs to another account ({$first})");
                }
            }
        } else {
            foreach (array_keys($firsts) as $first) {
                if (str_starts_with($first, 'cpmove-')) {
                    throw new TaskRejectedException("cPanel archive belongs to another account ({$first})");
                }
            }
        }

        $sections = [];
        $sectionEntries = [];
        $homeFiles = 0;
        $homeDirs = 0;
        $homeNested = null;
        $rootNested = false;
        foreach ($entries as $entry) {
            $segments = $entry['segments'];
            if ($root !== '') {
                if ($segments === [] || $segments[0] !== $root) {
                    $segments = [];
                } else {
                    array_shift($segments);
                }
            }
            if ($segments === []) {
                continue; // root entry / `./`
            }
            $section = $segments[0];
            $sections[$section] = true;
            $sectionEntries[$section] = ($sectionEntries[$section] ?? 0) + 1;
            if ($section === self::HOME) {
                if ($entry['is_dir']) {
                    $homeDirs++;
                } elseif (count($segments) === 2 && $segments[1] === self::NESTED) {
                    $homeNested = ($root === '' ? '' : $root . '/') . self::HOME . '/' . self::NESTED;
                } else {
                    $homeFiles++;
                }
            } elseif ($section === self::NESTED && count($segments) === 1) {
                $rootNested = true;
            }
        }
        if ($rootNested && !isset($sections[self::HOME])) {
            // legacy `homedir.tar` at the archive root
            $member = $root === '' ? self::NESTED : $root . '/' . self::NESTED;
            $sections = array_values(array_diff(array_keys($sections), [self::NESTED]));
            sort($sections);

            return [
                'layout' => 'nested',
                'root' => $root,
                'member' => $member,
                'nested_member' => $member,
                'entries' => count($entries),
                'sections' => $sections,
                'section_entries' => self::dropSection($sectionEntries, self::NESTED),
            ];
        }
        $nested = ($homeNested !== null && $homeFiles === 0) ? $homeNested : null;
        if ($nested === null && $homeDirs === 0 && $homeFiles === 0) {
            throw new TaskRejectedException('cPanel archive has no home directory');
        }

        $sections = array_values(array_diff(array_keys($sections), [self::HOME]));
        sort($sections);

        $prefix = $root === '' ? '' : $root . '/';

        return [
            'layout' => $nested === null ? 'direct' : 'nested',
            'root' => $root,
            'member' => $nested ?? ($prefix . self::HOME),
            'nested_member' => $nested,
            'entries' => count($entries),
            'sections' => $sections,
            'section_entries' => self::dropSection($sectionEntries, self::HOME),
        ];
    }

    /**
     * Counts for a `tar --list --verbose` output that only contains home
     * entries; the entry *types* are checked here, so a hardlink or device
     * node inside the home can never reach the extractor.
     *
     * @return array{files:int,dirs:int,bytes:int}
     */
    public static function homeCounts(string $verboseListing): array
    {
        $files = 0;
        $dirs = 0;
        $bytes = 0;
        $lines = 0;
        foreach (self::lines($verboseListing) as $line) {
            $lines++;
            if ($lines > self::MAX_ENTRIES) {
                throw new TaskRejectedException('cPanel archive has too many entries');
            }
            $type = $line[0];
            if (in_array($type, ['h', 'b', 'c', 'p', 's'], true)) {
                throw new TaskRejectedException('cPanel archive contains a hardlink or special file; refusing to import');
            }
            if (!in_array($type, ['-', 'd', 'l'], true)) {
                throw new TaskRejectedException('cPanel archive contains an unexpected entry type');
            }
            if ($type === 'd') {
                $dirs++;
            } else {
                $files++;
            }
            $tokens = preg_split('/\s+/', trim($line)) ?: [];
            $size = $tokens[2] ?? null;
            if (is_string($size) && ctype_digit($size)) {
                $value = (int) $size;
                if ($value > PHP_INT_MAX - $bytes) {
                    throw new TaskRejectedException('cPanel archive size cannot be summed safely');
                }
                $bytes += $value;
            }
        }
        if ($lines === 0) {
            throw new TaskRejectedException('cPanel archive home directory is empty');
        }

        return ['files' => $files, 'dirs' => $dirs, 'bytes' => $bytes];
    }

    /**
     * Refuses archives that could write OUTSIDE the extraction directory even
     * though every member name looks safe: a symlink entry (`link -> /etc`)
     * followed by an entry below that symlink (`link/passwd`) makes tar follow
     * the link as root. Both listings are printed by the same tar run in
     * archive order, so line N of `--list` and line N of `--list --verbose`
     * describe the same entry.
     */
    public static function assertNoSymlinkTraversal(string $plainListing, string $verboseListing): void
    {
        $names = self::lines($plainListing);
        $verbose = self::lines($verboseListing);
        if (count($names) !== count($verbose)) {
            throw new TaskRejectedException('cPanel archive listing is inconsistent; refusing to import');
        }
        $links = [];
        $paths = [];
        foreach ($names as $index => $raw) {
            $line = $verbose[$index];
            $type = $line[0];
            $path = self::normalizePath($raw);
            if ($path === null) {
                continue;
            }
            $paths[] = $path;
            if ($type !== 'l') {
                continue;
            }
            $arrow = strrpos($line, ' -> ');
            if ($arrow === false) {
                throw new TaskRejectedException('cPanel archive symlink target could not be verified');
            }
            $target = trim(substr($line, $arrow + 4));
            if ($target === '' || str_contains($target, "\0")) {
                throw new TaskRejectedException('cPanel archive symlink target could not be verified');
            }
            // An absolute or `..`-relative target is inert as long as nothing is
            // extracted *below* the link — and the ancestor pass below refuses
            // exactly that, whatever the target points at.
            $links[$path] = true;
        }
        foreach ($paths as $path) {
            $cut = strrpos($path, '/');
            while ($cut !== false) {
                $path = substr($path, 0, $cut);
                if (isset($links[$path])) {
                    throw new TaskRejectedException('cPanel archive writes through a symlink; refusing to import');
                }
                $cut = strrpos($path, '/');
            }
        }
    }

    /** @param array<string, int> $counts @return array<string, int> */
    private static function dropSection(array $counts, string $drop): array
    {
        unset($counts[$drop]);
        ksort($counts);

        return $counts;
    }

    /**
     * @return list<array{name:string,segments:list<string>,is_dir:bool}>
     */
    private static function entries(string $listing): array
    {
        $out = [];
        $lines = 0;
        foreach (self::lines($listing) as $line) {
            $lines++;
            if ($lines > self::MAX_ENTRIES) {
                throw new TaskRejectedException('cPanel archive has too many entries');
            }
            $path = self::normalizePath($line);
            if ($path === null) {
                continue; // the archive/home root itself
            }
            $out[] = [
                'name' => $line,
                'segments' => explode('/', $path),
                'is_dir' => str_ends_with($line, '/'),
            ];
        }
        if ($out === []) {
            throw new TaskRejectedException('cPanel archive is empty');
        }

        return $out;
    }

    /** One tar member name → safe relative path, or null for the root entry. */
    private static function normalizePath(string $raw): ?string
    {
        if (str_contains($raw, "\0") || str_contains($raw, '\\')) {
            throw new TaskRejectedException('cPanel archive contains an unsafe path');
        }
        if (str_starts_with($raw, '/')) {
            throw new TaskRejectedException('cPanel archive contains an absolute path');
        }
        $name = $raw;
        while (str_starts_with($name, './')) {
            $name = substr($name, 2);
        }
        if ($name === '.' || $name === '') {
            return null; // the archive root (`./`)
        }
        if (str_ends_with($name, '/')) {
            $name = substr($name, 0, -1);
        }
        if ($name === '') {
            throw new TaskRejectedException('cPanel archive contains an unsafe path');
        }
        foreach (explode('/', $name) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new TaskRejectedException('cPanel archive contains a path escape');
            }
        }

        return $name;
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', rtrim($text, "\n")) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $out[] = $line;
        }

        return $out;
    }
}
