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

    /** Extra (addon/sub/parked/redirect) vhost slug from a validated FQDN. */
    public function vhostSlug(string $domain): string
    {
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/', '-', $domain));
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'domain';
        }
        return substr($slug, 0, 80);
    }

    public function vhostExtra(string $username, string $domain): string
    {
        return $this->apacheSites . '/acp-' . $username . '-' . $this->vhostSlug($domain) . '.conf';
    }

    public function vhostExtraEnabled(string $username, string $domain): string
    {
        return $this->apacheEnabled . '/acp-' . $username . '-' . $this->vhostSlug($domain) . '.conf';
    }

    public function phpIniFile(string $username): string
    {
        return $this->home($username) . '/etc/php.ini';
    }

    public function errorpagesDir(string $username): string
    {
        return $this->home($username) . '/errorpages';
    }

    public function errorpagesConf(string $username): string
    {
        return $this->home($username) . '/etc/errorpages.conf';
    }

    public function indexesConf(string $username): string
    {
        return $this->home($username) . '/etc/indexes.conf';
    }

    public function mimeConf(string $username): string
    {
        return $this->home($username) . '/etc/mime.conf';
    }

    public function handlersConf(string $username): string
    {
        return $this->home($username) . '/etc/handlers.conf';
    }

    public function sslDir(string $username, string $domain): string
    {
        return $this->home($username) . '/ssl/' . $this->vhostSlug($domain);
    }

    public function leConfigDir(string $username): string
    {
        return $this->home($username) . '/ssl/letsencrypt';
    }

    public function vhostSsl(string $username, string $domain): string
    {
        return $this->apacheSites . '/acp-' . $username . '-' . $this->vhostSlug($domain) . '-ssl.conf';
    }

    public function vhostSslEnabled(string $username, string $domain): string
    {
        return $this->apacheEnabled . '/acp-' . $username . '-' . $this->vhostSlug($domain) . '-ssl.conf';
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
