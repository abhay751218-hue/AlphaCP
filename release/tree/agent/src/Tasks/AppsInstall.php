<?php

declare(strict_types=1);

namespace Alphacp\Agent\Tasks;

use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\MysqlServer;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskRejectedException;

/**
 * apps.install — cPanel "Site Software" one-click install (WordPress first).
 *
 * Saara system kaam root agent karta hai (web-FPM proc_open disabled — B1):
 *   1. `<home>/public_html` banata hai (SafeFs, PathGuard ke andar),
 *   2. MariaDB me `<account>_wp` database + user + grant (MysqlServer, secret stdin/SQL me),
 *   3. wordpress.org se tarball download (curl argv-only, account-home ke andar — koi
 *      shared-/tmp race nahi) + `tar -xzf --strip-components=1`,
 *   4. `wp-config.php` likhta hai (0640) aur public_html account ko chown -R.
 *
 * @acp-task apps.install
 */
final class AppsInstall extends DbTask
{
    private const WP_URL = 'https://wordpress.org/latest.tar.gz';

    public function handle(array $payload, TaskContext $ctx): array
    {
        $username = $this->account($payload, $ctx); // DbTask guard: payload['username']
        $app = strtolower(trim((string) ($payload['app'] ?? '')));
        if ($app !== 'wordpress') {
            throw new TaskRejectedException("app '{$app}' abhi supported nahi (sirf wordpress)");
        }
        $dbPassword = MysqlServer::password((string) ($payload['db_password'] ?? ''));

        $fs = new SafeFs($ctx->paths);
        $home = AccountPaths::fromEnv()->home($username);
        $pub = rtrim($home, '/') . '/public_html';
        $fs->assert($pub);
        $fs->mkdir($pub, 0755);

        $db = MysqlServer::accountName($username, 'wp', 'database');
        $server = $this->server($ctx);
        $this->ensureDatabase($server, $db, $dbPassword, $ctx);

        $tarball = rtrim($home, '/') . '/.alphacp-wp-' . bin2hex(random_bytes(6)) . '.tar.gz';
        $dl = $ctx->cmd->run(['/usr/bin/curl', '-sSL', '--retry', '2', '-o', $tarball, self::WP_URL], 180);
        if (!$dl->ok() || !$fs->isFile($tarball)) {
            throw new TaskRejectedException('wordpress download fail hua');
        }
        $untar = $ctx->cmd->run(
            ['/usr/bin/tar', '--extract', '--gzip', '--file', $tarball, '--directory', $pub, '--strip-components=1'],
            120
        );
        $fs->unlink($tarball);
        if (!$untar->ok()) {
            throw new TaskRejectedException('wordpress extract fail hua: ' . substr(trim($untar->stderr), 0, 200));
        }

        $fs->write($pub . '/wp-config.php', $this->wpConfig($db, $db, $dbPassword), 0640);
        $ctx->cmd->run(['/usr/bin/chown', '-R', $username . ':' . $username, $pub], 60);
        $ctx->log->info("wordpress installed for {$username} (db {$db})");

        return [
            'username' => $username,
            'app'      => 'wordpress',
            'db'       => $db,
            'home'     => $pub,
            'status'   => 'ok',
        ];
    }

    /** Idempotent DB+user+grant (cPanel `<account>_wp` naming). */
    private function ensureDatabase(MysqlServer $server, string $db, string $password, TaskContext $ctx): void
    {
        if (!$server->databaseExists($db)) {
            $server->createDatabase($db);
        }
        if (!$server->userExists($db)) {
            $server->sql(
                'CREATE USER ' . MysqlServer::quoteIdentifier($db) . '@' . MysqlServer::quoteIdentifier('localhost')
                . ' IDENTIFIED BY ' . MysqlServer::literal($password, 'db password') . ';'
            );
        }
        $server->sql(
            'GRANT ALL PRIVILEGES ON ' . MysqlServer::quoteIdentifier($db) . '.* TO '
            . MysqlServer::quoteIdentifier($db) . '@' . MysqlServer::quoteIdentifier('localhost') . ';'
        );
        $server->sql('FLUSH PRIVILEGES;');
        $ctx->log->info("MariaDB {$db} ready for wordpress");
    }

    private function wpConfig(string $db, string $user, string $pass): string
    {
        return "<?php\n"
            . "define( 'DB_NAME', '" . $db . "' );\n"
            . "define( 'DB_USER', '" . $user . "' );\n"
            . "define( 'DB_PASSWORD', '" . $pass . "' );\n"
            . "define( 'DB_HOST', 'localhost' );\n"
            . "define( 'WP_DEBUG', false );\n";
    }
}
