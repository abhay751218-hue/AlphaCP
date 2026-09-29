<?php
declare(strict_types=1);

namespace Alphacp\Agent;

/**
 * Where an account's OS objects live. Overridable via env for tests/sim.
 * Production defaults match Ubuntu 24.04 + Apache + Ondrej PHP-FPM.
 */
final class AccountPaths
{
    public function __construct(
        public readonly string $accountsRoot,
        public readonly string $apacheSites,
        public readonly string $apacheEnabled,
        public readonly string $phpPoolDir,
        public readonly string $suspendedRoot,
        public readonly string $phpFpmService,
        public readonly string $apacheService,
        public readonly string $nologin,
        public readonly string $phpVersion,
    ) {
    }

    public static function fromEnv(?string $phpVersion = null): self
    {
        $php = $phpVersion ?: self::detectPhpVersion();
        $nologin = getenv('ACP_NOLOGIN') ?: '';
        if ($nologin === '') {
            $nologin = is_file('/usr/sbin/nologin') ? '/usr/sbin/nologin' : '/sbin/nologin';
        }

        return new self(
            accountsRoot: rtrim((string) (getenv('ACP_ACCOUNTS_ROOT') ?: '/home'), '/'),
            apacheSites: rtrim((string) (getenv('ACP_APACHE_SITES') ?: '/etc/apache2/sites-available'), '/'),
            apacheEnabled: rtrim((string) (getenv('ACP_APACHE_ENABLED') ?: '/etc/apache2/sites-enabled'), '/'),
            phpPoolDir: rtrim((string) (getenv('ACP_PHP_POOL_DIR') ?: "/etc/php/{$php}/fpm/pool.d"), '/'),
            suspendedRoot: rtrim((string) (getenv('ACP_SUSPENDED_ROOT') ?: '/usr/local/alphacp/share/suspended'), '/'),
            phpFpmService: (string) (getenv('ACP_PHP_FPM_SERVICE') ?: "php{$php}-fpm"),
            apacheService: (string) (getenv('ACP_APACHE_SERVICE') ?: 'apache2'),
            nologin: $nologin,
            phpVersion: $php,
        );
    }

    public function home(string $username): string
    {
        return $this->accountsRoot . '/' . $username;
    }

    public function vhost(string $username): string
    {
        return $this->apacheSites . '/acp-' . $username . '.conf';
    }

    public function vhostEnabled(string $username): string
    {
        return $this->apacheEnabled . '/acp-' . $username . '.conf';
    }

    public function pool(string $username): string
    {
        return $this->phpPoolDir . '/acp-' . $username . '.conf';
    }

    public function poolDisabled(string $username): string
    {
        return $this->phpPoolDir . '/acp-' . $username . '.conf.suspended';
    }

    public function socketName(string $username): string
    {
        return 'acp-' . $username . '.sock';
    }

    public static function detectPhpVersion(): string
    {
        $env = getenv('ACP_PHP_VERSION') ?: '';
        if (is_string($env) && preg_match('/^8\.[0-9]$/', $env) === 1) {
            return $env;
        }
        foreach (['8.4', '8.3', '8.2', '8.1'] as $version) {
            if (is_dir("/etc/php/{$version}/fpm/pool.d")) {
                return $version;
            }
        }
        return '8.4';
    }
}
