<?php

declare(strict_types=1);

namespace App\Support;

/**
 * cPanel-compatible package limit keys (docs/00-requirements-freeze.md).
 * -1 = unlimited. Keep this list in sync with the packages table.
 */
final class PackageLimits
{
    /** @var list<string> */
    public const KEYS = [
        'QUOTA', 'BWLIMIT', 'MAXPOP', 'MAXFWD', 'MAXRESP', 'MAXPASS', 'MAXLST',
        'MAXFTP', 'MAXSQL', 'MAXSUB', 'MAXPARK', 'MAXADDON', 'MAXCRON', 'MAXINODE',
        'MAILBOXQUOTA', 'DBQUOTA', 'MAXEMAILPERHOUR', 'MAXMSGSIZE',
        'CPULIMIT', 'RAMLIMIT', 'IOLIMIT', 'NPROCLIMIT', 'EPLIMIT',
    ];

    /** @var array<string, string> */
    public const LABELS = [
        'QUOTA' => 'Disk quota (MB)',
        'BWLIMIT' => 'Bandwidth / month (MB)',
        'MAXPOP' => 'Max email accounts',
        'MAXFWD' => 'Max forwarders',
        'MAXRESP' => 'Max autoresponders',
        'MAXPASS' => 'Max mailing lists (mailman)',
        'MAXLST' => 'Max lists',
        'MAXFTP' => 'Max FTP accounts',
        'MAXSQL' => 'Max databases',
        'MAXSUB' => 'Max subdomains',
        'MAXPARK' => 'Max aliases (parked)',
        'MAXADDON' => 'Max addon domains',
        'MAXCRON' => 'Max cron jobs',
        'MAXINODE' => 'Max inodes',
        'MAILBOXQUOTA' => 'Mailbox quota (MB)',
        'DBQUOTA' => 'Database quota (MB)',
        'MAXEMAILPERHOUR' => 'Max emails / hour',
        'MAXMSGSIZE' => 'Max message size (MB)',
        'CPULIMIT' => 'CPU limit (%)',
        'RAMLIMIT' => 'RAM limit (MB)',
        'IOLIMIT' => 'IO limit (MB/s)',
        'NPROCLIMIT' => 'Max processes',
        'EPLIMIT' => 'Max entry processes',
    ];

    /** @var array<string, int> */
    public const DEFAULTS = [
        'QUOTA' => 1024,
        'BWLIMIT' => -1,
        'MAXPOP' => -1,
        'MAXFWD' => -1,
        'MAXRESP' => -1,
        'MAXPASS' => -1,
        'MAXLST' => -1,
        'MAXFTP' => -1,
        'MAXSQL' => -1,
        'MAXSUB' => -1,
        'MAXPARK' => -1,
        'MAXADDON' => -1,
        'MAXCRON' => -1,
        'MAXINODE' => -1,
        'MAILBOXQUOTA' => -1,
        'DBQUOTA' => -1,
        'MAXEMAILPERHOUR' => 300,
        'MAXMSGSIZE' => 50,
        'CPULIMIT' => -1,
        'RAMLIMIT' => -1,
        'IOLIMIT' => -1,
        'NPROCLIMIT' => -1,
        'EPLIMIT' => -1,
    ];

    /** @var array<string, string> */
    public const FEATURES = [
        'files' => 'File Manager / FTP',
        'email' => 'Email',
        'domains' => 'Addon / sub / parked domains',
        'databases' => 'MySQL databases',
        'dns' => 'DNS / Zone Editor',
        'ssl' => 'SSL / AutoSSL',
        'cron' => 'Cron jobs',
        'backup' => 'Backup',
        'stats' => 'Awstats / bandwidth',
    ];

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        $rules = [];
        foreach (self::KEYS as $key) {
            $rules[$key] = ['required', 'integer', 'min:-1', 'max:10485760'];
        }
        return $rules;
    }

    /** @return array<string, true> */
    public static function defaultFeatures(): array
    {
        $out = [];
        foreach (array_keys(self::FEATURES) as $key) {
            $out[$key] = true;
        }
        return $out;
    }
}
