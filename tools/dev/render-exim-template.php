#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * DEV TOOL (server par deploy nahi hota) — AlphaCP ka exim template render karke
 * stdout par dump karta hai, taaki use **asli exim** (`exim -C file -bV`) se
 * validate kiya ja sake sandbox me.
 *
 *   php tools/dev/render-exim-template.php > /tmp/acp-exim.conf
 *   /path/to/exim -C /tmp/acp-exim.conf -bV
 *
 * Paths env se milte hain (jaise agent/tests/run-tests.php ka harness karta hai).
 */

require __DIR__ . '/../../agent/src/Bootstrap.php';

use Alphacp\Agent\CommandRunner;
use Alphacp\Agent\MailServer;
use Alphacp\Agent\TaskLogger;

$root = getenv('ACP_RENDER_ROOT') ?: '/tmp/acp-exim-render';
foreach ([
    $root . '/etc/exim4', $root . '/etc/exim4/vacation', $root . '/etc/exim4/spam',
    $root . '/etc/dovecot/conf.d', $root . '/alphacp/etc/mail/dkim', $root . '/var/log/exim4',
] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

$map = [
    'ACP_MAIL_EXIM_TEMPLATE'   => $root . '/etc/exim4/exim4.conf.template',
    'ACP_MAIL_EXIM_DOMAINS'    => $root . '/etc/exim4/alphacp-domains',
    'ACP_MAIL_EXIM_RECIPIENTS' => $root . '/etc/exim4/alphacp-recipients',
    'ACP_MAIL_EXIM_ALIASES'    => $root . '/etc/exim4/alphacp-aliases',
    'ACP_MAIL_FILTERS'         => $root . '/etc/exim4/alphacp-filters',
    'ACP_MAIL_CATCHALL'        => $root . '/etc/exim4/alphacp-catchall',
    'ACP_MAIL_VACATION_DIR'    => $root . '/etc/exim4/vacation',
    'ACP_MAIL_SPAM_DIR'        => $root . '/etc/exim4/spam',
    'ACP_MAIL_DKIM_DIR'        => $root . '/alphacp/etc/mail/dkim',
    'ACP_MAIL_DOVECOT_USERS'   => $root . '/etc/dovecot/alphacp-users',
    'ACP_MAIL_DOVECOT_CONF'    => $root . '/etc/dovecot/conf.d/99-alphacp.conf',
    // allowlist me maujood paths (binary maujood na ho to capabilities() false dega)
    'ACP_MAIL_EXIM'            => '/usr/sbin/exim4',
    'ACP_MAIL_DOVECOT'         => '/usr/sbin/dovecot',
    'ACP_MAIL_DOVEADM'         => '/usr/bin/doveadm',
    'ACP_MAIL_DOVECONF'        => '/usr/sbin/doveconf',
    'ACP_MAIL_UPDATE_EXIM'     => '/usr/sbin/update-exim4.conf',
    'ACP_MAIL_EXIM_OPTIONS'    => $root . '/alphacp/etc/mail/exim-options.json',
    'ACP_MAIL_DOVECOT_OPTIONS' => $root . '/alphacp/etc/mail/dovecot-options.json',
    'ACP_MAIL_MAINLOG'         => $root . '/var/log/exim4/mainlog',
    'ACP_STATE_ROOT'           => $root . '/alphacp',
    'ACP_ACCOUNTS_ROOT'        => $root . '/home',
];
foreach ($map as $name => $value) {
    putenv($name . '=' . $value);
}

$log = new TaskLogger(new PDO('sqlite::memory:'), null, false);
$server = new MailServer(new CommandRunner(), $log);

$method = new ReflectionMethod(MailServer::class, 'renderEximTemplate');

echo (string) $method->invoke($server);
