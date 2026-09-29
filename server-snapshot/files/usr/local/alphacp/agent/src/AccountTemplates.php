<?php
declare(strict_types=1);

namespace Alphacp\Agent;

/** Apache vhost + PHP-FPM pool text. Username/domain are pre-validated. */
final class AccountTemplates
{
    public static function vhost(string $username, string $domain, string $home, string $docroot, string $socketName): string
    {
        $logs = $home . '/logs';
        $sock = '/run/php/' . $socketName;

        return <<<CONF
# AlphaCP account vhost — managed file, do not edit by hand
<VirtualHost *:80>
    ServerName {$domain}
    ServerAlias www.{$domain}
    ServerAdmin {$username}@{$domain}
    DocumentRoot {$docroot}

    <Directory {$docroot}>
        AllowOverride All
        Require all granted
        Options +FollowSymLinks
    </Directory>

    <FilesMatch "\\.php\$">
        SetHandler "proxy:unix:{$sock}|fcgi://localhost"
    </FilesMatch>

    IncludeOptional {$home}/etc/indexes.conf
    IncludeOptional {$home}/etc/errorpages.conf
    IncludeOptional {$home}/etc/mime.conf
    IncludeOptional {$home}/etc/handlers.conf
    IncludeOptional {$home}/etc/privacy.conf

    ErrorLog {$logs}/error.log
    CustomLog {$logs}/access.log combined
</VirtualHost>

CONF;
    }

    public static function sslVhost(
        string $username,
        string $domain,
        string $home,
        string $docroot,
        string $socketName,
        string $cert,
        string $key,
    ): string {
        $logs = $home . '/logs';
        $sock = '/run/php/' . $socketName;

        return <<<CONF
# AlphaCP SSL vhost — managed file, do not edit by hand
<VirtualHost *:443>
    ServerName {$domain}
    ServerAlias www.{$domain}
    ServerAdmin {$username}@{$domain}
    DocumentRoot {$docroot}
    SSLEngine on
    SSLCertificateFile {$cert}
    SSLCertificateKeyFile {$key}

    <Directory {$docroot}>
        AllowOverride All
        Require all granted
        Options +FollowSymLinks
    </Directory>

    <FilesMatch "\\.php\$">
        SetHandler "proxy:unix:{$sock}|fcgi://localhost"
    </FilesMatch>

    IncludeOptional {$home}/etc/indexes.conf
    IncludeOptional {$home}/etc/errorpages.conf
    IncludeOptional {$home}/etc/mime.conf
    IncludeOptional {$home}/etc/handlers.conf
    IncludeOptional {$home}/etc/privacy.conf

    ErrorLog {$logs}/ssl-error.log
    CustomLog {$logs}/ssl-access.log combined
</VirtualHost>

CONF;
    }

    public static function redirectVhost(string $username, string $domain, string $target, int $code = 301): string
    {
        $code = $code === 302 ? 302 : 301;

        return <<<CONF
# AlphaCP redirect vhost — managed file, do not edit by hand
<VirtualHost *:80>
    ServerName {$domain}
    ServerAlias www.{$domain}
    ServerAdmin {$username}@{$domain}
    Redirect {$code} / {$target}
</VirtualHost>

CONF;
    }

    public static function suspendedVhost(string $username, string $domain, string $suspendedRoot): string
    {
        return <<<CONF
# AlphaCP SUSPENDED account vhost — managed file
<VirtualHost *:80>
    ServerName {$domain}
    ServerAlias www.{$domain}
    DocumentRoot {$suspendedRoot}

    <Directory {$suspendedRoot}>
        AllowOverride None
        Require all granted
        Options -Indexes
    </Directory>

    ErrorLog /var/log/apache2/acp-{$username}-suspended-error.log
    CustomLog /var/log/apache2/acp-{$username}-suspended-access.log combined
</VirtualHost>

CONF;
    }

    /** @param array<string, string> $directives */
    public static function pool(string $username, string $home, string $socketName, array $directives = []): string
    {
        $sock = '/run/php/' . $socketName;
        $extra = '';
        foreach ($directives as $key => $value) {
            $extra .= PhpIni::poolLine($key, $value) . "\n";
        }

        return <<<CONF
; AlphaCP account pool — managed file, do not edit by hand
[{$username}]
user = {$username}
group = {$username}
listen = {$sock}
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 10
pm.process_idle_timeout = 10s
php_admin_value[open_basedir] = {$home}:/tmp
php_admin_value[upload_tmp_dir] = {$home}/tmp
php_admin_value[session.save_path] = {$home}/tmp
php_admin_flag[allow_url_fopen] = on
{$extra}
CONF;
    }

    /**
     * @param list<array{path: string, realm: string, slug: string, users: list<array{name: string, hash: string}>}> $entries
     */
    public static function privacyConf(string $home, array $entries): string
    {
        $lines = ['# AlphaCP directory privacy — managed file, do not edit by hand'];
        foreach ($entries as $row) {
            $dir = $home . '/' . $row['path'];
            $htpasswd = $home . '/etc/privacy/' . $row['slug'] . '.htpasswd';
            $realm = str_replace('"', '', $row['realm']);
            $lines[] = '<Directory ' . $dir . '>';
            $lines[] = '    AuthType Basic';
            $lines[] = '    AuthName "' . $realm . '"';
            $lines[] = '    AuthUserFile ' . $htpasswd;
            $lines[] = '    Require valid-user';
            $lines[] = '</Directory>';
        }

        return implode("\n", $lines) . "\n";
    }

    /** @param list<array{handler: string, ext: string}> $mappings */
    public static function handlersConf(array $mappings): string
    {
        $lines = ['# AlphaCP apache handlers — managed file, do not edit by hand'];
        foreach ($mappings as $row) {
            $lines[] = 'AddHandler ' . $row['handler'] . ' .' . $row['ext'];
        }

        return implode("\n", $lines) . "\n";
    }

    /** @param list<array{mime: string, ext: string}> $mappings */
    public static function mimeConf(array $mappings): string
    {
        $lines = ['# AlphaCP mime types — managed file, do not edit by hand'];
        foreach ($mappings as $row) {
            $lines[] = 'AddType ' . $row['mime'] . ' .' . $row['ext'];
        }

        return implode("\n", $lines) . "\n";
    }

    public static function indexesConf(string $home, string $mode): string
    {
        $pattern = preg_quote($home, '/');
        $options = $mode === 'off'
            ? 'Options -Indexes +FollowSymLinks'
            : 'Options +Indexes +FollowSymLinks';
        $indexOpts = match ($mode) {
            'fancy' => "    IndexOptions FancyIndexing HTMLTable NameWidth=*\n",
            'simple' => "    IndexOptions NameWidth=*\n",
            default => '',
        };

        return <<<CONF
# AlphaCP indexes — managed file, do not edit by hand
<DirectoryMatch "^{$pattern}/">
    {$options}
{$indexOpts}</DirectoryMatch>

CONF;
    }

    /** @param list<string> $codes */
    public static function errorpagesConf(string $home, array $codes): string
    {
        $dir = $home . '/errorpages';
        $lines = [
            '# AlphaCP error pages — managed file, do not edit by hand',
            "Alias /acp-errorpages {$dir}",
            "<Directory {$dir}>",
            '    AllowOverride None',
            '    Require all granted',
            '    Options -Indexes',
            '</Directory>',
        ];
        foreach ($codes as $code) {
            $lines[] = "ErrorDocument {$code} /acp-errorpages/{$code}.html";
        }
        return implode("\n", $lines) . "\n";
    }

    public static function welcomePage(string $domain): string
    {
        $safe = htmlspecialchars($domain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return "<!doctype html><html><head><meta charset=\"utf-8\"><title>{$safe}</title></head>"
            . "<body style=\"font-family:system-ui;padding:48px;background:#0f172a;color:#e8eefc\">"
            . "<h1>{$safe}</h1><p>Account AlphaCP ne bana diya. <code>public_html/</code> me files daalo.</p>"
            . "</body></html>\n";
    }

    public static function suspendedPage(): string
    {
        return "<!doctype html><html><head><meta charset=\"utf-8\"><title>Account suspended</title></head>"
            . "<body style=\"font-family:system-ui;padding:48px;background:#1b1020;color:#fca5a5\">"
            . "<h1>Account suspended</h1><p>This hosting account is suspended. Contact your provider.</p>"
            . "</body></html>\n";
    }
}
