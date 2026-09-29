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
        Options -Indexes +FollowSymLinks
    </Directory>

    <FilesMatch "\\.php\$">
        SetHandler "proxy:unix:{$sock}|fcgi://localhost"
    </FilesMatch>

    ErrorLog {$logs}/error.log
    CustomLog {$logs}/access.log combined
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

    public static function pool(string $username, string $home, string $socketName): string
    {
        $sock = '/run/php/' . $socketName;

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

CONF;
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
