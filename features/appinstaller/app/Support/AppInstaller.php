<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * cPanel "Site Software" — one-click app installs (WordPress first).
 * System ops (mysql/curl/tar/chown) via Process; wp-config via File.
 * Tests fake Process and point account home at a temp dir.
 */
final class AppInstaller
{
    /** @return array<int, array{id:string,name:string,desc:string}> */
    public static function catalog(): array
    {
        return [
            ['id' => 'wordpress', 'name' => 'WordPress', 'desc' => 'Blog / CMS / WooCommerce'],
            ['id' => 'joomla',    'name' => 'Joomla',    'desc' => 'CMS (jald aa raha hai)'],
            ['id' => 'drupal',    'name' => 'Drupal',    'desc' => 'CMS (jald aa raha hai)'],
        ];
    }

    /** @return array<string, mixed> */
    public static function installWordPress(Account $account, string $dbPassword): array
    {
        $home = rtrim($account->home_path, '/') . '/public_html';
        $db   = strtolower($account->username) . '_wp';

        File::makeDirectory($home, 0755, true, true);

        Process::run(sprintf(
            "mysql -e \"CREATE DATABASE IF NOT EXISTS %s; CREATE USER IF NOT EXISTS '%s'@'localhost' IDENTIFIED BY '%s'; GRANT ALL ON %s.* TO '%s'@'localhost'; FLUSH PRIVILEGES;\"",
            $db, $db, $dbPassword, $db, $db
        ));

        Process::run('curl -sL -o /tmp/wordpress.tar.gz https://wordpress.org/latest.tar.gz');
        Process::run('tar -xzf /tmp/wordpress.tar.gz -C ' . escapeshellarg($home) . ' --strip-components=1');

        File::put($home . '/wp-config.php', self::wpConfig($db, $db, $dbPassword));

        Process::run('chown -R ' . $account->username . ':' . $account->username . ' ' . escapeshellarg($home));

        return ['ok' => true, 'app' => 'wordpress', 'db' => $db, 'home' => $home];
    }

    private static function wpConfig(string $db, string $user, string $pass): string
    {
        return "<?php\n"
            . "define( 'DB_NAME', '" . $db . "' );\n"
            . "define( 'DB_USER', '" . $user . "' );\n"
            . "define( 'DB_PASSWORD', '" . $pass . "' );\n"
            . "define( 'DB_HOST', 'localhost' );\n"
            . "define( 'WP_DEBUG', false );\n";
    }
}
