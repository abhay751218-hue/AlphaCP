<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Hosting-account username / domain rules. Must stay in sync with
 * agent/src/AccountIdentity.php (docs/modules/accounts.md).
 */
final class AccountIdentity
{
    public const USERNAME_PATTERN = '/^[a-z][a-z0-9]{2,15}$/';
    public const DOMAIN_PATTERN = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

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

    public static function isReserved(string $username): bool
    {
        return in_array(strtolower($username), self::RESERVED, true);
    }
}
