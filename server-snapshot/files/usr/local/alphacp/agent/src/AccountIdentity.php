<?php
declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * Hosting-account username / domain rules (cPanel-like, fails closed).
 *
 * Duplicated on the panel as App\Support\AccountIdentity — keep both in sync
 * (docs/modules/accounts.md). Agent never trusts the panel: it re-validates.
 */
final class AccountIdentity
{
    public const USERNAME_PATTERN = '^[a-z][a-z0-9]{2,15}$';
    public const DOMAIN_PATTERN = '^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z]{2,63}$';

    /** @var list<string> */
    public const RESERVED = [
        'root', 'bin', 'daemon', 'sys', 'sync', 'games', 'man', 'mail', 'news',
        'uucp', 'proxy', 'www-data', 'www', 'backup', 'list', 'irc', 'gnats',
        'nobody', 'systemd-network', 'systemd-resolve', 'systemd-timesync',
        '_apt', 'messagebus', 'sshd', 'uuidd', 'tcpdump', 'landscape',
        'pollinate', 'fwupd', 'snapd', 'lxd', 'syslog', 'admin', 'ubuntu',
        'alphacp', 'mysql', 'mariadb', 'redis', 'nginx', 'apache', 'apache2',
        'httpd', 'named', 'bind', 'bind9', 'postfix', 'exim', 'exim4',
        'dovecot', 'clamav', 'spamd', 'opendkim', 'fail2ban', 'ftp', 'ntp',
        'debian-tor', 'debian-exim', 'statd', 'halt', 'shutdown', 'php',
        'php-fpm', 'fpm', 'panel', 'paneld', 'operator', 'guest', 'testuser',
    ];

    public static function username(string $username): ?string
    {
        if (preg_match('/' . self::USERNAME_PATTERN . '/', $username) !== 1) {
            return 'username must be 3-16 chars: lowercase letter, then a-z/0-9';
        }
        if (in_array($username, self::RESERVED, true)) {
            return "username '{$username}' is reserved";
        }
        return null;
    }

    public static function domain(string $domain): ?string
    {
        if (strlen($domain) > 190) {
            return 'domain too long';
        }
        if (preg_match('/' . self::DOMAIN_PATTERN . '/', $domain) !== 1) {
            return 'domain is not a valid FQDN';
        }
        return null;
    }

    public static function phpVersion(string $version): ?string
    {
        if (preg_match('/^8\.[0-9]$/', $version) !== 1) {
            return 'php_version must look like 8.4';
        }
        return null;
    }
}
