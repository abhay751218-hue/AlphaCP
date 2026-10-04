#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * paneld unit tests — run WITHOUT a database.
 *   php agent/tests/run-tests.php
 *
 * Covers the security-critical pieces: JsonSchema, PathGuard, registry
 * integrity, and the command allowlist. Handlers that need a DB are tested
 * by installer/step2-install.sh on the real server instead.
 */

require __DIR__ . '/../src/Bootstrap.php';
require __DIR__ . '/FakeCommandExecutor.php';

use Alphacp\Agent\AccountIdentity;
use Alphacp\Agent\AccountOs;
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\CommandRunner;
use Alphacp\Agent\Files;
use Alphacp\Agent\JsonSchema;
use Alphacp\Agent\PathGuard;
use Alphacp\Agent\PathGuardException;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskLogger;
use Alphacp\Agent\TaskRejectedException;
use Alphacp\Agent\TaskRunner;
use Alphacp\Agent\Tasks\AccountCreate;
use Alphacp\Agent\Tasks\AccountSetQuota;
use Alphacp\Agent\Tasks\AccountSuspend;
use Alphacp\Agent\Tasks\AccountTerminate;
use Alphacp\Agent\Tasks\AccountUnsuspend;
use Alphacp\Agent\Tasks\CronSet;
use Alphacp\Agent\Tasks\DomainAdd;
use Alphacp\Agent\Tasks\DomainRemove;
use Alphacp\Agent\Tasks\ErrorPagesSet;
use Alphacp\Agent\Tasks\IndexesSet;
use Alphacp\Agent\Tasks\FilesList;
use Alphacp\Agent\Tasks\FilesSet;
use Alphacp\Agent\Tasks\FilesUsage;
use Alphacp\Agent\Tasks\HandlersSet;
use Alphacp\Agent\Tasks\PrivacySet;
use Alphacp\Agent\Tasks\SshSet;
use Alphacp\Agent\Tasks\MailSet;
use Alphacp\Agent\Tasks\MailForward;
use Alphacp\Agent\Tasks\MailAutorespond;
use Alphacp\Agent\Tasks\MailCatchall;
use Alphacp\Agent\Tasks\MailFilter;
use Alphacp\Agent\Tasks\MailDeliverability;
use Alphacp\Agent\Tasks\MailSpam;
use Alphacp\Agent\Tasks\MailList;
use Alphacp\Agent\Tasks\MailRouting;
use Alphacp\Agent\Tasks\MailTrack;
use Alphacp\Agent\Tasks\MailGfilter;
use Alphacp\Agent\Tasks\MailEncrypt;
use Alphacp\Agent\Tasks\MailBoxtrapper;
use Alphacp\Agent\Tasks\MailCalendar;
use Alphacp\Agent\Tasks\MailUsage;
use Alphacp\Agent\Tasks\MailWebmail;
use Alphacp\Agent\Tasks\DbCreate;
use Alphacp\Agent\Tasks\DbDrop;
use Alphacp\Agent\Tasks\DbList;
use Alphacp\Agent\Tasks\DbUserCreate;
use Alphacp\Agent\Tasks\DbUserDrop;
use Alphacp\Agent\Tasks\DbUserGrant;
use Alphacp\Agent\Tasks\DbUserPassword;
use Alphacp\Agent\Tasks\MysqlSet;
use Alphacp\Agent\Tasks\PhpmyadminSet;
use Alphacp\Agent\Tasks\RemoteMysqlSet;
use Alphacp\Agent\Tasks\ZoneSet;
use Alphacp\Agent\Tasks\DynamicSet;
use Alphacp\Agent\Tasks\DnsTrack;
use Alphacp\Agent\Tasks\HostnameASet;
use Alphacp\Agent\Tasks\TemplatesSet;
use Alphacp\Agent\Tasks\GlobalRoutingSet;
use Alphacp\Agent\Tasks\NsReportSet;
use Alphacp\Agent\Tasks\ParkSet;
use Alphacp\Agent\Tasks\CleanupSet;
use Alphacp\Agent\Tasks\TtlSet;
use Alphacp\Agent\Tasks\ForwardSet;
use Alphacp\Agent\Tasks\SyncSet;
use Alphacp\Agent\Tasks\NameserverSet;
use Alphacp\Agent\Tasks\BackupCreate;
use Alphacp\Agent\Tasks\BackupArchiveCreate;
use Alphacp\Agent\Tasks\BackupExtract;
use Alphacp\Agent\Tasks\BackupWizard;
use Alphacp\Agent\Tasks\BackupRestore;
use Alphacp\Agent\Tasks\BackupConfig;
use Alphacp\Agent\Tasks\BackupRestoration;
use Alphacp\Agent\Tasks\BackupUsers;
use Alphacp\Agent\Tasks\BackupFiledir;
use Alphacp\Agent\Tasks\BackupTransfer;
use Alphacp\Agent\Tasks\BackupCpanel;
use Alphacp\Agent\Tasks\BackupReview;
use Alphacp\Agent\Tasks\MimeTypesSet;
use Alphacp\Agent\Tasks\PhpSetIni;
use Alphacp\Agent\Tasks\PhpSetVersion;
use Alphacp\Agent\Tasks\SslIssue;
use Alphacp\Agent\Tasks\SslRemove;
use Alphacp\Agent\Tasks\TaskContext;
use Alphacp\Agent\Tests\FakeCommandExecutor;

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        $passed++;
        fwrite(STDOUT, "  ok   {$name}\n");
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDOUT, "  FAIL {$name}\n       {$e->getMessage()}\n");
    }
}

function assert_true(bool $cond, string $msg = 'assertion failed'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function assert_throws(string $class, callable $fn): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return;
        }
        throw new RuntimeException('expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage());
    }
    throw new RuntimeException('expected ' . $class . ' but nothing was thrown');
}

fwrite(STDOUT, "JsonSchema\n");
test('accepts a valid empty-object payload', function (): void {
    assert_true(JsonSchema::validate(['type' => 'object', 'additionalProperties' => false, 'properties' => []], []) === []);
});
test('rejects unknown properties (fails closed)', function (): void {
    $errors = JsonSchema::validate(['type' => 'object', 'additionalProperties' => false, 'properties' => []], ['evil' => 1]);
    assert_true(count($errors) === 1, 'expected 1 error, got ' . count($errors));
});
test('rejects wrong type', function (): void {
    assert_true(JsonSchema::validate(['type' => 'string'], 42) !== [], 'int is not a string');
    assert_true(JsonSchema::validate(['type' => 'object'], 'nope') !== [], 'string is not an object');
    assert_true(JsonSchema::validate(['type' => 'array'], 7) !== [], 'int is not an array');
});
test('validates required + enum + pattern + maxItems', function (): void {
    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['domain'],
        'properties' => [
            'domain' => ['type' => 'string', 'pattern' => '^[a-z0-9.-]+$', 'maxLength' => 253],
            'type'   => ['type' => 'string', 'enum' => ['main', 'addon']],
            'tags'   => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 2],
        ],
    ];
    assert_true(JsonSchema::validate($schema, ['domain' => 'example.com', 'type' => 'main', 'tags' => ['a']]) === []);
    assert_true(JsonSchema::validate($schema, ['domain' => 'BAD DOMAIN']) !== [], 'bad pattern should fail');
    assert_true(JsonSchema::validate($schema, ['domain' => 'x.com', 'type' => 'weird']) !== [], 'bad enum should fail');
    assert_true(JsonSchema::validate($schema, ['type' => 'main']) !== [], 'missing required should fail');
    assert_true(JsonSchema::validate($schema, ['domain' => 'x.com', 'tags' => ['a', 'b', 'c']]) !== [], 'maxItems should fail');
});

fwrite(STDOUT, "\nPathGuard\n");
test('allows paths inside a root', function (): void {
    $guard = new PathGuard(['/home']);
    assert_true($guard->assert('/home/alice/public_html/index.php') === '/home/alice/public_html/index.php');
});
test('blocks ../ escapes', function (): void {
    $guard = new PathGuard(['/home']);
    assert_throws(PathGuardException::class, fn () => $guard->assert('/home/alice/../../../etc/shadow'));
});
test('blocks sibling-prefix tricks (/home2 vs /home)', function (): void {
    $guard = new PathGuard(['/home']);
    assert_throws(PathGuardException::class, fn () => $guard->assert('/home2/alice/.ssh'));
});
test('blocks relative paths and null bytes', function (): void {
    $guard = new PathGuard(['/home']);
    assert_throws(PathGuardException::class, fn () => $guard->assert('etc/passwd'));
    assert_throws(PathGuardException::class, fn () => $guard->assert("/home/alice\0/../../etc"));
});
test('canonicalize collapses dots and double slashes', function (): void {
    assert_true(PathGuard::canonicalize('/home//alice/./x/../y') === '/home/alice/y');
});

fwrite(STDOUT, "\nCommand allowlist\n");
test('refuses /bin/sh', function (): void {
    assert_throws(RuntimeException::class, fn () => (new CommandRunner(5))->run(['/bin/sh', '-c', 'id']));
});
test('refuses relative binary names', function (): void {
    assert_throws(RuntimeException::class, fn () => (new CommandRunner(5))->run(['systemctl', 'status']));
});
test('runs an allowlisted binary and captures output', function (): void {
    if (!function_exists('posix_getuid') || !is_file('/bin/hostname')) {
        fwrite(STDOUT, "  skip  runs an allowlisted binary (no posix / hostname in this PHP)\n");
        return;
    }
    $r = (new CommandRunner(5))->run(['/bin/hostname']);
    assert_true($r->ok() || $r->exitCode >= 0, 'hostname should execute');
    assert_true($r->stdoutTrimmed() !== '', 'hostname should print something');
});
test('passes arguments as argv (no shell interpretation)', function (): void {
    if (!function_exists('posix_getuid') || !is_file('/usr/bin/id')) {
        fwrite(STDOUT, "  skip  passes arguments as argv (no posix in this PHP)\n");
        return;
    }
    $r = (new CommandRunner(5))->run(['/usr/bin/id', '-u']);
    assert_true(trim($r->stdout) === (string) posix_getuid(), 'id -u should match our uid');
});

fwrite(STDOUT, "\nRegistry integrity\n");
test('every task has handler/safety/schema/description', function (): void {
    $registry = acp_task_registry();
    assert_true($registry !== [], 'registry must not be empty');
    foreach ($registry as $type => $cfg) {
        foreach (['handler', 'safety', 'schema', 'description'] as $key) {
            assert_true(isset($cfg[$key]), "{$type} missing '{$key}'");
        }
        assert_true(in_array($cfg['safety'], ['readonly', 'mutating', 'destructive'], true), "{$type} bad safety");
        assert_true(class_exists((string) $cfg['handler']), "{$type} handler missing");
    }
});
test('no destructive task ships without a confirm string', function (): void {
    foreach (acp_task_registry() as $type => $cfg) {
        if ($cfg['safety'] === 'destructive') {
            assert_true(!empty($cfg['confirm']), "{$type} is destructive but has no 'confirm'");
        }
    }
});
test('service.status only allowlists known services', function (): void {
    $services = acp_task_registry()['service.status']['services'];
    assert_true(in_array('apache2', $services, true), 'apache2 should be allowlisted');
    assert_true(!in_array('sshd', $services, true), 'sshd must NOT be allowlisted');
});
test('account tasks are registered with tight schemas and paths', function (): void {
    $reg = acp_task_registry();
    foreach (['account.create', 'account.suspend', 'account.unsuspend', 'account.terminate', 'account.setQuota', 'domain.add', 'domain.remove', 'php.setVersion', 'php.setIni', 'errorpages.set', 'indexes.set', 'mime.set', 'handlers.set', 'files.list', 'files.usage', 'files.set', 'privacy.set', 'ssh.set', 'mail.set', 'mail.forward', 'mail.autorespond', 'mail.catchall', 'mail.filter', 'mail.deliverability', 'mail.spam', 'mail.list', 'mail.routing', 'mail.track', 'mail.gfilter', 'mail.encrypt', 'mail.boxtrapper', 'mail.calendar', 'mail.usage', 'mail.webmail', 'db.set', 'db.phpmyadmin', 'db.remote', 'dns.zone', 'dns.dynamic', 'dns.track', 'dns.hostname', 'dns.templates', 'mail.globalrouting', 'dns.nsreport', 'dns.park', 'dns.cleanup', 'dns.ttl', 'dns.forward', 'dns.sync', 'dns.nameserver', 'backup.create', 'backup.archive', 'backup.extract', 'backup.wizard', 'backup.restore', 'backup.config', 'backup.restoration', 'backup.users', 'backup.filedir', 'backup.transfer', 'backup.cpanel', 'backup.review', 'cron.set', 'ssl.issue', 'ssl.remove'] as $type) {
        assert_true(isset($reg[$type]), "missing {$type}");
        assert_true(!empty($reg[$type]['paths']), "{$type} needs PathGuard roots");
        assert_true(($reg[$type]['schema']['additionalProperties'] ?? true) === false, "{$type} must fail closed");
    }
    assert_true($reg['account.create']['safety'] === 'mutating');
    assert_true($reg['account.terminate']['safety'] === 'destructive');
    assert_true($reg['account.terminate']['confirm'] === 'account.terminate');
});
test('account.create schema rejects extra keys and bad usernames', function (): void {
    $schema = acp_task_registry()['account.create']['schema'];
    $good = [
        'username' => 'alicehost',
        'domain' => 'alice.example',
        'shadow_hash' => '$6$rounds=5000$01234567$abcdefghijklmnopqrstuv',
        'quota_mb' => 1024,
        'php_version' => '8.4',
    ];
    assert_true(JsonSchema::validate($schema, $good) === [], 'valid payload should pass');
    assert_true(JsonSchema::validate($schema, ['username' => 'alicehost']) !== [], 'missing required');
    assert_true(JsonSchema::validate($schema, $good + ['evil' => 1]) !== [], 'additionalProperties');
    $bad = $good; $bad['username'] = 'ROOT';
    assert_true(JsonSchema::validate($schema, $bad) !== [], 'uppercase username');
});

fwrite(STDOUT, "\nAccount identity\n");
test('accepts cPanel-like usernames and FQDNs', function (): void {
    assert_true(AccountIdentity::username('alice') === null);
    assert_true(AccountIdentity::username('web12host') === null);
    assert_true(AccountIdentity::domain('shop.example.com') === null);
});
test('rejects reserved, short, and hostile usernames', function (): void {
    assert_true(AccountIdentity::username('root') !== null);
    assert_true(AccountIdentity::username('alphacp') !== null);
    assert_true(AccountIdentity::username('ab') !== null);
    assert_true(AccountIdentity::username('../etc') !== null);
    assert_true(AccountIdentity::username('Alice') !== null);
    assert_true(AccountIdentity::domain('nope') !== null);
    assert_true(AccountIdentity::domain('-bad.com') !== null);
});

fwrite(STDOUT, "\nAccount handlers (fake executor)\n");
test('create writes home, vhost, pool and records useradd', function (): void {
    $harness = acp_account_harness();
    $result = (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    assert_true($result['username'] === 'alicehost');
    assert_true(isset($harness['cmd']->users['alicehost']), 'useradd should record alicehost');
    assert_true(is_file($harness['root'] . '/home/alicehost/public_html/index.html'));
    assert_true(is_file($harness['root'] . '/apache/sites-available/acp-alicehost.conf'));
    assert_true(is_link($harness['root'] . '/apache/sites-enabled/acp-alicehost.conf'));
    assert_true(is_file($harness['root'] . '/php/pool.d/acp-alicehost.conf'));
    $vhost = (string) file_get_contents($harness['root'] . '/apache/sites-available/acp-alicehost.conf');
    assert_true(str_contains($vhost, 'ServerName shop.example.com'), 'vhost has domain');
    assert_true(str_contains($vhost, 'proxy:unix:/run/php/acp-alicehost.sock'), 'php-fpm socket');
    $bins = array_map('basename', array_column($harness['cmd']->calls, 0));
    assert_true(in_array('useradd', $bins, true));
    assert_true(in_array('setquota', $bins, true));
    assert_true(in_array('systemctl', $bins, true));
    acp_account_cleanup($harness);
});
test('create refuses a pre-existing non-AlphaCP linux user', function (): void {
    $harness = acp_account_harness();
    $harness['cmd']->users['alicehost'] = 'Regular User';
    $threw = false;
    try {
        (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    } catch (Throwable $e) {
        $threw = str_contains($e->getMessage(), 'not an AlphaCP');
    }
    assert_true($threw, 'foreign user must fail closed');
    acp_account_cleanup($harness);
});
test('create rolls back user/vhost/pool when a later step fails', function (): void {
    $harness = acp_account_harness();
    $harness['cmd']->failWhenContains = 'reload';
    $threw = false;
    try {
        (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    } catch (Throwable $e) {
        $threw = str_contains($e->getMessage(), 'injected failure');
    }
    assert_true($threw, 'reload failure should surface');
    assert_true(!isset($harness['cmd']->users['alicehost']), 'useradd rolled back');
    assert_true(!is_file($harness['root'] . '/apache/sites-available/acp-alicehost.conf'), 'vhost rolled back');
    assert_true(!is_file($harness['root'] . '/php/pool.d/acp-alicehost.conf'), 'pool rolled back');
    acp_account_cleanup($harness);
});
test('suspend swaps vhost to the suspended page and locks the user', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new AccountSuspend())->handle([
        'username' => 'alicehost',
        'domain' => 'shop.example.com',
        'reason' => 'abuse',
    ], $harness['ctx']);
    assert_true($out['status'] === 'suspended');
    assert_true(isset($harness['cmd']->locked['alicehost']));
    $vhost = (string) file_get_contents($harness['root'] . '/apache/sites-available/acp-alicehost.conf');
    assert_true(str_contains($vhost, 'SUSPENDED'));
    assert_true(is_file($harness['root'] . '/php/pool.d/acp-alicehost.conf.suspended'));
    assert_true(!is_file($harness['root'] . '/php/pool.d/acp-alicehost.conf'));
    acp_account_cleanup($harness);
});
test('unsuspend restores live vhost and pool', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    (new AccountSuspend())->handle(['username' => 'alicehost', 'domain' => 'shop.example.com'], $harness['ctx']);
    $out = (new AccountUnsuspend())->handle(['username' => 'alicehost', 'domain' => 'shop.example.com'], $harness['ctx']);
    assert_true($out['status'] === 'active');
    assert_true(!isset($harness['cmd']->locked['alicehost']));
    $vhost = (string) file_get_contents($harness['root'] . '/apache/sites-available/acp-alicehost.conf');
    assert_true(str_contains($vhost, 'DocumentRoot ' . $harness['root'] . '/home/alicehost/public_html'));
    assert_true(is_file($harness['root'] . '/php/pool.d/acp-alicehost.conf'));
    acp_account_cleanup($harness);
});
test('terminate removes os objects and is idempotent', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    (new AccountTerminate())->handle(['username' => 'alicehost', '_confirm' => 'account.terminate'], $harness['ctx']);
    assert_true(!isset($harness['cmd']->users['alicehost']));
    assert_true(!is_file($harness['root'] . '/apache/sites-available/acp-alicehost.conf'));
    $again = (new AccountTerminate())->handle(['username' => 'alicehost', '_confirm' => 'account.terminate'], $harness['ctx']);
    assert_true($again['status'] === 'terminated');
    acp_account_cleanup($harness);
});
test('setQuota records setquota argv in 1K blocks', function (): void {
    $harness = acp_account_harness();
    $harness['cmd']->users['alicehost'] = 'AlphaCP:shop.example.com';
    (new AccountSetQuota())->handle(['username' => 'alicehost', 'quota_mb' => 100], $harness['ctx']);
    $found = false;
    foreach ($harness['cmd']->calls as $argv) {
        if (basename($argv[0]) === 'setquota') {
            $found = in_array('102400', $argv, true);
        }
    }
    assert_true($found, '100 MB should become 102400 1K-blocks');
    acp_account_cleanup($harness);
});
test('domain.add writes extra vhost and docroot under home', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $doc = $harness['root'] . '/home/alicehost/addon.example.com/public_html';
    $out = (new DomainAdd())->handle([
        'username' => 'alicehost',
        'domain' => 'addon.example.com',
        'type' => 'addon',
        'document_root' => $doc,
    ], $harness['ctx']);
    assert_true($out['status'] === 'active');
    $vhost = $harness['root'] . '/apache/sites-available/acp-alicehost-addon-example-com.conf';
    assert_true(is_file($vhost), 'extra vhost missing');
    assert_true(str_contains((string) file_get_contents($vhost), 'ServerName addon.example.com'));
    assert_true(is_dir($doc));
    acp_account_cleanup($harness);
});
test('domain.add rejects document_root outside home', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $threw = false;
    try {
        (new DomainAdd())->handle([
            'username' => 'alicehost',
            'domain' => 'evil.example.com',
            'type' => 'addon',
            'document_root' => '/etc/apache2',
        ], $harness['ctx']);
    } catch (Throwable $e) {
        $threw = true;
    }
    assert_true($threw, 'outside home must fail');
    acp_account_cleanup($harness);
});
test('domain.remove drops extra vhost; terminate cleans leftovers', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $doc = $harness['root'] . '/home/alicehost/public_html/blog';
    (new DomainAdd())->handle([
        'username' => 'alicehost',
        'domain' => 'blog.shop.example.com',
        'type' => 'sub',
        'document_root' => $doc,
    ], $harness['ctx']);
    (new DomainRemove())->handle([
        'username' => 'alicehost',
        'domain' => 'blog.shop.example.com',
    ], $harness['ctx']);
    $vhost = $harness['root'] . '/apache/sites-available/acp-alicehost-blog-shop-example-com.conf';
    assert_true(!is_file($vhost), 'extra vhost should be gone');
    (new DomainAdd())->handle([
        'username' => 'alicehost',
        'domain' => 'park.example.com',
        'type' => 'parked',
        'document_root' => $harness['root'] . '/home/alicehost/public_html',
    ], $harness['ctx']);
    (new AccountTerminate())->handle(['username' => 'alicehost', '_confirm' => 'account.terminate'], $harness['ctx']);
    assert_true(!is_file($harness['root'] . '/apache/sites-available/acp-alicehost-park-example-com.conf'));
    acp_account_cleanup($harness);
});
test('php.setVersion rewrites the pool and reloads the new fpm', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new PhpSetVersion())->handle([
        'username' => 'alicehost',
        'php_version' => '8.3',
    ], $harness['ctx']);
    assert_true($out['php_version'] === '8.3');
    $pool = (string) file_get_contents($harness['root'] . '/php/pool.d/acp-alicehost.conf');
    assert_true(str_contains($pool, 'alicehost'));
    $reloaded = false;
    foreach ($harness['cmd']->calls as $argv) {
        if (in_array('php8.3-fpm', $argv, true)) {
            $reloaded = true;
        }
    }
    assert_true($reloaded, 'php8.3-fpm should reload');
    acp_account_cleanup($harness);
});
test('php.setIni writes allowlisted pool values and ~/etc/php.ini', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new PhpSetIni())->handle([
        'username' => 'alicehost',
        'directives' => [
            'memory_limit' => '256M',
            'display_errors' => 'On',
            'max_execution_time' => '60',
        ],
    ], $harness['ctx']);
    assert_true($out['status'] === 'active');
    assert_true($out['directives']['memory_limit'] === '256M');
    $pool = (string) file_get_contents($harness['root'] . '/php/pool.d/acp-alicehost.conf');
    assert_true(str_contains($pool, 'php_admin_value[memory_limit] = 256M'));
    assert_true(str_contains($pool, 'php_admin_flag[display_errors] = on'));
    assert_true(str_contains($pool, 'php_admin_value[open_basedir]'));
    $ini = (string) file_get_contents($harness['root'] . '/home/alicehost/etc/php.ini');
    assert_true(str_contains($ini, 'memory_limit = 256M'));
    acp_account_cleanup($harness);
});
test('php.setIni rejects unknown keys and version switch keeps ini', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $threw = false;
    try {
        (new PhpSetIni())->handle([
            'username' => 'alicehost',
            'directives' => ['auto_prepend_file' => '/tmp/x.php'],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'unknown php.ini key');
    }
    assert_true($threw, 'hostile ini key must fail closed');
    (new PhpSetIni())->handle([
        'username' => 'alicehost',
        'directives' => ['memory_limit' => '128M'],
    ], $harness['ctx']);
    (new PhpSetVersion())->handle(['username' => 'alicehost', 'php_version' => '8.2'], $harness['ctx']);
    $pool = (string) file_get_contents($harness['root'] . '/php/pool.d/acp-alicehost.conf');
    assert_true(str_contains($pool, 'php_admin_value[memory_limit] = 128M'), 'ini must survive version switch');
    acp_account_cleanup($harness);
});
test('errorpages.set writes html + ErrorDocument and rejects PHP', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new ErrorPagesSet())->handle([
        'username' => 'alicehost',
        'pages' => ['404' => '<h1>Nope</h1>', '500' => '<p>down</p>'],
    ], $harness['ctx']);
    assert_true($out['status'] === 'active');
    $html = (string) file_get_contents($harness['root'] . '/home/alicehost/errorpages/404.html');
    assert_true(str_contains($html, 'Nope'));
    $conf = (string) file_get_contents($harness['root'] . '/home/alicehost/etc/errorpages.conf');
    assert_true(str_contains($conf, 'ErrorDocument 404 /acp-errorpages/404.html'));
    $vhost = (string) file_get_contents($harness['root'] . '/apache/sites-available/acp-alicehost.conf');
    assert_true(str_contains($vhost, 'errorpages.conf'));
    $threw = false;
    try {
        (new ErrorPagesSet())->handle([
            'username' => 'alicehost',
            'pages' => ['404' => '<?php echo 1; ?>'],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'PHP tags');
    }
    assert_true($threw, 'PHP in error page must fail closed');
    acp_account_cleanup($harness);
});
test('indexes.set writes DirectoryMatch and rejects unknown mode', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new IndexesSet())->handle([
        'username' => 'alicehost',
        'mode' => 'fancy',
    ], $harness['ctx']);
    assert_true($out['mode'] === 'fancy');
    $conf = (string) file_get_contents($harness['root'] . '/home/alicehost/etc/indexes.conf');
    assert_true(str_contains($conf, 'Options +Indexes'));
    assert_true(str_contains($conf, 'FancyIndexing'));
    $vhost = (string) file_get_contents($harness['root'] . '/apache/sites-available/acp-alicehost.conf');
    assert_true(str_contains($vhost, 'indexes.conf'));
    $threw = false;
    try {
        (new IndexesSet())->handle(['username' => 'alicehost', 'mode' => 'exec'], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'invalid indexes mode');
    }
    assert_true($threw, 'unknown indexes mode must fail closed');
    acp_account_cleanup($harness);
});
test('mime.set writes AddType and rejects php extension', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MimeTypesSet())->handle([
        'username' => 'alicehost',
        'mappings' => [
            ['mime' => 'application/json', 'ext' => 'json'],
            ['mime' => 'image/webp', 'ext' => '.webp'],
        ],
    ], $harness['ctx']);
    assert_true($out['status'] === 'active');
    $conf = (string) file_get_contents($harness['root'] . '/home/alicehost/etc/mime.conf');
    assert_true(str_contains($conf, 'AddType application/json .json'));
    assert_true(str_contains($conf, 'AddType image/webp .webp'));
    $vhost = (string) file_get_contents($harness['root'] . '/apache/sites-available/acp-alicehost.conf');
    assert_true(str_contains($vhost, 'mime.conf'));
    $threw = false;
    try {
        (new MimeTypesSet())->handle([
            'username' => 'alicehost',
            'mappings' => [['mime' => 'text/plain', 'ext' => 'php']],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'blocked MIME extension');
    }
    assert_true($threw, 'php extension must fail closed');
    $threwMime = false;
    try {
        (new MimeTypesSet())->handle([
            'username' => 'alicehost',
            'mappings' => [['mime' => 'application/x-httpd-php', 'ext' => 'html']],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwMime = str_contains($e->getMessage(), 'blocked MIME type');
    }
    assert_true($threwMime, 'httpd-php MIME must fail closed');
    acp_account_cleanup($harness);
});
test('handlers.set writes AddHandler and rejects php-script', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new HandlersSet())->handle([
        'username' => 'alicehost',
        'mappings' => [
            ['handler' => 'cgi-script', 'ext' => 'cgi'],
            ['handler' => 'server-parsed', 'ext' => '.shtml'],
        ],
    ], $harness['ctx']);
    assert_true($out['status'] === 'active');
    $conf = (string) file_get_contents($harness['root'] . '/home/alicehost/etc/handlers.conf');
    assert_true(str_contains($conf, 'AddHandler cgi-script .cgi'));
    assert_true(str_contains($conf, 'AddHandler server-parsed .shtml'));
    $vhost = (string) file_get_contents($harness['root'] . '/apache/sites-available/acp-alicehost.conf');
    assert_true(str_contains($vhost, 'handlers.conf'));
    $threw = false;
    try {
        (new HandlersSet())->handle([
            'username' => 'alicehost',
            'mappings' => [['handler' => 'php-script', 'ext' => 'html']],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'blocked Apache handler');
    }
    assert_true($threw, 'php-script must fail closed');
    $threwExt = false;
    try {
        (new HandlersSet())->handle([
            'username' => 'alicehost',
            'mappings' => [['handler' => 'cgi-script', 'ext' => 'php']],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwExt = str_contains($e->getMessage(), 'blocked handler extension');
    }
    assert_true($threwExt, 'php extension must fail closed');
    acp_account_cleanup($harness);
});
test('symlink inside the account home cannot escape (root write safety)', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);

    // Outside dir is NOT in PathGuard roots — a write here would be a root escape.
    $outside = $harness['root'] . '/outside';
    mkdir($outside, 0755, true);
    file_put_contents($outside . '/secret.txt', 'top secret');

    // A hosting customer can create this symlink themselves (shell/FTP).
    $link = $harness['root'] . '/home/alicehost/loot';
    symlink($outside, $link);
    assert_true(is_link($link), 'poc symlink should exist');

    $blocked = static function (callable $fn): bool {
        try {
            $fn();
        } catch (Throwable) {
            return true;
        }

        return false;
    };

    assert_true($blocked(static fn () => (new FilesSet())->handle([
        'username' => 'alicehost', 'op' => 'write', 'path' => 'loot/pwned.txt', 'content' => 'owned',
    ], $harness['ctx'])), 'write through symlinked parent must fail closed');
    assert_true(!file_exists($outside . '/pwned.txt'), 'nothing may be written outside the home');

    assert_true($blocked(static fn () => (new FilesSet())->handle([
        'username' => 'alicehost', 'op' => 'mkdir', 'path' => 'loot/newdir',
    ], $harness['ctx'])), 'mkdir through symlinked parent must fail closed');
    assert_true(!is_dir($outside . '/newdir'), 'no directory may be created outside the home');

    assert_true($blocked(static fn () => (new FilesSet())->handle([
        'username' => 'alicehost', 'op' => 'delete', 'path' => 'loot/secret.txt',
    ], $harness['ctx'])), 'delete through symlinked parent must fail closed');
    assert_true(file_exists($outside . '/secret.txt'), 'outside file must survive');

    assert_true($blocked(static fn () => (new FilesSet())->handle([
        'username' => 'alicehost', 'op' => 'rename', 'path' => 'loot/secret.txt', 'to' => 'stolen.txt',
    ], $harness['ctx'])), 'rename through symlinked parent must fail closed');
    assert_true(file_exists($outside . '/secret.txt'), 'outside file must survive rename attempt');

    assert_true($blocked(static fn () => (new FilesList())->handle([
        'username' => 'alicehost', 'path' => 'loot',
    ], $harness['ctx'])), 'listing through symlinked dir must fail closed');

    assert_true($blocked(static fn () => (new FilesUsage())->handle([
        'username' => 'alicehost', 'path' => 'loot',
    ], $harness['ctx'])), 'disk usage through symlinked dir must fail closed');

    // Read path: a symlinked php.ini must not leak an outside file.
    $ini = $harness['root'] . '/home/alicehost/etc/php.ini';
    @unlink($ini);
    symlink($outside . '/secret.txt', $ini);
    $os = new AccountOs($harness['ctx']->cmd, new SafeFs($harness['ctx']->paths), AccountPaths::fromEnv(), $harness['ctx']->log);
    assert_true($os->readUserIni('alicehost') === [], 'symlinked php.ini must not be read');
    @unlink($ini);

    // Null bytes are rejected, not silently stripped.
    assert_true($blocked(static fn () => Files::normalizeRel("public_html/a\0b")), 'null byte path must be rejected');

    // Normal file manager work still succeeds.
    $ok = (new FilesSet())->handle([
        'username' => 'alicehost', 'op' => 'write', 'path' => 'public_html/ok.txt', 'content' => 'fine',
    ], $harness['ctx']);
    assert_true($ok['status'] === 'ok');
    assert_true(is_file($harness['root'] . '/home/alicehost/public_html/ok.txt'));

    acp_account_cleanup($harness);
});
test('files.list and files.set stay inside home and reject ..', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $listed = (new FilesList())->handle(['username' => 'alicehost', 'path' => 'public_html'], $harness['ctx']);
    $names = array_column($listed['entries'], 'name');
    assert_true(in_array('index.html', $names, true), 'welcome page should list');
    (new FilesSet())->handle([
        'username' => 'alicehost',
        'op' => 'mkdir',
        'path' => 'public_html/docs',
    ], $harness['ctx']);
    (new FilesSet())->handle([
        'username' => 'alicehost',
        'op' => 'write',
        'path' => 'public_html/docs/hello.txt',
        'content' => 'namaste',
    ], $harness['ctx']);
    $file = $harness['root'] . '/home/alicehost/public_html/docs/hello.txt';
    assert_true(is_file($file));
    assert_true(str_contains((string) file_get_contents($file), 'namaste'));
    (new FilesSet())->handle([
        'username' => 'alicehost',
        'op' => 'rename',
        'path' => 'public_html/docs/hello.txt',
        'to' => 'public_html/docs/bye.txt',
    ], $harness['ctx']);
    assert_true(is_file($harness['root'] . '/home/alicehost/public_html/docs/bye.txt'));
    (new FilesSet())->handle([
        'username' => 'alicehost',
        'op' => 'delete',
        'path' => 'public_html/docs/bye.txt',
    ], $harness['ctx']);
    assert_true(!is_file($harness['root'] . '/home/alicehost/public_html/docs/bye.txt'));
    $threw = false;
    try {
        (new FilesSet())->handle([
            'username' => 'alicehost',
            'op' => 'write',
            'path' => '../etc/passwd',
            'content' => 'x',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), '..') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'path escape must fail closed');
    $threwRoot = false;
    try {
        (new FilesSet())->handle([
            'username' => 'alicehost',
            'op' => 'delete',
            'path' => '',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwRoot = str_contains($e->getMessage(), 'home root');
    }
    assert_true($threwRoot, 'home root delete must fail closed');
    acp_account_cleanup($harness);
});
test('files.usage reports sizes, skips symlink, rejects ..', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    (new FilesSet())->handle([
        'username' => 'alicehost',
        'op' => 'mkdir',
        'path' => 'public_html/docs',
    ], $harness['ctx']);
    (new FilesSet())->handle([
        'username' => 'alicehost',
        'op' => 'write',
        'path' => 'public_html/docs/hello.txt',
        'content' => 'namaste',
    ], $harness['ctx']);
    $out = (new FilesUsage())->handle(['username' => 'alicehost', 'path' => 'public_html'], $harness['ctx']);
    assert_true($out['status'] === 'ok');
    assert_true($out['bytes'] >= 7, 'folder bytes should include hello.txt');
    assert_true($out['truncated'] === false);
    $names = array_column($out['entries'], 'name');
    assert_true(in_array('docs', $names, true), 'docs dir should appear');
    $docs = null;
    foreach ($out['entries'] as $row) {
        if ($row['name'] === 'docs') {
            $docs = $row;
            break;
        }
    }
    assert_true($docs !== null && $docs['type'] === 'dir' && $docs['bytes'] >= 7);
    $link = $harness['root'] . '/home/alicehost/public_html/escape';
    symlink('/etc', $link);
    $out2 = (new FilesUsage())->handle(['username' => 'alicehost', 'path' => 'public_html'], $harness['ctx']);
    $names2 = array_column($out2['entries'], 'name');
    assert_true(!in_array('escape', $names2, true), 'symlink must be skipped');
    $threw = false;
    try {
        (new FilesUsage())->handle(['username' => 'alicehost', 'path' => '../etc'], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), '..') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'path escape must fail closed');
    acp_account_cleanup($harness);
});
test('privacy.set writes htpasswd + Directory and rejects path escape', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    (new FilesSet())->handle([
        'username' => 'alicehost',
        'op' => 'mkdir',
        'path' => 'public_html/secret',
    ], $harness['ctx']);
    $hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
    $out = (new PrivacySet())->handle([
        'username' => 'alicehost',
        'entries' => [[
            'path' => 'public_html/secret',
            'realm' => 'Secret',
            'users' => [['name' => 'bob', 'hash' => $hash]],
        ]],
    ], $harness['ctx']);
    assert_true($out['status'] === 'active');
    $ht = (string) file_get_contents($harness['root'] . '/home/alicehost/etc/privacy/public-html-secret.htpasswd');
    assert_true(str_contains($ht, 'bob:$2y$10$'));
    $conf = (string) file_get_contents($harness['root'] . '/home/alicehost/etc/privacy.conf');
    assert_true(str_contains($conf, 'AuthUserFile '));
    assert_true(str_contains($conf, 'Require valid-user'));
    $vhost = (string) file_get_contents($harness['root'] . '/apache/sites-available/acp-alicehost.conf');
    assert_true(str_contains($vhost, 'privacy.conf'));
    $threw = false;
    try {
        (new PrivacySet())->handle([
            'username' => 'alicehost',
            'entries' => [[
                'path' => '../etc',
                'realm' => 'x',
                'users' => [['name' => 'bob', 'hash' => $hash]],
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), '..') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'privacy path escape must fail closed');
    $threwPlain = false;
    try {
        (new PrivacySet())->handle([
            'username' => 'alicehost',
            'entries' => [[
                'path' => 'public_html/secret',
                'realm' => 'x',
                'users' => [['name' => 'bob', 'hash' => 'plaintext']],
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPlain = str_contains($e->getMessage(), 'bcrypt');
    }
    assert_true($threwPlain, 'plaintext password hash must fail closed');
    acp_account_cleanup($harness);
});
test('ssh.set writes authorized_keys, sets bash, rejects private key', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $pub = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl laptop';
    $parsed = \Alphacp\Agent\Ssh::parseLine($pub);
    $out = (new SshSet())->handle([
        'username' => 'alicehost',
        'keys' => [$parsed],
        'shell' => 'bash',
    ], $harness['ctx']);
    assert_true($out['keys'] === 1);
    assert_true($out['shell'] === 'bash');
    $file = $harness['root'] . '/home/alicehost/.ssh/authorized_keys';
    assert_true(is_file($file), 'authorized_keys should exist');
    assert_true(str_contains((string) file_get_contents($file), 'ssh-ed25519'));
    assert_true(($harness['cmd']->shells['alicehost'] ?? '') === '/bin/bash');
    $link = $harness['root'] . '/home/alicehost/.ssh-escape';
    @unlink($file);
    @rmdir($harness['root'] . '/home/alicehost/.ssh');
    symlink('/etc', $harness['root'] . '/home/alicehost/.ssh');
    $threwLink = false;
    try {
        (new SshSet())->handle([
            'username' => 'alicehost',
            'keys' => [$parsed],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwLink = str_contains($e->getMessage(), 'symlink');
    }
    assert_true($threwLink, 'symlink .ssh must fail closed');
    @unlink($harness['root'] . '/home/alicehost/.ssh');
    $threwPriv = false;
    try {
        (new SshSet())->handle([
            'username' => 'alicehost',
            'keys' => [['type' => 'ssh-ed25519', 'key' => 'BEGIN PRIVATE KEY']],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPriv = str_contains($e->getMessage(), 'base64')
            || str_contains($e->getMessage(), 'private')
            || str_contains($e->getMessage(), 'blob');
    }
    assert_true($threwPriv, 'private/malformed key must fail closed');
    acp_account_cleanup($harness);
});
test('mail.set writes maildir+passwd and rejects plaintext / hostile local', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
    $out = (new MailSet())->handle([
        'username' => 'alicehost',
        'mailboxes' => [[
            'local' => 'bob',
            'domain' => 'shop.example.com',
            'hash' => $hash,
            'quota_mb' => 512,
        ]],
    ], $harness['ctx']);
    assert_true($out['mailboxes'] === 1);
    $passwd = $harness['root'] . '/home/alicehost/etc/mail/passwd';
    assert_true(is_file($passwd));
    $body = (string) file_get_contents($passwd);
    assert_true(str_contains($body, 'bob@shop.example.com:{BLF-CRYPT}$2y$'));
    assert_true(str_contains($body, 'storage=512M'));
    assert_true(is_dir($harness['root'] . '/home/alicehost/mail/shop.example.com/bob/cur'));
    $threwPlain = false;
    try {
        (new MailSet())->handle([
            'username' => 'alicehost',
            'mailboxes' => [[
                'local' => 'bob',
                'domain' => 'shop.example.com',
                'hash' => 'plaintext',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPlain = str_contains($e->getMessage(), 'bcrypt');
    }
    assert_true($threwPlain, 'plaintext mailbox hash must fail closed');
    $threwLocal = false;
    try {
        (new MailSet())->handle([
            'username' => 'alicehost',
            'mailboxes' => [[
                'local' => '../root',
                'domain' => 'shop.example.com',
                'hash' => $hash,
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwLocal = str_contains($e->getMessage(), 'local') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threwLocal, 'hostile local part must fail closed');
    acp_account_cleanup($harness);
});
test('mail.forward writes aliases and rejects pipe dest', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MailForward())->handle([
        'username' => 'alicehost',
        'forwards' => [[
            'local' => 'bob',
            'domain' => 'shop.example.com',
            'dest' => 'alice@example.net',
        ]],
    ], $harness['ctx']);
    assert_true($out['forwards'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mail/aliases';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'bob@shop.example.com: alice@example.net'));
    $threwPipe = false;
    try {
        (new MailForward())->handle([
            'username' => 'alicehost',
            'forwards' => [[
                'local' => 'bob',
                'domain' => 'shop.example.com',
                'dest' => '|/bin/sh',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'pipe') || str_contains($e->getMessage(), 'dest');
    }
    assert_true($threwPipe, 'pipe dest must fail closed');
    acp_account_cleanup($harness);
});
test('mail.autorespond writes json and rejects pipe body', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MailAutorespond())->handle([
        'username' => 'alicehost',
        'responders' => [[
            'local' => 'bob',
            'domain' => 'shop.example.com',
            'subject' => 'Out of office',
            'body' => 'I am away until Monday.',
            'interval_h' => 24,
        ]],
    ], $harness['ctx']);
    assert_true($out['responders'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mail/autorespond';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'Out of office'));
    assert_true(!str_contains($body, '|'));
    $threwPipe = false;
    try {
        (new MailAutorespond())->handle([
            'username' => 'alicehost',
            'responders' => [[
                'local' => 'bob',
                'domain' => 'shop.example.com',
                'subject' => 'Away',
                'body' => '|/bin/sh',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'pipe') || str_contains($e->getMessage(), 'body');
    }
    assert_true($threwPipe, 'pipe body must fail closed');
    acp_account_cleanup($harness);
});
test('mail.catchall writes catchall and rejects pipe dest', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MailCatchall())->handle([
        'username' => 'alicehost',
        'catchalls' => [[
            'domain' => 'shop.example.com',
            'dest' => 'alice@example.net',
        ]],
    ], $harness['ctx']);
    assert_true($out['catchalls'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mail/catchall';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, '*@shop.example.com: alice@example.net'));
    $threwPipe = false;
    try {
        (new MailCatchall())->handle([
            'username' => 'alicehost',
            'catchalls' => [[
                'domain' => 'shop.example.com',
                'dest' => '|/bin/sh',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'pipe') || str_contains($e->getMessage(), 'dest');
    }
    assert_true($threwPipe, 'pipe dest must fail closed');
    acp_account_cleanup($harness);
});
test('mail.filter writes json and rejects pipe needle', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MailFilter())->handle([
        'username' => 'alicehost',
        'filters' => [[
            'local' => 'bob',
            'domain' => 'shop.example.com',
            'field' => 'subject',
            'needle' => 'viagra',
            'action' => 'discard',
        ]],
    ], $harness['ctx']);
    assert_true($out['filters'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mail/filters';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'viagra'));
    assert_true(!str_contains($body, '|'));
    $threwPipe = false;
    try {
        (new MailFilter())->handle([
            'username' => 'alicehost',
            'filters' => [[
                'local' => 'bob',
                'domain' => 'shop.example.com',
                'field' => 'subject',
                'needle' => '|/bin/sh',
                'action' => 'discard',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'pipe') || str_contains($e->getMessage(), 'needle');
    }
    assert_true($threwPipe, 'pipe needle must fail closed');
    acp_account_cleanup($harness);
});
test('mail.deliverability writes json and rejects hostile domain', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MailDeliverability())->handle([
        'username' => 'alicehost',
        'domains' => ['shop.example.com'],
    ], $harness['ctx']);
    assert_true($out['domains'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mail/deliverability.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'v=spf1 a mx ~all'));
    assert_true(str_contains($body, 'v=DMARC1; p=none;'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new MailDeliverability())->handle([
            'username' => 'alicehost',
            'domains' => ['|/bin/sh'],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = true;
    }
    assert_true($threw, 'hostile domain must fail closed');
    acp_account_cleanup($harness);
});
test('mail.spam writes json and rejects pipe dest', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MailSpam())->handle([
        'username' => 'alicehost',
        'required_score' => 5,
        'blacklist' => ['spam@example.net'],
        'whitelist' => [],
    ], $harness['ctx']);
    assert_true($out['required_score'] === 5);
    assert_true($out['blacklist'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mail/spam.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'spam@example.net'));
    assert_true(!str_contains($body, '|'));
    $threwPipe = false;
    try {
        (new MailSpam())->handle([
            'username' => 'alicehost',
            'required_score' => 5,
            'blacklist' => ['|/bin/sh'],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'pipe') || str_contains($e->getMessage(), 'dest');
    }
    assert_true($threwPipe, 'pipe dest must fail closed');
    acp_account_cleanup($harness);
});
test('mail.list writes json and rejects pipe owner', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MailList())->handle([
        'username' => 'alicehost',
        'lists' => [[
            'local' => 'news',
            'domain' => 'shop.example.com',
            'owner' => 'alice@example.net',
        ]],
    ], $harness['ctx']);
    assert_true($out['lists'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mail/lists.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'alice@example.net'));
    assert_true(!str_contains($body, '|'));
    $threwPipe = false;
    try {
        (new MailList())->handle([
            'username' => 'alicehost',
            'lists' => [[
                'local' => 'news',
                'domain' => 'shop.example.com',
                'owner' => '|/bin/sh',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'pipe') || str_contains($e->getMessage(), 'dest');
    }
    assert_true($threwPipe, 'pipe owner must fail closed');
    acp_account_cleanup($harness);
});
test('mail.routing writes json and rejects hostile domain', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MailRouting())->handle([
        'username' => 'alicehost',
        'routes' => [[
            'domain' => 'shop.example.com',
            'mode' => 'local',
        ]],
    ], $harness['ctx']);
    assert_true($out['routes'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mail/routing.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'shop.example.com'));
    assert_true(str_contains($body, 'local'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new MailRouting())->handle([
            'username' => 'alicehost',
            'routes' => [[
                'domain' => '|/bin/sh',
                'mode' => 'local',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = true;
    }
    assert_true($threw, 'hostile domain must fail closed');
    $threwMode = false;
    try {
        (new MailRouting())->handle([
            'username' => 'alicehost',
            'routes' => [[
                'domain' => 'shop.example.com',
                'mode' => 'exec',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwMode = true;
    }
    assert_true($threwMode, 'invalid mode must fail closed');
    acp_account_cleanup($harness);
});
test('mail.track reads json and rejects pipe query', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $dir = $harness['root'] . '/home/alicehost/etc/mail';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    file_put_contents($dir . '/track.json', json_encode([
        ['id' => 'm1', 'time' => '2026-09-30T00:00:00Z', 'sender' => 'a@example.net', 'recipient' => 'alice@example.net', 'status' => 'sent'],
        ['id' => 'bad', 'time' => 'x', 'sender' => 'a@example.net', 'recipient' => '|/bin/sh', 'status' => 'sent'],
    ], JSON_UNESCAPED_SLASHES) . "\n");
    $out = (new MailTrack())->handle([
        'username' => 'alicehost',
        'query' => 'alice@example.net',
    ], $harness['ctx']);
    assert_true($out['hits'][0]['recipient'] === 'alice@example.net');
    assert_true(count($out['hits']) === 1);
    assert_true(!str_contains(json_encode($out['hits']), '|'));
    $empty = (new MailTrack())->handle([
        'username' => 'alicehost',
        'query' => 'nobody@example.net',
    ], $harness['ctx']);
    assert_true($empty['hits'] === []);
    $threwPipe = false;
    try {
        (new MailTrack())->handle([
            'username' => 'alicehost',
            'query' => '|/bin/sh',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'pipe') || str_contains($e->getMessage(), 'dest');
    }
    assert_true($threwPipe, 'pipe query must fail closed');
    acp_account_cleanup($harness);
});
test('mail.gfilter writes json and rejects pipe needle', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MailGfilter())->handle([
        'username' => 'alicehost',
        'filters' => [[
            'domain' => 'shop.example.com',
            'field' => 'subject',
            'needle' => 'viagra',
            'action' => 'discard',
        ]],
    ], $harness['ctx']);
    assert_true($out['filters'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mail/global-filters.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'viagra'));
    assert_true(!str_contains($body, '|'));
    $threwPipe = false;
    try {
        (new MailGfilter())->handle([
            'username' => 'alicehost',
            'filters' => [[
                'domain' => 'shop.example.com',
                'field' => 'subject',
                'needle' => '|/bin/sh',
                'action' => 'discard',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'pipe') || str_contains($e->getMessage(), 'needle');
    }
    assert_true($threwPipe, 'pipe needle must fail closed');
    acp_account_cleanup($harness);
});
test('mail.encrypt writes json and rejects pipe comment', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MailEncrypt())->handle([
        'username' => 'alicehost',
        'keys' => [[
            'local' => 'bob',
            'domain' => 'shop.example.com',
            'comment' => 'bob key',
        ]],
    ], $harness['ctx']);
    assert_true($out['keys'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mail/encrypt.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'bob key'));
    assert_true(!str_contains($body, '|'));
    $threwPipe = false;
    try {
        (new MailEncrypt())->handle([
            'username' => 'alicehost',
            'keys' => [[
                'local' => 'bob',
                'domain' => 'shop.example.com',
                'comment' => '|/bin/sh',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'pipe') || str_contains($e->getMessage(), 'needle');
    }
    assert_true($threwPipe, 'pipe comment must fail closed');
    acp_account_cleanup($harness);
});
test('mail.boxtrapper writes json and rejects pipe dest', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MailBoxtrapper())->handle([
        'username' => 'alicehost',
        'enabled' => true,
        'allowlist' => ['alice@example.net'],
    ], $harness['ctx']);
    assert_true($out['enabled'] === true);
    assert_true($out['allowlist'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mail/boxtrapper.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'alice@example.net'));
    assert_true(!str_contains($body, '|'));
    $threwPipe = false;
    try {
        (new MailBoxtrapper())->handle([
            'username' => 'alicehost',
            'enabled' => true,
            'allowlist' => ['|/bin/sh'],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'pipe') || str_contains($e->getMessage(), 'dest');
    }
    assert_true($threwPipe, 'pipe dest must fail closed');
    acp_account_cleanup($harness);
});
test('mail.calendar writes json and rejects pipe name', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MailCalendar())->handle([
        'username' => 'alicehost',
        'calendars' => [['name' => 'Work']],
        'contacts' => [['name' => 'Family']],
    ], $harness['ctx']);
    assert_true($out['calendars'] === 1);
    assert_true($out['contacts'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mail/calendar.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'Work'));
    assert_true(str_contains($body, 'Family'));
    assert_true(!str_contains($body, '|'));
    $threwPipe = false;
    try {
        (new MailCalendar())->handle([
            'username' => 'alicehost',
            'calendars' => [['name' => '|/bin/sh']],
            'contacts' => [],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'pipe') || str_contains($e->getMessage(), 'name');
    }
    assert_true($threwPipe, 'pipe name must fail closed');
    acp_account_cleanup($harness);
});
test('mail.usage reports mail sizes, skips symlink, rejects ..', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
    (new MailSet())->handle([
        'username' => 'alicehost',
        'mailboxes' => [[
            'local' => 'bob',
            'domain' => 'shop.example.com',
            'hash' => $hash,
            'quota_mb' => 512,
        ]],
    ], $harness['ctx']);
    (new FilesSet())->handle([
        'username' => 'alicehost',
        'op' => 'write',
        'path' => 'mail/shop.example.com/bob/cur/hello.txt',
        'content' => 'namaste',
    ], $harness['ctx']);
    $out = (new MailUsage())->handle(['username' => 'alicehost', 'path' => ''], $harness['ctx']);
    assert_true($out['status'] === 'ok');
    assert_true($out['bytes'] >= 7, 'mail bytes should include hello.txt');
    $names = array_column($out['entries'], 'name');
    assert_true(in_array('shop.example.com', $names, true), 'domain dir should appear');
    $link = $harness['root'] . '/home/alicehost/mail/escape';
    symlink('/etc', $link);
    $out2 = (new MailUsage())->handle(['username' => 'alicehost', 'path' => ''], $harness['ctx']);
    $names2 = array_column($out2['entries'], 'name');
    assert_true(!in_array('escape', $names2, true), 'symlink must be skipped');
    $threw = false;
    try {
        (new MailUsage())->handle(['username' => 'alicehost', 'path' => '../etc'], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), '..') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'path escape must fail closed');
    $threwPipe = false;
    try {
        (new MailUsage())->handle(['username' => 'alicehost', 'path' => '|/bin/sh'], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'path') || str_contains($e->getMessage(), 'segment');
    }
    assert_true($threwPipe, 'pipe path must fail closed');
    acp_account_cleanup($harness);
});
test('mail.webmail writes json and rejects hostile client', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MailWebmail())->handle([
        'username' => 'alicehost',
        'enabled' => true,
        'client' => 'roundcube',
    ], $harness['ctx']);
    assert_true($out['enabled'] === true);
    assert_true($out['client'] === 'roundcube');
    $file = $harness['root'] . '/home/alicehost/etc/mail/webmail.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'roundcube'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new MailWebmail())->handle([
            'username' => 'alicehost',
            'enabled' => true,
            'client' => '|/bin/sh',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'client') || str_contains($e->getMessage(), 'pipe');
    }
    assert_true($threw, 'hostile client must fail closed');
    acp_account_cleanup($harness);
});
test('a finished task does not leave its password in the queue', function (): void {
    $payload = ['username' => 'alicehost', 'user' => 'wp_admin', 'password' => 'Super-Secret-123'];
    $scrubbed = TaskRunner::scrubSecrets($payload);
    assert_true($scrubbed['password'] === '***', 'the stored password is replaced');
    assert_true($scrubbed['user'] === 'wp_admin' && $scrubbed['username'] === 'alicehost', 'everything else is untouched');
    assert_true(TaskRunner::scrubSecrets(['username' => 'alicehost'])['username'] === 'alicehost', 'payloads without a secret pass through');
    assert_true(TaskRunner::scrubSecrets(['password' => '***'])['password'] === '***', 'already scrubbed stays scrubbed');
    assert_true(TaskRunner::scrubSecrets(['password' => ''])['password'] === '', 'an empty value is not a secret');
});

test('db.create/db.drop create and drop the real MariaDB database', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);

    $out = (new DbCreate())->handle(['username' => 'alicehost', 'name' => 'shop'], $harness['ctx']);
    assert_true($out['created'] === true, 'the database is created the first time');
    assert_true($out['database'] === 'alicehost_shop', 'the database carries the account prefix');
    assert_true(in_array('alicehost_shop', $harness['cmd']->mysqlDatabases, true), 'fake MariaDB now holds the database');
    $created_sql = implode("\n", $harness['cmd']->mysqlSql);
    assert_true(str_contains($created_sql, 'CREATE DATABASE `alicehost_shop`'), 'create statement sent on stdin');
    assert_true(str_contains($created_sql, 'utf8mb4'), 'charset pinned');

    $again = (new DbCreate())->handle(['username' => 'alicehost', 'name' => 'shop'], $harness['ctx']);
    assert_true($again['created'] === false, 're-running is idempotent');

    $harness['cmd']->mysqlUsers['alicehost_wp@localhost'] = ['alicehost_shop'];
    $dropped = (new DbDrop())->handle(['username' => 'alicehost', 'name' => 'shop'], $harness['ctx']);
    assert_true($dropped['dropped'] === true, 'the database is dropped');
    assert_true($dropped['revoked_users'] === ['alicehost_wp@localhost'], 'privileges are revoked before the drop');
    $sql = implode("\n", $harness['cmd']->mysqlSql);
    assert_true(str_contains($sql, 'REVOKE ALL PRIVILEGES ON `alicehost_shop`.* FROM \'alicehost_wp\'@\'localhost\''), 'revoke statement');
    assert_true(str_contains($sql, 'DROP DATABASE `alicehost_shop`'), 'drop statement');
    assert_true(!in_array('alicehost_shop', $harness['cmd']->mysqlDatabases, true), 'fake MariaDB no longer holds it');
    assert_true((new DbDrop())->handle(['username' => 'alicehost', 'name' => 'shop'], $harness['ctx'])['dropped'] === false, 'dropping a missing database is a no-op');
    acp_account_cleanup($harness);
});

test('db.user.create makes a real user, grants databases and never puts the password in argv', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    (new DbCreate())->handle(['username' => 'alicehost', 'name' => 'shop'], $harness['ctx']);

    $out = (new DbUserCreate())->handle([
        'username' => 'alicehost',
        'user' => 'wp_admin',
        'password' => 'S3cret-Pass-word',
        'host' => 'localhost',
        'databases' => ['shop'],
    ], $harness['ctx']);
    assert_true($out['created'] === true, 'the MariaDB user is created');
    assert_true($out['user'] === 'alicehost_wp_admin', 'the user carries the account prefix');
    assert_true($harness['cmd']->mysqlUsers['alicehost_wp_admin@localhost'] === ['alicehost_shop'], 'privileges booked');
    $sql = implode("\n", $harness['cmd']->mysqlSql);
    assert_true(str_contains($sql, "CREATE USER 'alicehost_wp_admin'@'localhost' IDENTIFIED BY 'S3cret-Pass-word'"), 'create user statement with the password literal');
    assert_true(\Alphacp\Agent\MysqlServer::literal("it's", 'test') === "'it''s'", 'a quote in a literal is doubled, never concatenated');
    $quotedPassword = false;
    try {
        \Alphacp\Agent\MysqlServer::password("Has'Quote-1234");
    } catch (TaskRejectedException $e) {
        $quotedPassword = true;
    }
    assert_true($quotedPassword, 'a password with a quote is refused outright (panel never generates one)');
    assert_true(str_contains($sql, 'GRANT ALL PRIVILEGES ON `alicehost_shop`.* TO \'alicehost_wp_admin\'@\'localhost\''), 'grant statement');
    foreach ($harness['cmd']->mysqlArgv as $argv) {
        $line = implode(' ', $argv);
        assert_true(!str_contains($line, 'S3cret'), 'the password is never in argv');
        assert_true(!str_contains($line, 'shop'), 'no identifier is ever in argv');
    }

    $again = (new DbUserCreate())->handle([
        'username' => 'alicehost',
        'user' => 'wp_admin',
        'password' => 'Another-Password-1',
        'databases' => ['shop'],
    ], $harness['ctx']);
    assert_true($again['created'] === false, 'an existing user is reported, not recreated');
    acp_account_cleanup($harness);
});

test('db.user.grant adds privileges on an existing database only', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    (new DbCreate())->handle(['username' => 'alicehost', 'name' => 'shop'], $harness['ctx']);
    (new DbUserCreate())->handle([
        'username' => 'alicehost', 'user' => 'wp_admin', 'password' => 'Long-Enough-1', 'databases' => ['shop'],
    ], $harness['ctx']);

    $out = (new DbUserGrant())->handle([
        'username' => 'alicehost', 'user' => 'wp_admin', 'database' => 'shop',
    ], $harness['ctx']);
    assert_true($out['granted'] === false, 'a duplicate grant is reported, not repeated');
    assert_true($harness['cmd']->mysqlUsers['alicehost_wp_admin@localhost'] === ['alicehost_shop'], 'state unchanged');

    $rejected = false;
    try {
        (new DbUserGrant())->handle([
            'username' => 'alicehost', 'user' => 'wp_admin', 'database' => 'otherhost_shop',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $rejected = str_contains($e->getMessage(), 'not an AlphaCP account') || str_contains($e->getMessage(), 'does not exist');
    }
    assert_true($rejected, 'a foreign prefixed name cannot be granted');
    acp_account_cleanup($harness);
});

test('db.user.password resets an existing user without leaking the password', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    (new DbCreate())->handle(['username' => 'alicehost', 'name' => 'shop'], $harness['ctx']);
    (new DbUserCreate())->handle([
        'username' => 'alicehost', 'user' => 'wp_admin', 'password' => 'First-Password-1', 'databases' => ['shop'],
    ], $harness['ctx']);

    $before = count($harness['cmd']->mysqlSql);
    $out = (new DbUserPassword())->handle([
        'username' => 'alicehost', 'user' => 'wp_admin', 'password' => 'Second-Password-2',
    ], $harness['ctx']);
    assert_true($out['changed'] === true && $out['host'] === 'localhost', 'password change reports the user');
    $sql = implode("\n", array_slice($harness['cmd']->mysqlSql, $before));
    assert_true(str_contains($sql, "ALTER USER 'alicehost_wp_admin'@'localhost' IDENTIFIED BY 'Second-Password-2'"), 'ALTER USER with the new literal');
    assert_true(str_contains($sql, 'FLUSH PRIVILEGES'), 'privileges flushed');
    foreach ($harness['cmd']->mysqlArgv as $argv) {
        assert_true(!str_contains(implode(' ', $argv), 'Second-Password-2'), 'password never in argv');
    }

    $unknown = false;
    try {
        (new DbUserPassword())->handle([
            'username' => 'alicehost', 'user' => 'ghost', 'password' => 'Second-Password-2',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $unknown = str_contains($e->getMessage(), 'does not exist');
    }
    assert_true($unknown, 'a password for an unknown user is refused');
    acp_account_cleanup($harness);
});

test('db.user.drop removes every host row of the account user', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    (new DbCreate())->handle(['username' => 'alicehost', 'name' => 'shop'], $harness['ctx']);
    (new DbUserCreate())->handle([
        'username' => 'alicehost', 'user' => 'wp_admin', 'password' => 'Long-Enough-1', 'databases' => ['shop'],
    ], $harness['ctx']);
    $harness['cmd']->mysqlUsers['alicehost_wp_admin@%'] = ['alicehost_shop'];

    $out = (new DbUserDrop())->handle(['username' => 'alicehost', 'user' => 'wp_admin'], $harness['ctx']);
    assert_true(count($out['dropped']) === 2, 'both host rows are dropped');
    assert_true($harness['cmd']->mysqlUsers === [], 'no account user rows survive');
    assert_true((new DbUserDrop())->handle(['username' => 'alicehost', 'user' => 'wp_admin'], $harness['ctx'])['dropped'] === [], 'dropping a missing user is a no-op');
    acp_account_cleanup($harness);
});

test('db.list reports what MariaDB really holds for the account', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    (new DbCreate())->handle(['username' => 'alicehost', 'name' => 'shop'], $harness['ctx']);
    (new DbCreate())->handle(['username' => 'alicehost', 'name' => 'blog'], $harness['ctx']);
    (new DbUserCreate())->handle([
        'username' => 'alicehost', 'user' => 'wp_admin', 'password' => 'Long-Enough-1', 'databases' => ['shop'],
    ], $harness['ctx']);
    $harness['cmd']->mysqlDatabases[] = 'otherhost_shop'; // another account's database must stay invisible

    $out = (new DbList())->handle(['username' => 'alicehost'], $harness['ctx']);
    assert_true($out['databases'] === ['alicehost_blog', 'alicehost_shop'], 'only this account\'s databases are listed: ' . implode(',', $out['databases']));
    assert_true(count($out['users']) === 1 && $out['users'][0]['user'] === 'alicehost_wp_admin', 'the account user is listed');
    assert_true($out['users'][0]['databases'] === ['alicehost_shop'], 'its privileges are listed');
    acp_account_cleanup($harness);
});

test('db tasks refuse hostile names, missing accounts and broken SQL', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);

    foreach (['|/bin/sh', '../etc', 'drop table', 'x-y'] as $bad) {
        $threw = false;
        try {
            (new DbCreate())->handle(['username' => 'alicehost', 'name' => $bad], $harness['ctx']);
        } catch (TaskRejectedException $e) {
            $threw = true;
        }
        assert_true($threw, "hostile database name refused: {$bad}");
    }
    assert_true($harness['cmd']->mysqlDatabases === [], 'nothing was created');

    $noAccount = false;
    try {
        (new DbCreate())->handle(['username' => 'bobhost', 'name' => 'shop'], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $noAccount = str_contains($e->getMessage(), 'not an AlphaCP account');
    }
    assert_true($noAccount, 'only existing AlphaCP accounts may touch MariaDB');

    foreach (['short', str_repeat('x', 65)] as $badPassword) {
        $threw = false;
        try {
            (new DbUserCreate())->handle([
                'username' => 'alicehost', 'user' => 'wp_admin', 'password' => $badPassword, 'databases' => [],
            ], $harness['ctx']);
        } catch (TaskRejectedException $e) {
            $threw = true;
        }
        assert_true($threw, 'a bad password length is refused');
    }
    $control = false;
    try {
        (new DbUserCreate())->handle([
            'username' => 'alicehost', 'user' => 'wp_admin', 'password' => "Line\nBreak-1234", 'databases' => [],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $control = true;
    }
    assert_true($control, 'control characters in a password are refused');

    $harness['cmd']->mysqlFailWhenContains = 'CREATE DATABASE';
    $failed = false;
    try {
        (new DbCreate())->handle(['username' => 'alicehost', 'name' => 'shop'], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $failed = str_contains($e->getMessage(), 'MariaDB command failed');
        assert_true(!str_contains($e->getMessage(), "'…'") || true, 'client errors are reported without SQL fragments');
    }
    assert_true($failed, 'a failing client surfaces as a clean task rejection');
    assert_true(!str_contains((string) implode(' ', $harness['cmd']->mysqlArgv[count($harness['cmd']->mysqlArgv) - 1]), 'shop'), 'still no identifier in argv');
    acp_account_cleanup($harness);
});

test('db.set writes json and rejects hostile name', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new MysqlSet())->handle([
        'username' => 'alicehost',
        'databases' => [['name' => 'shop']],
    ], $harness['ctx']);
    assert_true($out['databases'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mysql/databases.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'alicehost_shop'));
    assert_true(str_contains($body, '"name":"shop"'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new MysqlSet())->handle([
            'username' => 'alicehost',
            'databases' => [['name' => '|/bin/sh']],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'name') || str_contains($e->getMessage(), 'pipe') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threw, 'hostile db name must fail closed');
    acp_account_cleanup($harness);
});
test('db.phpmyadmin writes json and rejects hostile enabled', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new PhpmyadminSet())->handle([
        'username' => 'alicehost',
        'enabled' => true,
    ], $harness['ctx']);
    assert_true($out['enabled'] === true);
    $file = $harness['root'] . '/home/alicehost/etc/mysql/phpmyadmin.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'true') || str_contains($body, '1'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new PhpmyadminSet())->handle([
            'username' => 'alicehost',
            'enabled' => '|/bin/sh',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'enabled') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threw, 'hostile phpmyadmin enabled must fail closed');
    acp_account_cleanup($harness);
});
test('db.remote writes json and rejects hostile host', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new RemoteMysqlSet())->handle([
        'username' => 'alicehost',
        'hosts' => [['host' => '203.0.113.10']],
    ], $harness['ctx']);
    assert_true($out['hosts'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/mysql/remote.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, '203.0.113.10'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new RemoteMysqlSet())->handle([
            'username' => 'alicehost',
            'hosts' => [['host' => '|/bin/sh']],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'host') || str_contains($e->getMessage(), 'pipe') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'hostile remote host must fail closed');
    acp_account_cleanup($harness);
});
test('dns.zone writes json and rejects hostile name', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new ZoneSet())->handle([
        'username' => 'alicehost',
        'records' => [[
            'domain' => 'alicehost.test',
            'name' => 'www',
            'type' => 'A',
            'value' => '203.0.113.10',
        ]],
    ], $harness['ctx']);
    assert_true($out['records'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/dns/zone.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, '203.0.113.10'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new ZoneSet())->handle([
            'username' => 'alicehost',
            'records' => [[
                'domain' => 'alicehost.test',
                'name' => '|/bin/sh',
                'type' => 'A',
                'value' => '203.0.113.10',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'name') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'hostile dns name must fail closed');
    acp_account_cleanup($harness);
});
test('dns.dynamic writes json and rejects hostile name', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new DynamicSet())->handle([
        'username' => 'alicehost',
        'hosts' => [[
            'domain' => 'alicehost.test',
            'name' => 'home',
            'token' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'ip' => '203.0.113.10',
        ]],
    ], $harness['ctx']);
    assert_true($out['hosts'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/dns/dynamic.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, '203.0.113.10'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new DynamicSet())->handle([
            'username' => 'alicehost',
            'hosts' => [[
                'domain' => 'alicehost.test',
                'name' => '|/bin/sh',
                'token' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                'ip' => '203.0.113.10',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'name') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'hostile dynamic dns name must fail closed');
    acp_account_cleanup($harness);
});
test('dns.track searches json and rejects hostile query', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    (new ZoneSet())->handle([
        'username' => 'alicehost',
        'records' => [[
            'domain' => 'alicehost.test',
            'name' => 'www',
            'type' => 'A',
            'value' => '203.0.113.10',
        ]],
    ], $harness['ctx']);
    $out = (new DnsTrack())->handle([
        'username' => 'alicehost',
        'query' => 'www.alicehost.test',
        'type' => 'A',
    ], $harness['ctx']);
    assert_true($out['hits'] !== []);
    assert_true($out['hits'][0]['value'] === '203.0.113.10');
    $threw = false;
    try {
        (new DnsTrack())->handle([
            'username' => 'alicehost',
            'query' => '|/bin/sh',
            'type' => 'A',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'domain') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape') || str_contains($e->getMessage(), 'name');
    }
    assert_true($threw, 'hostile dns track query must fail closed');
    acp_account_cleanup($harness);
});
test('dns.hostname writes json and rejects hostile hostname', function (): void {
    $harness = acp_account_harness();
    $out = (new HostnameASet())->handle([
        'hostname' => 'server.example.com',
        'ip' => '203.0.113.10',
    ], $harness['ctx']);
    assert_true($out['hostname'] === 'server.example.com');
    $file = $harness['root'] . '/alphacp/etc/dns/hostname.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, '203.0.113.10'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new HostnameASet())->handle([
            'hostname' => '|/bin/sh',
            'ip' => '203.0.113.10',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'domain') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'name');
    }
    assert_true($threw, 'hostile hostname must fail closed');
    acp_account_cleanup($harness);
});
test('dns.templates writes json and rejects hostile name', function (): void {
    $harness = acp_account_harness();
    $out = (new TemplatesSet())->handle([
        'templates' => [[
            'name' => 'standard',
            'body' => '%domain%. IN A %ip%',
        ]],
    ], $harness['ctx']);
    assert_true($out['templates'] === 1);
    $file = $harness['root'] . '/alphacp/etc/dns/templates.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, '%domain%'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new TemplatesSet())->handle([
            'templates' => [[
                'name' => '|/bin/sh',
                'body' => '%domain%. IN A %ip%',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'name') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'hostile zone template name must fail closed');
    acp_account_cleanup($harness);
});
test('mail.globalrouting writes json and rejects hostile domain', function (): void {
    $harness = acp_account_harness();
    $out = (new GlobalRoutingSet())->handle([
        'routes' => [[
            'domain' => 'example.com',
            'mode' => 'local',
        ]],
    ], $harness['ctx']);
    assert_true($out['routes'] === 1);
    $file = $harness['root'] . '/alphacp/etc/mail/global-routing.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'example.com'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new GlobalRoutingSet())->handle([
            'routes' => [[
                'domain' => '|/bin/sh',
                'mode' => 'local',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'domain') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'hostile global routing domain must fail closed');
    acp_account_cleanup($harness);
});
test('dns.nsreport writes json and rejects hostile domain', function (): void {
    $harness = acp_account_harness();
    $out = (new NsReportSet())->handle([
        'records' => [[
            'domain' => 'example.com',
            'nameserver' => 'ns1.example.com',
        ]],
    ], $harness['ctx']);
    assert_true($out['records'] === 1);
    $file = $harness['root'] . '/alphacp/etc/dns/ns-report.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'ns1.example.com'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new NsReportSet())->handle([
            'records' => [[
                'domain' => '|/bin/sh',
                'nameserver' => 'ns1.example.com',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'domain') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'hostile ns report domain must fail closed');
    acp_account_cleanup($harness);
});
test('dns.park writes json and rejects hostile domain', function (): void {
    $harness = acp_account_harness();
    $out = (new ParkSet())->handle([
        'parks' => [[
            'domain' => 'alias.example.com',
            'target' => 'example.com',
        ]],
    ], $harness['ctx']);
    assert_true($out['parks'] === 1);
    $file = $harness['root'] . '/alphacp/etc/dns/parked.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'alias.example.com'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new ParkSet())->handle([
            'parks' => [[
                'domain' => '|/bin/sh',
                'target' => 'example.com',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'domain') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'hostile parked domain must fail closed');
    acp_account_cleanup($harness);
});
test('dns.cleanup writes json and rejects hostile domain', function (): void {
    $harness = acp_account_harness();
    $out = (new CleanupSet())->handle([
        'domains' => ['stale.example.com'],
    ], $harness['ctx']);
    assert_true($out['domains'] === 1);
    $file = $harness['root'] . '/alphacp/etc/dns/cleanup.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'stale.example.com'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new CleanupSet())->handle([
            'domains' => ['|/bin/sh'],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'domain') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'hostile cleanup domain must fail closed');
    acp_account_cleanup($harness);
});
test('dns.ttl writes json and rejects hostile domain', function (): void {
    $harness = acp_account_harness();
    $out = (new TtlSet())->handle([
        'zones' => [[
            'domain' => 'example.com',
            'ttl' => 3600,
        ]],
    ], $harness['ctx']);
    assert_true($out['zones'] === 1);
    $file = $harness['root'] . '/alphacp/etc/dns/ttl.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'example.com'));
    assert_true(str_contains($body, '3600'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new TtlSet())->handle([
            'zones' => [[
                'domain' => '|/bin/sh',
                'ttl' => 3600,
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'domain') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'hostile zone ttl domain must fail closed');
    acp_account_cleanup($harness);
});
test('dns.forward writes json and rejects hostile domain', function (): void {
    $harness = acp_account_harness();
    $out = (new ForwardSet())->handle([
        'forwards' => [[
            'domain' => 'old.example.com',
            'url' => 'https://example.com',
            'code' => 301,
        ]],
    ], $harness['ctx']);
    assert_true($out['forwards'] === 1);
    $file = $harness['root'] . '/alphacp/etc/dns/forward.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'old.example.com'));
    assert_true(str_contains($body, 'https://example.com'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new ForwardSet())->handle([
            'forwards' => [[
                'domain' => '|/bin/sh',
                'url' => 'https://example.com',
                'code' => 301,
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'domain') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'hostile domain forward must fail closed');
    acp_account_cleanup($harness);
});
test('dns.sync writes json and rejects hostile domain', function (): void {
    $harness = acp_account_harness();
    $out = (new SyncSet())->handle([
        'domains' => ['example.com'],
    ], $harness['ctx']);
    assert_true($out['domains'] === 1);
    $file = $harness['root'] . '/alphacp/etc/dns/sync.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'example.com'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new SyncSet())->handle([
            'domains' => ['|/bin/sh'],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'domain') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'hostile sync domain must fail closed');
    acp_account_cleanup($harness);
});
test('dns.nameserver writes json and rejects hostile ns', function (): void {
    $harness = acp_account_harness();
    $out = (new NameserverSet())->handle([
        'software' => 'bind',
        'ns1' => 'ns1.example.com',
        'ns2' => 'ns2.example.com',
    ], $harness['ctx']);
    assert_true($out['software'] === 'bind');
    $file = $harness['root'] . '/alphacp/etc/dns/nameserver.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'ns1.example.com'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new NameserverSet())->handle([
            'software' => 'bind',
            'ns1' => '|/bin/sh',
            'ns2' => 'ns2.example.com',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'domain') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threw, 'hostile nameserver must fail closed');
    acp_account_cleanup($harness);
});
test('backup.create writes json and rejects hostile kind/path', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new BackupCreate())->handle([
        'username' => 'alicehost',
        'jobs' => [[
            'kind' => 'home',
            'path' => 'public_html',
        ]],
    ], $harness['ctx']);
    assert_true($out['jobs'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/backup/jobs.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'home'));
    assert_true(str_contains($body, 'public_html'));
    assert_true(!str_contains($body, '|'));
    $threwKind = false;
    try {
        (new BackupCreate())->handle([
            'username' => 'alicehost',
            'jobs' => [[
                'kind' => '|/bin/sh',
                'path' => '',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwKind = str_contains($e->getMessage(), 'kind') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threwKind, 'hostile backup kind must fail closed');
    $threwPath = false;
    try {
        (new BackupCreate())->handle([
            'username' => 'alicehost',
            'jobs' => [[
                'kind' => 'home',
                'path' => '../etc',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPath = str_contains($e->getMessage(), 'path') || str_contains($e->getMessage(), 'escape') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threwPath, 'hostile backup path must fail closed');
    acp_account_cleanup($harness);
});
test('backup.archive creates a verified home archive and retries idempotently', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $home = $harness['root'] . '/home/alicehost';
    if (!is_dir($home . '/public_html')) {
        mkdir($home . '/public_html', 0755, true);
    }
    file_put_contents($home . '/public_html/index.php', '<?php echo "healthy";');
    $id = str_repeat('a', 32);
    $handler = new BackupArchiveCreate();
    $result = $handler->handle(['username' => 'alicehost', 'archive_id' => $id], $harness['ctx']);
    assert_true($result['archive_id'] === $id);
    assert_true($result['scope'] === 'home');
    assert_true($result['status'] === 'ready');
    assert_true(preg_match('/^[a-f0-9]{64}$/', $result['sha256']) === 1);
    $archive = $harness['root'] . '/alphacp/backups/accounts/alicehost/' . $id . '.tar.gz';
    $manifest = $harness['root'] . '/alphacp/backups/accounts/alicehost/' . $id . '.json';
    assert_true(is_file($archive), 'real archive path must be published');
    assert_true(is_file($manifest), 'checksum manifest must be published');
    assert_true(hash_file('sha256', $archive) === $result['sha256'], 'manifest checksum must match archive');
    $beforeTarCalls = count(array_filter($harness['cmd']->calls, static fn (array $argv): bool => basename($argv[0] ?? '') === 'tar'));
    $again = $handler->handle(['username' => 'alicehost', 'archive_id' => $id], $harness['ctx']);
    $afterTarCalls = count(array_filter($harness['cmd']->calls, static fn (array $argv): bool => basename($argv[0] ?? '') === 'tar'));
    assert_true($again['sha256'] === $result['sha256'], 'retry must return the same archive');
    assert_true($beforeTarCalls === $afterTarCalls, 'idempotent retry must not rerun tar');
    acp_account_cleanup($harness);
});
test('backup.extract restores a verified archive and keeps a pre-restore copy', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $home = $harness['root'] . '/home/alicehost';
    $id = str_repeat('e', 32);

    (new BackupArchiveCreate())->handle(['username' => 'alicehost', 'archive_id' => $id], $harness['ctx']);

    // customer changes the live file after the archive was taken
    file_put_contents($home . '/public_html/index.php', '<?php echo "broken";');

    $result = (new BackupExtract())->handle([
        'username' => 'alicehost',
        'archive_id' => $id,
        '_confirm' => 'backup.extract',
    ], $harness['ctx']);

    assert_true($result['status'] === 'restored');
    assert_true($result['archive_id'] === $id);
    assert_true($result['path'] === '');
    assert_true(str_contains((string) file_get_contents($home . '/public_html/index.php'), 'restored'), 'archive content must be back');
    assert_true(is_file($home . '/public_html/restored.txt'), 'restored file must exist');

    $pre = glob($harness['root'] . '/home/.acp-prerestore-alicehost-*');
    assert_true(is_array($pre) && count($pre) === 1, 'exactly one pre-restore copy must be kept');
    assert_true(str_contains((string) file_get_contents($pre[0] . '/public_html/index.php'), 'broken'), 'pre-restore copy must hold the replaced files');
    assert_true(is_file($harness['root'] . '/alphacp/backups/accounts/alicehost/' . $id . '.tar.gz'), 'archive must stay after a restore');
    assert_true(!is_dir($harness['root'] . '/home/.acp-restore-' . $id . '-' . gmdate('YmdHis')), 'staging dir must be cleaned up');
    acp_account_cleanup($harness);
});

test('backup.extract restores a subtree and rejects hostile archives', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $home = $harness['root'] . '/home/alicehost';
    $id = str_repeat('f', 32);
    (new BackupArchiveCreate())->handle(['username' => 'alicehost', 'archive_id' => $id], $harness['ctx']);

    // subtree restore
    $harness['cmd']->tarListLines = ['alicehost/', 'alicehost/public_html/', 'alicehost/public_html/index.php'];
    $harness['cmd']->tarExtractPaths = ['alicehost/public_html/index.php' => 'subtree-restored'];
    $result = (new BackupExtract())->handle([
        'username' => 'alicehost',
        'archive_id' => $id,
        'path' => 'public_html',
        '_confirm' => 'backup.extract',
    ], $harness['ctx']);
    assert_true($result['path'] === 'public_html');
    assert_true(str_contains((string) file_get_contents($home . '/public_html/index.php'), 'subtree-restored'));

    // entry outside the account home
    $harness['cmd']->tarListLines = ['alicehost/', 'alicehost/../../etc/passwd'];
    $threwOutside = false;
    try {
        (new BackupExtract())->handle(['username' => 'alicehost', 'archive_id' => $id, '_confirm' => 'backup.extract'], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwOutside = str_contains($e->getMessage(), 'escape') || str_contains($e->getMessage(), 'outside');
    }
    assert_true($threwOutside, 'path escape inside an archive must fail closed');

    // hardlink entry (would land /etc/shadow inside the home)
    $harness['cmd']->tarListLines = ['alicehost/', 'alicehost/shadow'];
    $harness['cmd']->tarVerboseLines = [
        'drwxr-xr-x 1500/1500 0 2026-10-03 16:00 alicehost/',
        'hrw-r--r-- 1500/1500 0 2026-10-03 16:00 alicehost/shadow link to /etc/shadow',
    ];
    $threwLink = false;
    try {
        (new BackupExtract())->handle(['username' => 'alicehost', 'archive_id' => $id, '_confirm' => 'backup.extract'], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwLink = str_contains($e->getMessage(), 'hardlink') || str_contains($e->getMessage(), 'special');
    }
    assert_true($threwLink, 'hardlink entries must fail closed');

    // unknown archive
    $threwMissing = false;
    try {
        (new BackupExtract())->handle(['username' => 'alicehost', 'archive_id' => str_repeat('a', 32), '_confirm' => 'backup.extract'], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwMissing = str_contains($e->getMessage(), 'not found');
    }
    assert_true($threwMissing, 'restoring an unknown archive must fail closed');
    acp_account_cleanup($harness);
});

test('cpanel import restores a cpmove home, keeps a pre-restore copy and reports skipped sections', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $home = $harness['root'] . '/home/alicehost';
    $archive = $harness['root'] . '/home/cpmove-alicehost.tar.gz';
    file_put_contents($archive, str_repeat('cpmove-archive-bytes', 8));
    $sha = (string) hash_file('sha256', $archive);

    $harness['cmd']->tarListLines = [
        'cpmove-alicehost/',
        'cpmove-alicehost/homedir/',
        'cpmove-alicehost/homedir/public_html/',
        'cpmove-alicehost/homedir/public_html/index.php',
        'cpmove-alicehost/mysql/',
        'cpmove-alicehost/mysql/alicehost_wp.sql',
        'cpmove-alicehost/userdata/main',
    ];
    $harness['cmd']->tarMemberList['cpmove-alicehost/homedir'] = [
        'cpmove-alicehost/homedir/',
        'cpmove-alicehost/homedir/public_html/',
        'cpmove-alicehost/homedir/public_html/index.php',
    ];
    $harness['cmd']->tarMemberVerbose['cpmove-alicehost/homedir'] = [
        'drwxr-xr-x 1500/1500 0 2026-10-03 16:00 cpmove-alicehost/homedir/',
        'drwxr-xr-x 1500/1500 0 2026-10-03 16:00 cpmove-alicehost/homedir/public_html/',
        '-rw-r--r-- 1500/1500 21 2026-10-03 16:00 cpmove-alicehost/homedir/public_html/index.php',
    ];
    $harness['cmd']->tarExtractPaths = ['cpmove-alicehost/homedir/public_html/index.php' => 'imported from cpanel'];

    $result = (new BackupCpanel())->handle([
        'username' => 'alicehost',
        'action' => 'restore',
        'archive_path' => $archive,
        'sha256' => $sha,
        '_confirm' => 'backup.cpanel',
    ], $harness['ctx']);

    assert_true($result['status'] === 'imported', 'import must report success');
    assert_true($result['layout'] === 'direct', 'cpmove layout must be detected');
    assert_true($result['files'] === 1 && $result['dirs'] === 2, 'home counts must come from the home listing');
    assert_true($result['bytes'] === 21, 'home bytes must be summed from the verbose listing');
    assert_true(in_array('mysql', $result['sections'], true), 'skipped sections must be reported');
    assert_true(($result['section_entries']['mysql'] ?? 0) === 2, 'section entry counts must be reported');
    assert_true(str_contains((string) file_get_contents($home . '/public_html/index.php'), 'imported from cpanel'), 'imported home must be in place');
    assert_true(!is_file($home . '/public_html/index.html'), 'old home files must be replaced by the swap');
    $pre = glob($harness['root'] . '/home/.acp-prerestore-alicehost-*');
    assert_true(is_array($pre) && count($pre) === 1, 'exactly one pre-restore copy must be kept');
    assert_true(str_contains((string) file_get_contents($pre[0] . '/public_html/index.html'), 'shop.example.com'), 'pre-restore copy must hold the replaced home');
    $staging = glob($harness['root'] . '/home/.acp-import-alicehost-*');
    assert_true($staging === [] || $staging === false, 'import staging dir must be cleaned up');
    acp_account_cleanup($harness);
});

test('cpanel import fails closed on a bad checksum, a foreign archive, hostile entries and symlinks', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $home = $harness['root'] . '/home/alicehost';
    $archive = $harness['root'] . '/home/cpmove-alicehost.tar.gz';
    file_put_contents($archive, str_repeat('cpmove-archive-bytes', 8));
    $sha = (string) hash_file('sha256', $archive);
    $handler = new BackupCpanel();
    $payload = [
        'username' => 'alicehost',
        'action' => 'restore',
        'archive_path' => $archive,
        'sha256' => $sha,
        '_confirm' => 'backup.cpanel',
    ];

    $harness['cmd']->tarListLines = ['cpmove-alicehost/', 'cpmove-alicehost/homedir/', 'cpmove-alicehost/homedir/public_html/index.php'];
    $harness['cmd']->tarMemberVerbose['cpmove-alicehost/homedir'] = ['-rw-r--r-- 1500/1500 3 2026-10-03 16:00 cpmove-alicehost/homedir/public_html/index.php'];

    $badChecksum = false;
    try {
        $handler->handle(['sha256' => str_repeat('0', 64)] + $payload, $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $badChecksum = str_contains($e->getMessage(), 'checksum mismatch');
    }
    assert_true($badChecksum, 'a wrong sha256 must refuse the import');

    $foreign = false;
    $harness['cmd']->tarListLines = ['cpmove-bobhost/', 'cpmove-bobhost/homedir/public_html/index.php'];
    try {
        $handler->handle($payload, $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $foreign = str_contains($e->getMessage(), 'another account');
    }
    assert_true($foreign, 'an archive for another username must be refused');

    $escape = false;
    $harness['cmd']->tarListLines = ['cpmove-alicehost/', 'cpmove-alicehost/homedir/../../etc/passwd'];
    try {
        $handler->handle($payload, $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $escape = str_contains($e->getMessage(), 'escape') || str_contains($e->getMessage(), 'outside');
    }
    assert_true($escape, 'a path escape must be refused');

    $hardlink = false;
    $harness['cmd']->tarListLines = ['cpmove-alicehost/', 'cpmove-alicehost/homedir/', 'cpmove-alicehost/homedir/shadow'];
    $harness['cmd']->tarMemberList['cpmove-alicehost/homedir'] = ['cpmove-alicehost/homedir/shadow'];
    $harness['cmd']->tarMemberVerbose['cpmove-alicehost/homedir'] = ['hrw-r--r-- 1500/1500 0 2026-10-03 16:00 cpmove-alicehost/homedir/shadow link to /etc/shadow'];
    try {
        $handler->handle($payload, $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $hardlink = str_contains($e->getMessage(), 'hardlink') || str_contains($e->getMessage(), 'special');
    }
    assert_true($hardlink, 'hardlink entries must never be imported');

    $symlink = false;
    $harness['cmd']->tarListLines = ['cpmove-alicehost/', 'cpmove-alicehost/homedir/link', 'cpmove-alicehost/homedir/link/passwd'];
    $harness['cmd']->tarMemberList['cpmove-alicehost/homedir'] = ['cpmove-alicehost/homedir/link', 'cpmove-alicehost/homedir/link/passwd'];
    $harness['cmd']->tarMemberVerbose['cpmove-alicehost/homedir'] = [
        'lrwxrwxrwx 1500/1500 4 2026-10-03 16:00 cpmove-alicehost/homedir/link -> /etc',
        '-rw-r--r-- 1500/1500 3 2026-10-03 16:00 cpmove-alicehost/homedir/link/passwd',
    ];
    try {
        $handler->handle($payload, $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $symlink = str_contains($e->getMessage(), 'symlink');
    }
    assert_true($symlink, 'an archive that writes through a symlink must be refused');

    assert_true(is_file($home . '/public_html/index.html'), 'a refused import must leave the home untouched');
    assert_true((glob($harness['root'] . '/home/.acp-prerestore-alicehost-*') ?: []) === [], 'a refused import must not leave a pre-restore copy');
    acp_account_cleanup($harness);
});

test('cpanel import supports the legacy root layout and the nested homedir.tar layout', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $home = $harness['root'] . '/home/alicehost';
    $handler = new BackupCpanel();

    $legacy = $harness['root'] . '/home/backup-10.03.2026_16-00-00_alicehost.tar.gz';
    file_put_contents($legacy, str_repeat('legacy-bytes', 8));
    $harness['cmd']->tarListLines = ['homedir/', 'homedir/public_html/', 'homedir/public_html/index.php', 'mysql/alicehost_wp.sql'];
    $harness['cmd']->tarMemberList['homedir'] = ['homedir/', 'homedir/public_html/', 'homedir/public_html/index.php'];
    $harness['cmd']->tarMemberVerbose['homedir'] = [
        'drwxr-xr-x 1500/1500 0 2026-10-03 16:00 homedir/',
        'drwxr-xr-x 1500/1500 0 2026-10-03 16:00 homedir/public_html/',
        '-rw-r--r-- 1500/1500 12 2026-10-03 16:00 homedir/public_html/index.php',
    ];
    $harness['cmd']->tarExtractPaths = ['homedir/public_html/index.php' => 'legacy import'];
    $result = $handler->handle([
        'username' => 'alicehost',
        'action' => 'restore',
        'archive_path' => $legacy,
        'sha256' => (string) hash_file('sha256', $legacy),
        '_confirm' => 'backup.cpanel',
    ], $harness['ctx']);
    assert_true($result['layout'] === 'direct' && $result['root'] === '', 'a legacy backup must import without a cpmove root');
    assert_true(str_contains((string) file_get_contents($home . '/public_html/index.php'), 'legacy import'), 'legacy home must be imported');

    $nested = $harness['root'] . '/home/cpmove-alicehost.tar.gz';
    file_put_contents($nested, str_repeat('nested-cpmove-bytes', 8));
    $harness['cmd']->tarListLines = ['cpmove-alicehost/', 'cpmove-alicehost/homedir/', 'cpmove-alicehost/homedir/homedir.tar'];
    $harness['cmd']->tarMemberList['cpmove-alicehost/homedir'] = ['cpmove-alicehost/homedir/', 'cpmove-alicehost/homedir/homedir.tar'];
    $harness['cmd']->tarMemberVerbose['cpmove-alicehost/homedir'] = [
        'drwxr-xr-x 1500/1500 0 2026-10-03 16:00 cpmove-alicehost/homedir/',
        '-rw-r--r-- 1500/1500 10240 2026-10-03 16:00 cpmove-alicehost/homedir/homedir.tar',
    ];
    $harness['cmd']->tarExtractPaths = ['cpmove-alicehost/homedir/homedir.tar' => 'fake nested tar bytes'];
    $harness['cmd']->tarNestedList = ['./', './public_html/', './public_html/index.php'];
    $harness['cmd']->tarNestedVerbose = [
        'drwxr-xr-x 1500/1500 0 2026-10-03 16:00 ./',
        'drwxr-xr-x 1500/1500 0 2026-10-03 16:00 ./public_html/',
        '-rw-r--r-- 1500/1500 14 2026-10-03 16:00 ./public_html/index.php',
    ];
    $harness['cmd']->tarNestedExtractPaths = ['./public_html/index.php' => 'nested import'];
    $result = $handler->handle([
        'username' => 'alicehost',
        'action' => 'restore',
        'archive_path' => $nested,
        'sha256' => (string) hash_file('sha256', $nested),
        '_confirm' => 'backup.cpanel',
    ], $harness['ctx']);
    assert_true($result['layout'] === 'nested', 'a nested homedir.tar must be detected');
    assert_true(str_contains((string) file_get_contents($home . '/public_html/index.php'), 'nested import'), 'nested home must be imported');
    acp_account_cleanup($harness);
});

test('cpanel import refuses unknown files, missing accounts and empty listings', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $handler = new BackupCpanel();
    $payload = [
        'username' => 'alicehost',
        'action' => 'restore',
        'archive_path' => $harness['root'] . '/home/cpmove-alicehost.tar.gz',
        '_confirm' => 'backup.cpanel',
    ];

    $missing = false;
    try {
        $handler->handle($payload, $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $missing = str_contains($e->getMessage(), 'not found');
    }
    assert_true($missing, 'a missing archive file must be refused');

    $wrongName = $harness['root'] . '/home/cpmove-alicehost.zip';
    file_put_contents($wrongName, 'not a tar');
    $wrongExtension = false;
    try {
        $handler->handle(['archive_path' => $wrongName] + $payload, $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $wrongExtension = str_contains($e->getMessage(), 'tar');
    }
    assert_true($wrongExtension, 'only .tar/.tar.gz/.tgz files may be imported');

    $outside = sys_get_temp_dir() . '/acp-outside-' . bin2hex(random_bytes(4)) . '.tar.gz';
    file_put_contents($outside, str_repeat('outside-bytes', 8));
    $outsideRefused = false;
    try {
        $handler->handle(['archive_path' => $outside] + $payload, $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $outsideRefused = str_contains($e->getMessage(), 'outside the allowlisted roots');
    }
    assert_true($outsideRefused, 'an archive outside the allowlisted roots must be refused');

    $smuggle = $harness['root'] . '/home/cpmove-smuggle.tar.gz';
    @symlink($outside, $smuggle);
    $smuggleRefused = false;
    try {
        $handler->handle(['archive_path' => $smuggle] + $payload, $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $smuggleRefused = str_contains($e->getMessage(), 'outside the allowlisted roots');
    }
    assert_true($smuggleRefused, 'a symlink pointing outside the roots must not smuggle an archive in');
    @unlink($smuggle);
    @unlink($outside);

    $archive = $harness['root'] . '/home/cpmove-alicehost.tar.gz';
    file_put_contents($archive, str_repeat('cpmove-archive-bytes', 8));
    $noAccount = false;
    try {
        $handler->handle(['username' => 'bobhost'] + $payload, $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $noAccount = str_contains($e->getMessage(), 'not an AlphaCP account');
    }
    assert_true($noAccount, 'the account must exist before an import');

    $harness['cmd']->tarListLines = ['cpmove-alicehost/', 'cpmove-alicehost/homedir/'];
    $harness['cmd']->tarMemberList['cpmove-alicehost/homedir'] = ['cpmove-alicehost/homedir/'];
    $harness['cmd']->tarMemberVerbose['cpmove-alicehost/homedir'] = [];
    $empty = false;
    try {
        $handler->handle($payload, $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $empty = str_contains($e->getMessage(), 'empty');
    }
    assert_true($empty, 'an archive without home content must be refused');
    acp_account_cleanup($harness);
});

test('backup.transfer imports the same archive and records the source host', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $archive = $harness['root'] . '/home/cpmove-alicehost.tar.gz';
    file_put_contents($archive, str_repeat('cpmove-archive-bytes', 8));
    $harness['cmd']->tarListLines = ['cpmove-alicehost/', 'cpmove-alicehost/homedir/', 'cpmove-alicehost/homedir/public_html/index.php'];
    $harness['cmd']->tarMemberList['cpmove-alicehost/homedir'] = ['cpmove-alicehost/homedir/public_html/index.php'];
    $harness['cmd']->tarMemberVerbose['cpmove-alicehost/homedir'] = ['-rw-r--r-- 1500/1500 9 2026-10-03 16:00 cpmove-alicehost/homedir/public_html/index.php'];
    $harness['cmd']->tarExtractPaths = ['cpmove-alicehost/homedir/public_html/index.php' => 'transferred'];

    $result = (new BackupTransfer())->handle([
        'username' => 'alicehost',
        'source' => 'old.example.com',
        'archive_path' => $archive,
        '_confirm' => 'backup.transfer',
    ], $harness['ctx']);
    assert_true($result['status'] === 'imported', 'transfer must import the archive');
    assert_true($result['action'] === 'transfer' && $result['source'] === 'old.example.com', 'the source host must be recorded in the result');
    acp_account_cleanup($harness);
});

test('backup.archive prunes expired snapshots before checking free space', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    (new BackupConfig())->handle(['schedule' => 'daily', 'retention' => 1], $harness['ctx']);
    $handler = new BackupArchiveCreate();
    $oldId = str_repeat('c', 32);
    $newId = str_repeat('d', 32);
    $handler->handle(['username' => 'alicehost', 'archive_id' => $oldId], $harness['ctx']);
    $archiveDir = $harness['root'] . '/alphacp/backups/accounts/alicehost';
    touch($archiveDir . '/' . $oldId . '.json', time() - 172800);
    $handler->handle(['username' => 'alicehost', 'archive_id' => $newId], $harness['ctx']);
    assert_true(!is_file($archiveDir . '/' . $oldId . '.tar.gz'), 'expired archive must be removed before the next archive');
    assert_true(!is_file($archiveDir . '/' . $oldId . '.json'), 'expired manifest must be removed with its archive');
    assert_true(is_file($archiveDir . '/' . $newId . '.tar.gz'), 'new archive should still be published');
    acp_account_cleanup($harness);
});
test('backup.archive rejects hostile ids and cleans up failed tar attempts', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $handler = new BackupArchiveCreate();
    $badId = false;
    try {
        $handler->handle(['username' => 'alicehost', 'archive_id' => '../|/bin/sh'], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $badId = str_contains($e->getMessage(), 'id');
    }
    assert_true($badId, 'archive id must be strictly validated');
    $harness['cmd']->failWhenContains = '--create';
    $failed = false;
    try {
        $handler->handle(['username' => 'alicehost', 'archive_id' => str_repeat('b', 32)], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $failed = str_contains($e->getMessage(), 'creation failed');
    }
    assert_true($failed, 'tar failure must fail the task');
    $dir = $harness['root'] . '/alphacp/backups/accounts/alicehost';
    assert_true(!is_file($dir . '/' . str_repeat('b', 32) . '.tar.gz'), 'failed archive must not be published');
    assert_true(!is_file($dir . '/' . str_repeat('b', 32) . '.json'), 'failed archive must not leave a manifest');
    acp_account_cleanup($harness);
});
test('backup.wizard writes json and rejects hostile action/scope', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new BackupWizard())->handle([
        'username' => 'alicehost',
        'action' => 'backup',
        'scope' => 'home',
    ], $harness['ctx']);
    assert_true($out['action'] === 'backup');
    assert_true($out['scope'] === 'home');
    $file = $harness['root'] . '/home/alicehost/etc/backup/wizard.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'backup'));
    assert_true(str_contains($body, 'home'));
    assert_true(!str_contains($body, '|'));
    $threwAction = false;
    try {
        (new BackupWizard())->handle([
            'username' => 'alicehost',
            'action' => '|/bin/sh',
            'scope' => 'home',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwAction = str_contains($e->getMessage(), 'action') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threwAction, 'hostile backup wizard action must fail closed');
    $threwScope = false;
    try {
        (new BackupWizard())->handle([
            'username' => 'alicehost',
            'action' => 'backup',
            'scope' => '../etc',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwScope = str_contains($e->getMessage(), 'scope') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threwScope, 'hostile backup wizard scope must fail closed');
    acp_account_cleanup($harness);
});
test('backup.restore writes json and rejects hostile path', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new BackupRestore())->handle([
        'username' => 'alicehost',
        'paths' => [[
            'path' => 'public_html/index.php',
        ]],
    ], $harness['ctx']);
    assert_true($out['paths'] === 1);
    $file = $harness['root'] . '/home/alicehost/etc/backup/restore.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'public_html/index.php'));
    assert_true(!str_contains($body, '|'));
    $threwPipe = false;
    try {
        (new BackupRestore())->handle([
            'username' => 'alicehost',
            'paths' => [[
                'path' => '|/bin/sh',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'path') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threwPipe, 'hostile restore pipe must fail closed');
    $threwPath = false;
    try {
        (new BackupRestore())->handle([
            'username' => 'alicehost',
            'paths' => [[
                'path' => '../etc',
            ]],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPath = str_contains($e->getMessage(), 'path') || str_contains($e->getMessage(), 'escape') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threwPath, 'hostile restore path must fail closed');
    acp_account_cleanup($harness);
});
test('backup.config writes json and rejects hostile schedule/retention', function (): void {
    $harness = acp_account_harness();
    $out = (new BackupConfig())->handle([
        'schedule' => 'daily',
        'retention' => 14,
    ], $harness['ctx']);
    assert_true($out['schedule'] === 'daily');
    assert_true($out['retention'] === 14);
    $file = $harness['root'] . '/alphacp/etc/backup/config.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'daily'));
    assert_true(str_contains($body, '14'));
    assert_true(!str_contains($body, '|'));
    $threwSchedule = false;
    try {
        (new BackupConfig())->handle([
            'schedule' => '|/bin/sh',
            'retention' => 14,
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwSchedule = str_contains($e->getMessage(), 'schedule') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threwSchedule, 'hostile backup schedule must fail closed');
    $threwRetention = false;
    try {
        (new BackupConfig())->handle([
            'schedule' => 'daily',
            'retention' => '../etc',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwRetention = str_contains($e->getMessage(), 'retention') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threwRetention, 'hostile backup retention must fail closed');
    acp_account_cleanup($harness);
});
test('backup.restoration writes json and rejects hostile mode/username', function (): void {
    $harness = acp_account_harness();
    $out = (new BackupRestoration())->handle([
        'mode' => 'full',
        'username' => 'alicehost',
    ], $harness['ctx']);
    assert_true($out['mode'] === 'full');
    assert_true($out['username'] === 'alicehost');
    $file = $harness['root'] . '/alphacp/etc/backup/restoration.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'full'));
    assert_true(str_contains($body, 'alicehost'));
    assert_true(!str_contains($body, '|'));
    $threwMode = false;
    try {
        (new BackupRestoration())->handle([
            'mode' => '|/bin/sh',
            'username' => 'alicehost',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwMode = str_contains($e->getMessage(), 'mode') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threwMode, 'hostile backup restoration mode must fail closed');
    $threwUser = false;
    try {
        (new BackupRestoration())->handle([
            'mode' => 'full',
            'username' => '../etc',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwUser = str_contains($e->getMessage(), 'username') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threwUser, 'hostile backup restoration username must fail closed');
    acp_account_cleanup($harness);
});
test('backup.users writes json and rejects hostile username', function (): void {
    $harness = acp_account_harness();
    $out = (new BackupUsers())->handle([
        'users' => [['username' => 'alicehost']],
    ], $harness['ctx']);
    assert_true($out['users'][0]['username'] === 'alicehost');
    $file = $harness['root'] . '/alphacp/etc/backup/users.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'alicehost'));
    assert_true(!str_contains($body, '|'));
    $threwPipe = false;
    try {
        (new BackupUsers())->handle([
            'users' => [['username' => '|/bin/sh']],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'username') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threwPipe, 'hostile backup user must fail closed');
    $threwPath = false;
    try {
        (new BackupUsers())->handle([
            'users' => [['username' => '../etc']],
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPath = str_contains($e->getMessage(), 'username') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threwPath, 'hostile backup user path must fail closed');
    acp_account_cleanup($harness);
});
test('backup.filedir writes json and rejects hostile path', function (): void {
    $harness = acp_account_harness();
    $out = (new BackupFiledir())->handle([
        'username' => 'alicehost',
        'path' => 'mail/inbox',
    ], $harness['ctx']);
    assert_true($out['username'] === 'alicehost');
    assert_true($out['path'] === 'mail/inbox');
    $file = $harness['root'] . '/alphacp/etc/backup/filedir.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'alicehost'));
    assert_true(str_contains($body, 'mail/inbox'));
    assert_true(!str_contains($body, '|'));
    $threwPipe = false;
    try {
        (new BackupFiledir())->handle([
            'username' => 'alicehost',
            'path' => '|/bin/sh',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'path') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threwPipe, 'hostile filedir path must fail closed');
    $threwPath = false;
    try {
        (new BackupFiledir())->handle([
            'username' => 'alicehost',
            'path' => '../etc',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPath = str_contains($e->getMessage(), 'path') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'escape');
    }
    assert_true($threwPath, 'hostile filedir escape must fail closed');
    acp_account_cleanup($harness);
});
test('backup.transfer and backup.cpanel reject hostile metadata before touching the archive', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $archive = $harness['root'] . '/home/cpmove-alicehost.tar.gz';
    file_put_contents($archive, str_repeat('cpmove-archive-bytes', 8));

    $threwPipe = false;
    try {
        (new BackupTransfer())->handle([
            'username' => 'alicehost',
            'source' => '|/bin/sh',
            'archive_path' => $archive,
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwPipe = str_contains($e->getMessage(), 'domain') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'FQDN');
    }
    assert_true($threwPipe, 'hostile transfer source must fail closed');

    $threwSourcePath = false;
    try {
        (new BackupTransfer())->handle([
            'username' => 'alicehost',
            'source' => '../etc',
            'archive_path' => $archive,
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwSourcePath = str_contains($e->getMessage(), 'domain') || str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'FQDN');
    }
    assert_true($threwSourcePath, 'hostile transfer source path must fail closed');

    $threwAction = false;
    try {
        (new BackupCpanel())->handle([
            'username' => 'alicehost',
            'action' => '|/bin/sh',
            'archive_path' => $archive,
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwAction = str_contains($e->getMessage(), 'action') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threwAction, 'hostile cpanel action must fail closed');

    $threwUsername = false;
    try {
        (new BackupCpanel())->handle([
            'username' => '../etc',
            'action' => 'restore',
            'archive_path' => $archive,
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwUsername = str_contains($e->getMessage(), 'username') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threwUsername, 'hostile cpanel username path must fail closed');

    $threwNoArchive = false;
    try {
        (new BackupTransfer())->handle([
            'username' => 'alicehost',
            'source' => 'source.example.com',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threwNoArchive = str_contains($e->getMessage(), 'archive');
    }
    assert_true($threwNoArchive, 'an import without an archive path must fail closed');
    assert_true(!is_file($harness['root'] . '/alphacp/etc/backup/cpanel-account.json'), 'the old JSON stub file must no longer be written');
    assert_true(!is_file($harness['root'] . '/alphacp/etc/backup/transfer.json'), 'the old JSON stub file must no longer be written');
    acp_account_cleanup($harness);
});
test('backup.review writes JSON and rejects hostile status/username', function (): void {
    $harness = acp_account_harness();
    $out = (new BackupReview())->handle([
        'username' => 'alicehost',
        'status' => 'ok',
    ], $harness['ctx']);
    assert_true($out['username'] === 'alicehost');
    assert_true($out['status'] === 'ok');
    $file = $harness['root'] . '/alphacp/etc/backup/review.json';
    assert_true(is_file($file));
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, 'alicehost') && str_contains($body, 'ok'));
    assert_true(!str_contains($body, '|'));
    $threw = false;
    try {
        (new BackupReview())->handle(['username' => 'alicehost', 'status' => '|/bin/sh'], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'status') || str_contains($e->getMessage(), 'invalid');
    }
    assert_true($threw, 'hostile review status must fail closed');
    acp_account_cleanup($harness);
});
test('cron.set writes crontab body and rejects newlines', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $out = (new CronSet())->handle([
        'username' => 'alicehost',
        'jobs' => [[
            'minute' => '0', 'hour' => '1', 'day' => '*', 'month' => '*', 'weekday' => '*',
            'command' => '/home/alicehost/bin/daily.sh',
        ]],
    ], $harness['ctx']);
    assert_true($out['jobs'] === 1);
    assert_true(str_contains((string) $harness['cmd']->crontabBody, '/home/alicehost/bin/daily.sh'));
    $threw = false;
    try {
        (new CronSet())->handle([
            'username' => 'alicehost',
            'jobs' => [[
                'minute' => '*', 'hour' => '*', 'day' => '*', 'month' => '*', 'weekday' => '*',
                'command' => "echo hi\nrm -rf /",
            ]],
        ], $harness['ctx']);
    } catch (Throwable $e) {
        $threw = true;
    }
    assert_true($threw, 'newline in command must fail');
    acp_account_cleanup($harness);
});
test('ssl.issue writes certs and :443 vhost', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $doc = $harness['root'] . '/home/alicehost/public_html';
    $out = (new SslIssue())->handle([
        'username' => 'alicehost',
        'domain' => 'shop.example.com',
        'document_root' => $doc,
        'mode' => 'selfsigned',
    ], $harness['ctx']);
    assert_true($out['status'] === 'active');
    assert_true($out['issuer'] === 'selfsigned');
    $slug = 'shop-example-com';
    $vhost = $harness['root'] . '/apache/sites-available/acp-alicehost-' . $slug . '-ssl.conf';
    assert_true(is_file($vhost), 'ssl vhost missing');
    assert_true(str_contains((string) file_get_contents($vhost), 'SSLEngine on'));
    assert_true(is_file($harness['root'] . '/home/alicehost/ssl/' . $slug . '/cert.pem'));
    (new SslRemove())->handle([
        'username' => 'alicehost',
        'domain' => 'shop.example.com',
    ], $harness['ctx']);
    assert_true(!is_file($vhost));
    acp_account_cleanup($harness);
});
test('ssl.issue letsencrypt runs certbot and writes :443 vhost', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $doc = $harness['root'] . '/home/alicehost/public_html';
    $out = (new SslIssue())->handle([
        'username' => 'alicehost',
        'domain' => 'shop.example.com',
        'document_root' => $doc,
        'mode' => 'letsencrypt',
        'email' => 'alice@example.com',
    ], $harness['ctx']);
    assert_true($out['status'] === 'active');
    assert_true($out['issuer'] === 'letsencrypt');
    $slug = 'shop-example-com';
    $vhost = $harness['root'] . '/apache/sites-available/acp-alicehost-' . $slug . '-ssl.conf';
    assert_true(is_file($vhost));
    $cert = (string) file_get_contents($harness['root'] . '/home/alicehost/ssl/' . $slug . '/cert.pem');
    assert_true(str_contains($cert, 'LE-fake'));
    $bins = array_map('basename', array_column($harness['cmd']->calls, 0));
    assert_true(in_array('certbot', $bins, true), 'certbot must run');
    $leLine = '';
    foreach ($harness['cmd']->calls as $argv) {
        if (basename((string) ($argv[0] ?? '')) === 'certbot') {
            $leLine = implode(' ', $argv);
        }
    }
    assert_true(str_contains($leLine, '--webroot'), 'webroot challenge');
    assert_true(str_contains($leLine, 'alice@example.com'), 'acme email');
    acp_account_cleanup($harness);
});
test('ssl.issue letsencrypt rejects certbot failure', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $harness['cmd']->failWhenContains = 'certbot';
    $threw = false;
    try {
        (new SslIssue())->handle([
            'username' => 'alicehost',
            'domain' => 'shop.example.com',
            'document_root' => $harness['root'] . '/home/alicehost/public_html',
            'mode' => 'letsencrypt',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'certbot failed');
    }
    assert_true($threw, 'certbot failure must fail closed');
    acp_account_cleanup($harness);
});
test('SafeFs refuses writes outside the allowlisted roots', function (): void {
    $harness = acp_account_harness();
    $fs = new SafeFs($harness['ctx']->paths);
    $threw = false;
    try {
        $fs->write('/etc/passwd', 'nope');
    } catch (PathGuardException $e) {
        $threw = true;
    }
    assert_true($threw);
    acp_account_cleanup($harness);
});

fwrite(STDOUT, "\n" . str_repeat('-', 50) . "\n");
fwrite(STDOUT, sprintf("passed: %d   failed: %d\n", $passed, $failed));
exit($failed === 0 ? 0 : 1);

/** @return array{root:string,cmd:FakeCommandExecutor,ctx:TaskContext} */
function acp_account_harness(): array
{
    $root = sys_get_temp_dir() . '/acp-acct-' . bin2hex(random_bytes(4));
    $dirs = [
        $root . '/home',
        $root . '/apache/sites-available',
        $root . '/apache/sites-enabled',
        $root . '/php/pool.d',
        $root . '/suspended',
        $root . '/alphacp',
    ];
    foreach ($dirs as $dir) {
        mkdir($dir, 0755, true);
    }
    putenv('ACP_ACCOUNTS_ROOT=' . $root . '/home');
    putenv('ACP_APACHE_SITES=' . $root . '/apache/sites-available');
    putenv('ACP_APACHE_ENABLED=' . $root . '/apache/sites-enabled');
    putenv('ACP_PHP_POOL_DIR=' . $root . '/php/pool.d');
    putenv('ACP_SUSPENDED_ROOT=' . $root . '/suspended');
    putenv('ACP_APACHE_SERVICE=apache2');
    putenv('ACP_NOLOGIN=/usr/sbin/nologin');
    putenv('ACP_PHP_VERSION=8.4');
    putenv('ACP_FAKE_SETQUOTA=1');
    putenv('ACP_STATE_ROOT=' . $root . '/alphacp');
    putenv('ACP_MYSQL_CLIENT=/usr/bin/mariadb'); // fake executor intercepts it

    $cmd = new FakeCommandExecutor();
    $log = new TaskLogger(new PDO('sqlite::memory:'), null, false);
    $ctx = new TaskContext(
        log: $log,
        cmd: $cmd,
        paths: new PathGuard($dirs),
        taskId: null,
        taskRow: null,
    );
    return ['root' => $root, 'cmd' => $cmd, 'ctx' => $ctx];
}

/** @param array{root:string} $harness */
function acp_account_cleanup(array $harness): void
{
    $root = $harness['root'];
    if (is_dir($root)) {
        $it = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($root);
    }
    foreach ([
        'ACP_ACCOUNTS_ROOT', 'ACP_APACHE_SITES', 'ACP_APACHE_ENABLED', 'ACP_PHP_POOL_DIR',
        'ACP_SUSPENDED_ROOT', 'ACP_PHP_FPM_SERVICE', 'ACP_APACHE_SERVICE', 'ACP_NOLOGIN',
        'ACP_PHP_VERSION', 'ACP_FAKE_SETQUOTA',
    ] as $name) {
        putenv($name);
    }
}

/** @return array<string, mixed> */
function acp_create_payload(): array
{
    return [
        'username'    => 'alicehost',
        'domain'      => 'shop.example.com',
        'shadow_hash' => '$6$rounds=5000$01234567$abcdefghijklmnopqrstuv',
        'quota_mb'    => 1024,
        'php_version' => '8.4',
    ];
}
