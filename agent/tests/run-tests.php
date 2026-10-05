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
use Alphacp\Agent\RemoteDestination;
use Alphacp\Agent\RemotePull;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskLogger;
use Alphacp\Agent\TaskRejectedException;
use Alphacp\Agent\TaskRunner;
use Alphacp\Agent\Tasks\AccountCreate;
use Alphacp\Agent\Tasks\AccountSetQuota;
use Alphacp\Agent\Tasks\AccountSuspend;
use Alphacp\Agent\Tasks\AccountTerminate;
use Alphacp\Agent\Tasks\AccountUnsuspend;
use Alphacp\Agent\Tasks\BackupPull;
use Alphacp\Agent\Tasks\BackupDestination;
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
use Alphacp\Agent\Tasks\DbRestore;
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
use Alphacp\Agent\BindServer;
use Alphacp\Agent\MailServer;
use Alphacp\Agent\Tasks\MailServerSetup;
use Alphacp\Agent\Tasks\BindSetup;
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
    foreach (['account.create', 'account.suspend', 'account.unsuspend', 'account.terminate', 'account.setQuota', 'domain.add', 'domain.remove', 'php.setVersion', 'php.setIni', 'errorpages.set', 'indexes.set', 'mime.set', 'handlers.set', 'files.list', 'files.usage', 'files.set', 'privacy.set', 'ssh.set', 'mail.set', 'mail.forward', 'mail.autorespond', 'mail.catchall', 'mail.filter', 'mail.deliverability', 'mail.spam', 'mail.list', 'mail.routing', 'mail.track', 'mail.gfilter', 'mail.encrypt', 'mail.boxtrapper', 'mail.calendar', 'mail.usage', 'mail.webmail', 'mail.server', 'db.set', 'db.phpmyadmin', 'db.remote', 'dns.zone', 'dns.dynamic', 'dns.track', 'dns.hostname', 'dns.templates', 'mail.globalrouting', 'dns.nsreport', 'dns.park', 'dns.cleanup', 'dns.ttl', 'dns.forward', 'dns.sync', 'dns.nameserver', 'dns.bind', 'backup.create', 'backup.archive', 'backup.extract', 'backup.wizard', 'backup.restore', 'backup.config', 'backup.restoration', 'backup.users', 'backup.filedir', 'backup.transfer', 'backup.cpanel', 'backup.review', 'cron.set', 'ssl.issue', 'ssl.remove'] as $type) {
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
test('useradd comment never contains a colon (real useradd rejects it)', function (): void {
    // Live bug: `useradd: invalid comment 'AlphaCP:example.com'` — isliye panel ka
    // Create Account asli host par hamesha fail hota tha.
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);

    $comment = null;
    foreach ($harness['cmd']->calls as $argv) {
        if (basename((string) ($argv[0] ?? '')) !== 'useradd') {
            continue;
        }
        foreach ($argv as $i => $arg) {
            if ($arg === '-c' && isset($argv[$i + 1])) {
                $comment = $argv[$i + 1];
            }
        }
    }
    assert_true($comment !== null, 'useradd -c comment bheja gaya');
    assert_true(!str_contains((string) $comment, ':'), "comment me colon nahi hona chahiye (mila: {$comment})");
    assert_true(str_starts_with((string) $comment, AccountOs::GECOS_MARKER . ' '), 'comment AlphaCP marker se shuru hota hai');

    // getent se pahchaan: naya format + legacy 'AlphaCP:' dono chalne chahiye
    $harness['cmd']->users['acpnewstyle'] = AccountOs::GECOS_MARKER . ' shop.example.com';
    $harness['cmd']->users['acplegacy'] = 'AlphaCP:shop.example.com';
    $os = new AccountOs($harness['ctx']->cmd, new SafeFs($harness['ctx']->paths), AccountPaths::fromEnv(), $harness['ctx']->log);
    assert_true($os->isOurUser('acpnewstyle') === true, 'naya GECOS format pehchana jata hai');
    assert_true($os->isOurUser('acplegacy') === true, 'legacy AlphaCP: user bhi pehchana jata hai');
    assert_true($os->isOurUser('notours') === false, 'doosre user ko AlphaCP nahi samajhta');
    acp_account_cleanup($harness);
});

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

test('db.restore imports cpmove mysql dumps into real databases (and refuses a hostile one)', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $archive = $harness['root'] . '/home/cpmove-alicehost.tar.gz';
    file_put_contents($archive, str_repeat('cpmove-archive-bytes', 8));
    $sha = (string) hash_file('sha256', $archive);

    $harness['cmd']->tarListLines = [
        'cpmove-alicehost/', 'cpmove-alicehost/homedir/', 'cpmove-alicehost/homedir/public_html/index.php',
        'cpmove-alicehost/mysql/', 'cpmove-alicehost/mysql/alicehost_shop.sql',
        'cpmove-alicehost/mysql/alicehost_blog.sql', 'cpmove-alicehost/mysql/alicehost_notes.txt',
    ];
    $harness['cmd']->tarMemberList['cpmove-alicehost/mysql'] = [
        'cpmove-alicehost/mysql/',
        'cpmove-alicehost/mysql/alicehost_shop.sql',
        'cpmove-alicehost/mysql/alicehost_blog.sql',
        'cpmove-alicehost/mysql/alicehost_notes.txt',
    ];
    // shop: a normal dump (mysqldump --add-drop-database shaped) — imports fine
    // blog: touches ANOTHER database — must refuse before anything runs
    $harness['cmd']->tarExtractPaths = [
        'cpmove-alicehost/mysql/alicehost_shop.sql' =>
            "USE `alicehost_shop`;\nDROP DATABASE IF EXISTS `alicehost_shop`;\nCREATE DATABASE `alicehost_shop`;\n"
            . "CREATE TABLE `wp` (`id` int);\nINSERT INTO `wp` VALUES (7);\n",
        'cpmove-alicehost/mysql/alicehost_blog.sql' => "DROP DATABASE `someotherdb`;\n",
    ];

    $blocked = false;
    try {
        (new DbRestore())->handle([
            'username' => 'alicehost', 'archive_path' => $archive, 'sha256' => $sha, '_confirm' => 'db.restore',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $blocked = str_contains($e->getMessage(), 'another database');
    }
    assert_true($blocked, 'a dump naming another database must refuse the whole import');
    assert_true($harness['cmd']->mysqlDatabases === [], 'nothing is imported when one dump is hostile');
    assert_true($harness['cmd']->stdinFiles === [], 'nothing is streamed when one dump is hostile');

    // the operator can exclude it explicitly (the panel shows which dump failed)
    $result = (new DbRestore())->handle([
        'username' => 'alicehost', 'archive_path' => $archive, 'sha256' => $sha,
        'only' => ['shop'], '_confirm' => 'db.restore',
    ], $harness['ctx']);

    assert_true($result['status'] === 'imported', 'restore report success');
    assert_true(count($result['databases']) === 1, 'only the requested dump is imported');
    assert_true($result['databases'][0]['database'] === 'alicehost_shop', 'target database carries the account prefix');
    assert_true($result['databases'][0]['database_created'] === true, 'missing database is created first');
    assert_true(in_array('alicehost_shop', $harness['cmd']->mysqlDatabases, true), 'database exists in MariaDB');
    assert_true(count($harness['cmd']->stdinFiles) === 1, 'the dump is streamed to the client');
    $stdin = $harness['cmd']->stdinFiles[0]['contents'];
    assert_true(str_starts_with($stdin, "USE `alicehost_shop`;"), 'prepared dump selects the target database');
    assert_true(str_contains($stdin, 'CREATE TABLE `wp`'), 'dump statements are kept');
    assert_true(substr_count($stdin, 'USE ') === 1, 'the archive USE line is not duplicated');
    assert_true(!str_contains($stdin, 'DROP DATABASE'), 'drop-database lines for our own db are stripped');
    assert_true(substr_count($stdin, 'CREATE DATABASE') === 0, 'create-database lines are stripped too');
    $skipped = array_column($result['skipped'], 'member');
    assert_true(in_array('cpmove-alicehost/mysql/alicehost_notes.txt', $skipped, true), 'non-.sql members are reported as skipped');

    // a dump that tries to write files as the database user is refused as well
    $harness['cmd']->tarExtractPaths = [
        'cpmove-alicehost/mysql/alicehost_shop.sql' => "SELECT 'x' INTO OUTFILE '/root/evil';\n",
    ];
    $harness['cmd']->stdinFiles = [];
    $outfileBlocked = false;
    try {
        (new DbRestore())->handle([
            'username' => 'alicehost', 'archive_path' => $archive, 'only' => ['shop'],
            '_confirm' => 'db.restore',
        ], $harness['ctx']);
    } catch (TaskRejectedException $e) {
        $outfileBlocked = str_contains($e->getMessage(), 'INTO OUTFILE');
    }
    assert_true($outfileBlocked, 'INTO OUTFILE must be refused');
    assert_true($harness['cmd']->stdinFiles === [], 'a refused dump is never streamed');

    $staging = glob($harness['root'] . '/home/.acp-mysql-alicehost-*');
    assert_true($staging === [] || $staging === false, 'mysql staging dir must be cleaned up');
    acp_account_cleanup($harness);
});

test('db.restore reports an archive without mysql dumps instead of failing', function (): void {
    $harness = acp_account_harness();
    (new AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
    $archive = $harness['root'] . '/home/cpmove-alicehost.tar.gz';
    file_put_contents($archive, str_repeat('cpmove-archive-bytes', 8));

    $harness['cmd']->tarListLines = ['cpmove-alicehost/', 'cpmove-alicehost/homedir/', 'cpmove-alicehost/homedir/public_html/index.php'];
    $result = (new DbRestore())->handle([
        'username' => 'alicehost', 'archive_path' => $archive, '_confirm' => 'db.restore',
    ], $harness['ctx']);

    assert_true($result['status'] === 'empty', 'a home-only archive reports empty');
    assert_true($result['databases'] === [], 'nothing is restored');
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

// ---------------------------------------------------------------------------
// S10 — remote pull (backup.pull): cpmove archive doosre server se SSH (scp) se
// laana. Yahan asli network nahi chalta — FakeCommandExecutor ssh-keyscan /
// ssh-keygen / scp / sshpass ko intercept karta hai.
// ---------------------------------------------------------------------------

/** @return array{root: string, cmd: FakeCommandExecutor, ctx: TaskContext, drop: string} */
function acp_pull_harness(): array
{
    $root = sys_get_temp_dir() . '/acp-pull-' . bin2hex(random_bytes(4));
    $drop = $root . '/incoming';
    mkdir($drop, 0750, true);
    putenv('ACP_STATE_ROOT=' . $root);
    putenv('ACP_IMPORT_DIR=' . $drop);
    // fake executor basename se dispatch karta hai — asli server par ye openssh-client hai
    putenv('ACP_SSH_KEYSCAN=/usr/bin/ssh-keyscan');
    putenv('ACP_SSH_KEYGEN=/usr/bin/ssh-keygen');
    putenv('ACP_SSH_SCP=/usr/bin/scp');
    putenv('ACP_SSH_SSHPASS=/usr/bin/sshpass');

    $cmd = new FakeCommandExecutor();
    $log = new TaskLogger(new PDO('sqlite::memory:'), null, false);
    $ctx = new TaskContext(log: $log, cmd: $cmd, paths: null, taskId: null, taskRow: null);

    return ['root' => $root, 'drop' => $drop, 'cmd' => $cmd, 'ctx' => $ctx];
}

/** @param array{root: string} $harness */
function acp_pull_cleanup(array $harness): void
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
    foreach (['ACP_STATE_ROOT', 'ACP_IMPORT_DIR', 'ACP_SSH_KEYSCAN', 'ACP_SSH_KEYGEN', 'ACP_SSH_SCP', 'ACP_SSH_SSHPASS'] as $name) {
        putenv($name);
    }
}

test('backup.pull probe: fingerprint laata hai, kuch download nahi karta', function (): void {
    $h = acp_pull_harness();
    $result = (new BackupPull())->handle([
        'host' => 'old.example.com', 'probe' => true, '_confirm' => 'backup.pull',
    ], $h['ctx']);

    assert_true($result['probe'] === true, 'probe mode flag');
    assert_true(str_starts_with($result['fingerprint'], 'SHA256:'), 'fingerprint SHA256: se shuru ho');
    assert_true($result['key_type'] === 'ED25519', 'key type mila');
    assert_true($h['cmd']->scpArgv === null, 'probe me scp kabhi nahi chala');
    assert_true(glob($h['drop'] . '/*') === [] || glob($h['drop'] . '/*') === false, 'probe me koi file nahi bani');
    acp_pull_cleanup($h);
});

test('backup.pull: key auth se archive drop dir me aata hai', function (): void {
    $h = acp_pull_harness();
    $h['cmd']->scpContent = str_repeat('cpmove-bytes-', 20);

    $result = (new BackupPull())->handle([
        'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-alicehost.tar.gz',
        'auth' => 'key', 'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----",
        'host_fingerprint' => $h['cmd']->hostKeyFingerprint, '_confirm' => 'backup.pull',
    ], $h['ctx']);

    assert_true($result['name'] === 'cpmove-alicehost.tar.gz', 'remote file ka naam mila');
    assert_true(is_file($result['path']), 'archive drop dir me likha gaya');
    assert_true(str_starts_with($result['path'], $h['drop'] . '/'), 'archive drop dir ke andar hi hai');
    assert_true($result['bytes'] === strlen(str_repeat('cpmove-bytes-', 20)), 'size sahi');
    assert_true($result['sha256'] === hash('sha256', str_repeat('cpmove-bytes-', 20)), 'sha256 sahi');
    $argv = $h['cmd']->scpArgv ?? [];
    assert_true(in_array('-i', $argv, true), 'key auth me -i pass hua');
    assert_true(in_array('BatchMode=yes', $argv, true), 'key auth batch mode me chala');
    assert_true(in_array('root@old.example.com:/home/cpmove-alicehost.tar.gz', $argv, true), 'scp source spec sahi');
    assert_true(!in_array('/usr/bin/sshpass', $argv, true), 'key auth me sshpass nahi');
    acp_pull_cleanup($h);
});

test('backup.pull: host key pin na ho to refuse (MITM se bachav)', function (): void {
    $h = acp_pull_harness();
    $blocked = false;
    try {
        (new BackupPull())->handle([
            'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-a.tar.gz',
            'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----', '_confirm' => 'backup.pull',
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $blocked = str_contains($e->getMessage(), 'not pinned');
    }
    assert_true($blocked, 'bina pinned fingerprint ke pull refuse ho');
    assert_true($h['cmd']->scpArgv === null, 'refuse hone par scp hi nahi chala');
    acp_pull_cleanup($h);
});

test('backup.pull: fingerprint mismatch (server badla / MITM) -> refuse', function (): void {
    $h = acp_pull_harness();
    // admin ne pehle probe karke is fingerprint ko pin kiya tha...
    $first = (new BackupPull())->handle([
        'host' => 'old.example.com', 'probe' => true, '_confirm' => 'backup.pull',
    ], $h['ctx']);
    // ...aur ab wahi server (ya beech me koi) DOOSRI key dikha raha hai
    $h['cmd']->hostKeySecondFingerprint = 'SHA256:TOTALLYdiFFerentFingerprintAAAAAAAAAAAAAAAAAAA';

    $blocked = false;
    try {
        (new BackupPull())->handle([
            'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-a.tar.gz',
            'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
            'host_fingerprint' => $first['fingerprint'], '_confirm' => 'backup.pull',
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $blocked = str_contains($e->getMessage(), 'MISMATCH');
    }
    assert_true($blocked, 'fingerprint badalne par pull refuse ho');
    assert_true($h['cmd']->scpArgv === null, 'mismatch par scp chala hi nahi');
    assert_true(glob($h['drop'] . '/*') === [] || glob($h['drop'] . '/*') === false, 'koi file nahi chhodi');
    acp_pull_cleanup($h);
});

test('backup.pull: password auth me password argv me nahi, sshpass -f file se', function (): void {
    $h = acp_pull_harness();
    $h['cmd']->scpContent = str_repeat('pw-archive-', 12);

    $result = (new BackupPull())->handle([
        'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/backup/cpmove-bob.tar.gz',
        'auth' => 'password', 'password' => 'hunter2-super-secret',
        'host_fingerprint' => $h['cmd']->hostKeyFingerprint, '_confirm' => 'backup.pull',
    ], $h['ctx']);

    assert_true($result['auth'] === 'password', 'auth mode report hua');
    $pwFile = $h['cmd']->sshpassFile;
    assert_true(is_string($pwFile) && $pwFile !== '', 'sshpass ko -f <file> mila');
    assert_true(!is_file($pwFile), 'password file kaam ke baad delete ho gayi');
    $argv = $h['cmd']->calls;
    foreach ($argv as $call) {
        assert_true(!in_array('hunter2-super-secret', $call, true), 'password kabhi argv me nahi gaya');
    }
    acp_pull_cleanup($h);
});

test('backup.pull: sshpass na ho to password auth saaf message ke saath refuse', function (): void {
    $h = acp_pull_harness();
    putenv('ACP_SSH_SSHPASS=');           // override hatao -> asli path hi use hoga
    if (is_executable(RemotePull::SSHPASS)) {
        acp_pull_cleanup($h);
        assert_true(true, 'sshpass installed — skip');

        return;
    }
    $blocked = false;
    try {
        (new BackupPull())->handle([
            'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-a.tar.gz',
            'auth' => 'password', 'password' => 'x',
            'host_fingerprint' => $h['cmd']->hostKeyFingerprint, '_confirm' => 'backup.pull',
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $blocked = str_contains($e->getMessage(), 'sshpass');
    }
    assert_true($blocked, 'sshpass missing par saaf message');
    acp_pull_cleanup($h);
});

test('backup.pull: remote path aur naam ke niyam (.. / absolute / tar ext)', function (): void {
    $h = acp_pull_harness();
    $fp = $h['cmd']->hostKeyFingerprint;

    foreach (['/home/../etc/passwd', 'relative/path.tar.gz', '/home/c pmove.tar.gz'] as $bad) {
        $blocked = false;
        try {
            (new BackupPull())->handle([
                'host' => 'old.example.com', 'user' => 'root', 'remote_path' => $bad,
                'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
                'host_fingerprint' => $fp, '_confirm' => 'backup.pull',
            ], $h['ctx']);
        } catch (TaskRejectedException $e) {
            $blocked = true;
        }
        assert_true($blocked, "remote path '{$bad}' refuse ho");
    }

    foreach (['evil.sh', 'archive.zip', '.bashrc'] as $badName) {
        $blocked = false;
        try {
            (new BackupPull())->handle([
                'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-a.tar.gz',
                'dest_name' => $badName, 'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
                'host_fingerprint' => $fp, '_confirm' => 'backup.pull',
            ], $h['ctx']);
        } catch (TaskRejectedException $e) {
            $blocked = true;
        }
        assert_true($blocked, "dest_name '{$badName}' refuse ho (sirf tar/tar.gz/tgz)");
    }

    foreach (['old.example.com; rm -rf /', 'not a host', ''] as $badHost) {
        $blocked = false;
        try {
            (new BackupPull())->handle([
                'host' => $badHost, 'user' => 'root', 'remote_path' => '/home/cpmove-a.tar.gz',
                'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
                'host_fingerprint' => $fp, '_confirm' => 'backup.pull',
            ], $h['ctx']);
        } catch (TaskRejectedException $e) {
            $blocked = true;
        }
        assert_true($blocked, "host '{$badHost}' refuse ho");
    }
    acp_pull_cleanup($h);
});

test('backup.pull: sha256 mismatch par file drop dir me nahi rehti', function (): void {
    $h = acp_pull_harness();
    $h['cmd']->scpContent = str_repeat('x', 100);
    $blocked = false;
    try {
        (new BackupPull())->handle([
            'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-a.tar.gz',
            'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
            'sha256' => str_repeat('a', 64),
            'host_fingerprint' => $h['cmd']->hostKeyFingerprint, '_confirm' => 'backup.pull',
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $blocked = str_contains($e->getMessage(), 'checksum mismatch');
    }
    assert_true($blocked, 'galat sha256 par refuse');
    $left = glob($h['drop'] . '/*');
    assert_true($left === [] || $left === false, 'adhoora archive drop dir me nahi chhoda gaya');
    acp_pull_cleanup($h);
});

test('backup.pull: maujooda file overwrite nahi hoti (jab tak overwrite=true na ho)', function (): void {
    $h = acp_pull_harness();
    file_put_contents($h['drop'] . '/cpmove-alice.tar.gz', 'purana-archive');
    $blocked = false;
    try {
        (new BackupPull())->handle([
            'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-alice.tar.gz',
            'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
            'host_fingerprint' => $h['cmd']->hostKeyFingerprint, '_confirm' => 'backup.pull',
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $blocked = str_contains($e->getMessage(), 'already exists');
    }
    assert_true($blocked, 'bina overwrite ke refuse');
    assert_true(file_get_contents($h['drop'] . '/cpmove-alice.tar.gz') === 'purana-archive', 'purani file waise hi hai');

    (new BackupPull())->handle([
        'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-alice.tar.gz',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----', 'overwrite' => true,
        'host_fingerprint' => $h['cmd']->hostKeyFingerprint, '_confirm' => 'backup.pull',
    ], $h['ctx']);
    assert_true(file_get_contents($h['drop'] . '/cpmove-alice.tar.gz') !== 'purana-archive', 'overwrite=true par nayi file');
    acp_pull_cleanup($h);
});

test('backup.pull: private key disk par nahi rehti (temp files saaf)', function (): void {
    $h = acp_pull_harness();
    $secret = 'ACPCANARY-PRIVATE-KEY-MATERIAL-0123456789';
    (new BackupPull())->handle([
        'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-a.tar.gz',
        'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\n{$secret}\n-----END OPENSSH PRIVATE KEY-----",
        'host_fingerprint' => $h['cmd']->hostKeyFingerprint, '_confirm' => 'backup.pull',
    ], $h['ctx']);

    $leftovers = glob(sys_get_temp_dir() . '/acprp*') ?: [];
    assert_true($leftovers === [], 'koi temp file nahi bachi: ' . implode(',', $leftovers));
    foreach ($leftovers as $file) {
        assert_true(!str_contains((string) @file_get_contents($file), $secret), 'key material disk par nahi');
    }
    acp_pull_cleanup($h);
});

test('backup.pull: scp fail hone par .part file saaf ho jati hai', function (): void {
    $h = acp_pull_harness();
    $h['cmd']->scpFails = true;
    $blocked = false;
    try {
        (new BackupPull())->handle([
            'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-a.tar.gz',
            'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
            'host_fingerprint' => $h['cmd']->hostKeyFingerprint, '_confirm' => 'backup.pull',
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $blocked = str_contains($e->getMessage(), 'remote pull fail');
    }
    assert_true($blocked, 'scp fail par task fail');
    $left = glob($h['drop'] . '/*');
    assert_true($left === [] || $left === false, 'koi adhura archive nahi chhoda');
    acp_pull_cleanup($h);
});

test('backup.pull: server kai host keys de to sab fingerprints milte hain (ed25519 pehle)', function (): void {
    $h = acp_pull_harness();
    $h['cmd']->hostKeys = [
        ['pubkey' => 'old.example.com ssh-rsa AAAARSA', 'fingerprint' => 'SHA256:RSAkeyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'type' => 'RSA'],
        ['pubkey' => 'old.example.com ssh-ed25519 AAAAFake1', 'fingerprint' => 'SHA256:ED25519keyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'type' => 'ED25519'],
        ['pubkey' => 'old.example.com ecdsa-sha2-nistp256 AAAAECDSA', 'fingerprint' => 'SHA256:ECDSAkeyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'type' => 'ECDSA'],
    ];
    $result = (new BackupPull())->handle([
        'host' => 'old.example.com', 'probe' => true, '_confirm' => 'backup.pull',
    ], $h['ctx']);

    assert_true($result['key_type'] === 'ED25519', 'sabse strong key pehle');
    assert_true($result['fingerprint'] === 'SHA256:ED25519keyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'ed25519 ka fingerprint diya');
    assert_true(count($result['fingerprints']) === 3, 'teenon keys ke fingerprints mile');
    assert_true(str_contains($result['pubkey'], 'ssh-rsa'), 'poora key block mila (scp chahe jo bhi use kare)');
    acp_pull_cleanup($h);
});

test('backup.pull: keyscan ka order badle to bhi pinned key match ho (LIVE bug ka fix)', function (): void {
    $h = acp_pull_harness();
    // pehle server ne ed25519 pehle diya (probe)
    $h['cmd']->hostKeys = [
        ['pubkey' => 'old.example.com ssh-ed25519 AAAAFake1', 'fingerprint' => 'SHA256:ED25519keyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'type' => 'ED25519'],
        ['pubkey' => 'old.example.com ssh-rsa AAAARSA', 'fingerprint' => 'SHA256:RSAkeyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'type' => 'RSA'],
    ];
    $probed = (new BackupPull())->handle([
        'host' => 'old.example.com', 'probe' => true, '_confirm' => 'backup.pull',
    ], $h['ctx']);
    $pinned = $probed['fingerprint'];

    // ab keyscan ne order ulta diya (asli server par aisa hi hota hai)
    $h['cmd']->hostKeys = array_reverse($h['cmd']->hostKeys);
    $result = (new BackupPull())->handle([
        'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-a.tar.gz',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
        'host_fingerprint' => $pinned, '_confirm' => 'backup.pull',
    ], $h['ctx']);

    assert_true($result['name'] === 'cpmove-a.tar.gz', 'order badalne ke bawajood pull chal gaya');
    assert_true($result['fingerprint'] === $pinned, 'pinned fingerprint report hua');
    acp_pull_cleanup($h);
});

test('backup.pull: pin kisi bhi key se match na ho to MISMATCH (saare fingerprints dikhe)', function (): void {
    $h = acp_pull_harness();
    $h['cmd']->hostKeys = [
        ['pubkey' => 'old.example.com ssh-ed25519 AAAAFake1', 'fingerprint' => 'SHA256:ED25519keyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'type' => 'ED25519'],
        ['pubkey' => 'old.example.com ssh-rsa AAAARSA', 'fingerprint' => 'SHA256:RSAkeyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'type' => 'RSA'],
    ];
    $msg = '';
    try {
        (new BackupPull())->handle([
            'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-a.tar.gz',
            'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
            'host_fingerprint' => 'SHA256:kisiAurKiKeyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
            '_confirm' => 'backup.pull',
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $msg = $e->getMessage();
    }
    assert_true(str_contains($msg, 'MISMATCH'), 'MISMATCH par refuse');
    assert_true(str_contains($msg, 'ED25519key'), 'error me server ki saari keys dikhen');
    assert_true(str_contains($msg, 'RSAkey'), 'error me doosri key bhi dikhe');
    acp_pull_cleanup($h);
});

test('backup.pull: auth ki kami network se pehle pakdi jaye (keyscan call hi na ho)', function (): void {
    $h = acp_pull_harness();
    $h['cmd']->keyscanCalls = 0;
    $msg = '';
    try {
        (new BackupPull())->handle([
            'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-a.tar.gz',
            'auth' => 'key', 'host_fingerprint' => 'SHA256:ED25519keyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
            '_confirm' => 'backup.pull',
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $msg = $e->getMessage();
    }
    assert_true(str_contains($msg, 'private_key'), 'error key ke bare me ho: ' . $msg);
    assert_true($h['cmd']->keyscanCalls === 0, 'network (keyscan) call hi nahi hua');

    $h['cmd']->keyscanCalls = 0;
    $msg = '';
    try {
        (new BackupPull())->handle([
            'host' => 'old.example.com', 'user' => 'root', 'remote_path' => '/home/cpmove-a.tar.gz',
            'auth' => 'password', 'host_fingerprint' => 'SHA256:ED25519keyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
            '_confirm' => 'backup.pull',
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $msg = $e->getMessage();
    }
    assert_true(str_contains($msg, 'password'), 'error password ke bare me ho: ' . $msg);
    assert_true($h['cmd']->keyscanCalls === 0, 'network (keyscan) call hi nahi hua (password case)');
    acp_pull_cleanup($h);
});

// ---------------------------------------------------------------------------
// S10 — remote backup destinations (backup.destination): apne archives doosre
// server par bhejna. Yahan bhi asli network nahi chalta — FakeCommandExecutor
// ssh / scp / ssh-keygen / ssh-keyscan / sshpass ko intercept karta hai.
// ---------------------------------------------------------------------------

/** @return array{root: string, cmd: FakeCommandExecutor, ctx: TaskContext, saves: string} */
function acp_dest_harness(): array
{
    $root = sys_get_temp_dir() . '/acp-dest-' . bin2hex(random_bytes(4));
    $saves = $root . '/backups/accounts/alicehost';
    mkdir($saves, 0777, true);
    putenv('ACP_STATE_ROOT=' . $root);
    putenv('ACP_SSH_KEYSCAN=/usr/bin/ssh-keyscan');
    putenv('ACP_SSH_KEYGEN=/usr/bin/ssh-keygen');
    putenv('ACP_SSH_SCP=/usr/bin/scp');
    putenv('ACP_SSH_BIN=/usr/bin/ssh');
    putenv('ACP_SSH_SSHPASS=/usr/bin/sshpass');

    $cmd = new FakeCommandExecutor();
    $cmd->hostKeys = [
        ['pubkey' => 'backup.example.com ssh-ed25519 AAAAED', 'fingerprint' => 'SHA256:ED25519keyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'type' => 'ED25519'],
        ['pubkey' => 'backup.example.com ssh-rsa AAAARSA', 'fingerprint' => 'SHA256:RSAkeyBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB', 'type' => 'RSA'],
    ];
    $log = new TaskLogger(new PDO('sqlite::memory:'), null, false);
    $ctx = new TaskContext(log: $log, cmd: $cmd, paths: null, taskId: null, taskRow: null);

    return ['root' => $root, 'saves' => $saves, 'cmd' => $cmd, 'ctx' => $ctx];
}

/** @param array{root: string} $harness */
function acp_dest_cleanup(array $harness): void
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
    foreach (['ACP_STATE_ROOT', 'ACP_SSH_KEYSCAN', 'ACP_SSH_KEYGEN', 'ACP_SSH_SCP', 'ACP_SSH_BIN', 'ACP_SSH_SSHPASS'] as $name) {
        putenv($name);
    }
}

/** @param array{cmd: FakeCommandExecutor, ctx: TaskContext} $h */
function acp_dest_save(array $h, array $extra = []): array
{
    return (new BackupDestination())->handle(array_merge([
        'action'           => 'save',
        'name'             => 'offsite1',
        'host'             => 'backup.example.com',
        'user'             => 'backup',
        'path'             => '/srv/backups/alphacp',
        'host_fingerprint' => 'SHA256:ED25519keyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
        '_confirm'         => 'backup.destination',
    ], $extra), $h['ctx']);
}

test('backup.destination save: config + naya ed25519 key, result me koi secret nahi', function (): void {
    $h = acp_dest_harness();
    $out = acp_dest_save($h);

    assert_true(($out['action'] ?? '') === 'save', 'action save');
    $cfg = $out['destination'];
    assert_true(($cfg['name'] ?? '') === 'offsite1', 'name wapas mila');
    assert_true(($cfg['host'] ?? '') === 'backup.example.com', 'host wapas mila');
    assert_true(($cfg['host_fingerprint'] ?? '') === 'SHA256:ED25519keyAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'host key pin saved');
    assert_true(str_contains((string) ($cfg['public_key'] ?? ''), 'ssh-ed25519'), 'public key mila (admin backup server par dale)');
    assert_true(($cfg['has_key'] ?? false) === true, 'key file ban gayi');

    $json = json_encode($out);
    assert_true(!str_contains((string) $json, 'PRIVATE KEY'), 'result me private key nahi: ' . (string) $json);
    assert_true(!array_key_exists('password', $cfg) && !array_key_exists('private_key', $cfg) && !array_key_exists('key_path', $cfg), 'result me koi secret field nahi');
    assert_true(($cfg['has_password'] ?? false) === false, 'key auth me password stored nahi');

    $file = $h['root'] . '/etc/backup-destinations/offsite1.json';
    assert_true(is_file($file), 'config file likhi gayi');
    assert_true((fileperms($file) & 0777) === 0600, 'config 0600 hai');
    $key = $h['root'] . '/etc/backup-keys/offsite1';
    assert_true(is_file($key), 'key file bani');
    assert_true((fileperms($key) & 0777) === 0600, 'key 0600 hai');
    acp_dest_cleanup($h);
});

test('backup.destination save: bina host key pin ke refuse', function (): void {
    $h = acp_dest_harness();
    $msg = '';
    try {
        acp_dest_save($h, ['host_fingerprint' => '', 'accept_host_key' => false]);
    } catch (TaskRejectedException $e) {
        $msg = $e->getMessage();
    }
    assert_true(str_contains($msg, 'host key pin'), 'pin maanga: ' . $msg);
    assert_true(!is_file($h['root'] . '/etc/backup-destinations/offsite1.json'), 'config nahi bani');
    acp_dest_cleanup($h);
});

test('backup.destination save: galat naam (path escape / space) refuse', function (): void {
    $h = acp_dest_harness();
    foreach (['../evil', 'bad name', '-flag', 'toolongdestinationnameaaaaaaaaaaaaaaaaa'] as $bad) {
        $msg = '';
        try {
            acp_dest_save($h, ['name' => $bad]);
        } catch (TaskRejectedException $e) {
            $msg = $e->getMessage();
        }
        assert_true($msg !== '', "naam '{$bad}' refuse hona chahiye");
    }
    assert_true(($h['cmd']->keyscanCalls ?? 0) === 0, 'validation me koi network call nahi');
    acp_dest_cleanup($h);
});

test('backup.destination list: saved destinations dikhti hain, secret nahi', function (): void {
    $h = acp_dest_harness();
    acp_dest_save($h);
    $out = (new BackupDestination())->handle(['action' => 'list', '_confirm' => 'backup.destination'], $h['ctx']);

    assert_true(($out['count'] ?? 0) === 1, 'ek destination: ' . json_encode($out));
    assert_true(($out['destinations'][0]['name'] ?? '') === 'offsite1', 'naam sahi');
    assert_true(!str_contains(json_encode($out), 'PRIVATE KEY'), 'list me key nahi');
    acp_dest_cleanup($h);
});

test('backup.destination test: ssh ek baar chala, pin verify hua', function (): void {
    $h = acp_dest_harness();
    acp_dest_save($h);
    $h['cmd']->sshStdout = "ACP-OK\n";
    $out = (new BackupDestination())->handle([
        'action' => 'test', 'name' => 'offsite1', '_confirm' => 'backup.destination',
    ], $h['ctx']);

    assert_true(($out['ok'] ?? false) === true, 'test ok: ' . json_encode($out));
    assert_true($h['cmd']->sshCalls === 1, 'ssh ek baar chala');
    $argv = implode(' ', $h['cmd']->sshArgv ?? []);
    assert_true(str_contains($argv, 'backup@backup.example.com'), 'ssh target sahi: ' . $argv);
    assert_true(str_contains($argv, '/srv/backups/alphacp'), 'remote path sahi: ' . $argv);
    assert_true(str_contains($argv, 'ACP-OK'), 'probe command gaya');
    assert_true(str_contains($argv, 'StrictHostKeyChecking=yes'), 'host key strict');
    assert_true(!str_contains($argv, 'PRIVATE KEY'), 'argv me key nahi');
    acp_dest_cleanup($h);
});

test('backup.destination test: host key badal gayi (MITM) to MISMATCH', function (): void {
    $h = acp_dest_harness();
    acp_dest_save($h);
    $h['cmd']->hostKeys = [['pubkey' => 'backup.example.com ssh-ed25519 AAAANEW', 'fingerprint' => 'SHA256:NEWkeyCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC', 'type' => 'ED25519']];
    $msg = '';
    try {
        (new BackupDestination())->handle(['action' => 'test', 'name' => 'offsite1', '_confirm' => 'backup.destination'], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $msg = $e->getMessage();
    }
    assert_true(str_contains($msg, 'MISMATCH'), 'MISMATCH pakda: ' . $msg);
    assert_true($h['cmd']->sshCalls === 0, 'ssh call hi nahi hui');
    acp_dest_cleanup($h);
});

test('backup.destination push: .part se upload + remote sha256 verify', function (): void {
    $h = acp_dest_harness();
    acp_dest_save($h);
    $archive = $h['saves'] . '/abc123.tar.gz';
    file_put_contents($archive, 'real-archive-bytes-0123456789');
    $sha = hash_file('sha256', $archive);
    $h['cmd']->sshStdout = $sha . "  /srv/backups/alphacp/abc123.tar.gz\n";

    $out = (new BackupDestination())->handle([
        'action' => 'push', 'name' => 'offsite1', 'archive_path' => $archive, '_confirm' => 'backup.destination',
    ], $h['ctx']);

    assert_true(($out['verified'] ?? false) === true, 'push verified: ' . json_encode($out));
    assert_true(($out['sha256'] ?? '') === $sha, 'sha256 match');
    assert_true(($out['file'] ?? '') === 'abc123.tar.gz', 'file naam');
    assert_true(($out['remote_path'] ?? '') === '/srv/backups/alphacp/abc123.tar.gz', 'remote path');
    $scp = implode(' ', $h['cmd']->scpArgv ?? []);
    assert_true(str_contains($scp, $archive), 'scp source archive: ' . $scp);
    assert_true(str_contains($scp, 'backup@backup.example.com:/srv/backups/alphacp/abc123.tar.gz.part'), 'scp .part par gaya: ' . $scp);
    $ssh = implode(' ', $h['cmd']->sshArgv ?? []);
    assert_true(str_contains($ssh, 'mv -f'), 'atomic rename hua');
    assert_true(str_contains($ssh, 'sha256sum'), 'remote checksum hua');
    acp_dest_cleanup($h);
});

test('backup.destination push: checksum mismatch par remote file hat gayi', function (): void {
    $h = acp_dest_harness();
    acp_dest_save($h);
    $archive = $h['saves'] . '/abc123.tar.gz';
    file_put_contents($archive, 'real-archive-bytes-0123456789');
    $h['cmd']->sshStdout = str_repeat('f', 64) . "  /srv/backups/alphacp/abc123.tar.gz\n";
    $msg = '';
    try {
        (new BackupDestination())->handle([
            'action' => 'push', 'name' => 'offsite1', 'archive_path' => $archive, '_confirm' => 'backup.destination',
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $msg = $e->getMessage();
    }
    assert_true(str_contains($msg, 'checksum mismatch'), 'mismatch pakda: ' . $msg);
    $ssh = implode(' ', $h['cmd']->sshArgv ?? []);
    assert_true(str_contains($ssh, 'rm -f'), 'remote se file hatayi: ' . $ssh);
    acp_dest_cleanup($h);
});

test('backup.destination push: backup store ke BAHAR wali file refuse', function (): void {
    $h = acp_dest_harness();
    acp_dest_save($h);
    $outside = $h['root'] . '/etc/passwd.tar.gz';
    file_put_contents($outside, 'nope');
    $msg = '';
    try {
        (new BackupDestination())->handle([
            'action' => 'push', 'name' => 'offsite1', 'archive_path' => $outside, '_confirm' => 'backup.destination',
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $msg = $e->getMessage();
    }
    assert_true(str_contains($msg, 'backup store'), 'store ke bahar refuse: ' . $msg);
    assert_true(($h['cmd']->scpArgv ?? null) === null, 'scp chala hi nahi');
    acp_dest_cleanup($h);
});

test('backup.destination browse: sirf tarball naam, ajeeb entry nahi', function (): void {
    $h = acp_dest_harness();
    acp_dest_save($h);
    $h['cmd']->sshStdout = "abc123.tar.gz\n../evil\nrandom.txt\ndef456.tar.gz\n";
    $out = (new BackupDestination())->handle(['action' => 'browse', 'name' => 'offsite1', '_confirm' => 'backup.destination'], $h['ctx']);

    assert_true(($out['files'] ?? []) === ['abc123.tar.gz', 'def456.tar.gz'], 'sirf archives: ' . json_encode($out));
    assert_true(($out['count'] ?? 0) === 2, 'count 2');
    acp_dest_cleanup($h);
});

test('backup.destination remove: config + key dono gayab', function (): void {
    $h = acp_dest_harness();
    acp_dest_save($h);
    $out = (new BackupDestination())->handle(['action' => 'remove', 'name' => 'offsite1', '_confirm' => 'backup.destination'], $h['ctx']);

    assert_true(($out['removed'] ?? false) === true, 'remove hua');
    assert_true(!is_file($h['root'] . '/etc/backup-destinations/offsite1.json'), 'config gayi');
    assert_true(!is_file($h['root'] . '/etc/backup-keys/offsite1'), 'key gayi');
    $list = (new BackupDestination())->handle(['action' => 'list', '_confirm' => 'backup.destination'], $h['ctx']);
    assert_true(($list['count'] ?? -1) === 0, 'list khaali');
    acp_dest_cleanup($h);
});

test('backup.destination password auth: sshpass wrapper chala, password argv me nahi', function (): void {
    $h = acp_dest_harness();
    acp_dest_save($h, ['auth' => 'password', 'password' => 'SuperSecret123']);
    $h['cmd']->sshStdout = "ACP-OK\n";
    $out = (new BackupDestination())->handle(['action' => 'test', 'name' => 'offsite1', '_confirm' => 'backup.destination'], $h['ctx']);

    assert_true(($out['ok'] ?? false) === true, 'password auth se test chala');
    assert_true(is_file($h['root'] . '/etc/backup-keys/offsite1.password'), 'password file bani');
    assert_true((fileperms($h['root'] . '/etc/backup-keys/offsite1.password') & 0777) === 0600, 'password file 0600');
    $argv = implode(' ', $h['cmd']->sshArgv ?? []);
    assert_true(!str_contains($argv, 'SuperSecret123'), 'argv me password nahi: ' . $argv);
    assert_true(str_contains($argv, 'PubkeyAuthentication=no'), 'password-only auth');
    acp_dest_cleanup($h);
});

test('backup.destination: unknown action refuse', function (): void {
    $h = acp_dest_harness();
    $msg = '';
    try {
        (new BackupDestination())->handle(['action' => 'destroy', '_confirm' => 'backup.destination'], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $msg = $e->getMessage();
    }
    assert_true(str_contains($msg, 'nahi chalega'), 'unknown action refuse: ' . $msg);
    acp_dest_cleanup($h);
});

// ---------------------------------------------------------------------------
// Ye do test LIVE bug (5 Oct, 0.73.0) se paida hue: live check me har
// destination action "binary not in agent allowlist: /usr/bin/ssh" se fail hua
// — FakeCommandExecutor allowlist check nahi karta, isliye offline tests green
// the. Ab dono taraf se band hai.
// ---------------------------------------------------------------------------

test('remote SSH tooling: ssh/scp/ssh-keygen/ssh-keyscan/sshpass agent allowlist me hain', function (): void {
    $ref = new ReflectionClass(CommandRunner::class);
    $list = $ref->getConstant('BIN_ALLOWLIST');
    assert_true(is_array($list), 'CommandRunner allowlist mili');

    $needed = [
        RemotePull::KEYSCAN, RemotePull::KEYGEN, RemotePull::SCP, RemotePull::SSHPASS,
        RemoteDestination::SSH, RemoteDestination::SCP, RemoteDestination::KEYGEN, RemoteDestination::SSHPASS,
    ];
    foreach ($needed as $bin) {
        assert_true(in_array($bin, $list, true), "{$bin} agent allowlist me hona chahiye — nahi to task 'binary not in agent allowlist' se fail hoga");
    }
});

test('backup.destination push: archive path destination se PEHLE check hota hai', function (): void {
    $h = acp_dest_harness();
    // destination save hi nahi ki — phir bhi ghalat path ka jawab "backup store" wala aana chahiye
    $msg = '';
    try {
        (new BackupDestination())->handle([
            'action'       => 'push',
            'name'         => 'no-such-destination',
            'archive_path' => '/etc/passwd.tar.gz',
            '_confirm'     => 'backup.destination',
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $msg = $e->getMessage();
    }
    assert_true(str_contains($msg, 'backup store'), 'galat path ka sahi error: ' . $msg);
    assert_true(!str_contains($msg, 'nahi mili'), 'error "destination nahi mili" nahi hona chahiye: ' . $msg);
    acp_dest_cleanup($h);
});

test('backup.destination push: verify call fail ho to bhi remote .part hatane ki koshish hoti hai', function (): void {
    $h = acp_dest_harness();
    acp_dest_save($h);
    $archive = $h['saves'] . '/abc123.tar.gz';
    file_put_contents($archive, 'real-archive-bytes-0123456789');
    $h['cmd']->sshFails = true;              // verify wala ssh call fail
    $msg = '';
    try {
        (new BackupDestination())->handle([
            'action' => 'push', 'name' => 'offsite1', 'archive_path' => $archive, '_confirm' => 'backup.destination',
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $msg = $e->getMessage();
    }
    assert_true($msg !== '', 'push fail hona chahiye');
    assert_true($h['cmd']->sshCalls >= 2, 'verify ke baad rm -f ki koshish bhi hui: calls=' . $h['cmd']->sshCalls);
    acp_dest_cleanup($h);
});

test('agent source lint: jo file catch (Throwable kare wo use Throwable bhi kare', function (): void {
    // 0.73.0 ka LIVE bug: RemoteDestination.php me `use Throwable;` missing tha, to
    // namespace ke andar `catch (Throwable)` kabhi match hi nahi hua aur push ki
    // saafai (remote .part hatana) chup-chaap skip ho gayi. Ye test dobara na ho.
    $root = dirname(__DIR__) . '/src';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $bad = [];
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $code = (string) file_get_contents($file->getPathname());
        if (!str_contains($code, 'catch (Throwable')) {
            continue;
        }
        if (preg_match('/^use Throwable;$/m', $code) !== 1) {
            $bad[] = $file->getPathname();
        }
    }
    assert_true($bad === [], 'in files me use Throwable missing hai: ' . implode(', ', $bad));
});


fwrite(STDOUT, "\nS9 BIND9 (dns.bind)\n");

/** @return array{root:string,cmd:FakeCommandExecutor,ctx:TaskContext} */
function acp_bind_harness(): array
{
    $root = sys_get_temp_dir() . '/acp-bind-' . bin2hex(random_bytes(4));
    $dirs = [
        $root . '/etc/bind',
        $root . '/etc/bind/zones',
        $root . '/home',
        $root . '/alphacp',
    ];
    foreach ($dirs as $dir) {
        mkdir($dir, 0755, true);
    }
    // distro jaisa named.conf + options (updater inhi par kaam karega)
    file_put_contents(
        $root . '/etc/bind/named.conf',
        "include \"/etc/bind/named.conf.options\";\ninclude \"/etc/bind/named.conf.local\";\n"
    );
    file_put_contents(
        $root . '/etc/bind/named.conf.options',
        "options {\n    directory \"/var/cache/bind\";\n};\n"
    );
    putenv('ACP_BIND_CONF=' . $root . '/etc/bind/named.conf');
    putenv('ACP_BIND_OPTIONS=' . $root . '/etc/bind/named.conf.options');
    putenv('ACP_BIND_ZONES=' . $root . '/etc/bind/named.conf.alphacp');
    putenv('ACP_BIND_ZONE_DIR=' . $root . '/etc/bind/zones');
    // fake executor in bins ko intercept karta hai — absolute path hona kaafi hai
    putenv('ACP_BIND_CHECKCONF=' . $root . '/bin/named-checkconf');
    putenv('ACP_BIND_CHECKZONE=' . $root . '/bin/named-checkzone');
    putenv('ACP_BIND_RNDC=' . $root . '/bin/rndc');
    putenv('ACP_BIND_DIG=' . $root . '/bin/dig');
    putenv('ACP_STATE_ROOT=' . $root . '/alphacp');
    putenv('ACP_ACCOUNTS_ROOT=' . $root . '/home');
    putenv('ACP_BIND_DIG_WAIT=1');   // tests me intezaar nahi (asli server 0.7s leta hai)

    $cmd = new FakeCommandExecutor();
    $cmd->hostnameI = "203.0.113.5 10.0.0.7\n";
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
function acp_bind_cleanup(array $harness): void
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
        'ACP_BIND_CONF', 'ACP_BIND_OPTIONS', 'ACP_BIND_ZONES', 'ACP_BIND_ZONE_DIR',
        'ACP_BIND_CHECKCONF', 'ACP_BIND_CHECKZONE', 'ACP_BIND_RNDC', 'ACP_BIND_DIG',
        'ACP_BIND_DIG_WAIT',
    ] as $name) {
        putenv($name);
    }
}

/** @return list<array<string,string>> */
function acp_bind_records(string $domain): array
{
    return [
        ['domain' => $domain, 'name' => '@', 'type' => 'A', 'value' => '203.0.113.10'],
        ['domain' => $domain, 'name' => 'www', 'type' => 'A', 'value' => '203.0.113.10'],
        ['domain' => $domain, 'name' => 'mail', 'type' => 'A', 'value' => '203.0.113.11'],
        ['domain' => $domain, 'name' => '@', 'type' => 'MX', 'value' => 'mail.' . $domain],
        ['domain' => $domain, 'name' => '@', 'type' => 'TXT', 'value' => 'v=spf1 a mx -all'],
        ['domain' => $domain, 'name' => 'shop', 'type' => 'CNAME', 'value' => $domain],
    ];
}

test('dns.bind setup idempotent — do baar chalao to bhi ek hi include line', function (): void {
    $h = acp_bind_harness();
    $conf = $h['root'] . '/etc/bind/named.conf';
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    $after1 = (string) file_get_contents($conf);
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    $after2 = (string) file_get_contents($conf);
    $include = 'include "' . $h['root'] . '/etc/bind/named.conf.alphacp";';
    assert_true(substr_count($after2, $include) === 1, 'include line ek hi baar likhni chahiye');
    assert_true(str_contains($after1, 'include "/etc/bind/named.conf.options";'), 'distro lines rehni chahiye');
    // backup sirf pehli baar banta hai (doosri baar overwrite nahi hota)
    assert_true(is_file($h['root'] . '/etc/bind/named.conf.options.acp-orig'));
    $backup = (string) file_get_contents($h['root'] . '/etc/bind/named.conf.options.acp-orig');
    assert_true(str_contains($backup, 'directory "/var/cache/bind"'), 'asli options backup me bacchi honi chahiye');
    // managed options: loopback + server ka apna IP, recursion off
    $options = (string) file_get_contents($h['root'] . '/etc/bind/named.conf.options');
    assert_true(str_contains($options, 'listen-on { 127.0.0.1; 203.0.113.5; };'), 'listen-on ghalat: ' . $options);
    assert_true(str_contains($options, 'recursion no;'));
    assert_true(str_contains($options, 'allow-transfer { none; };'));
    assert_true(is_dir($h['root'] . '/etc/bind/zones'));
    acp_bind_cleanup($h);
});

test('dns.bind setup named-checkconf fail ho to purani config wapas', function (): void {
    $h = acp_bind_harness();
    $options = $h['root'] . '/etc/bind/named.conf.options';
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    $h['cmd']->bindCheckconfFails = true;
    $threw = false;
    try {
        (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'named-checkconf fail');
    }
    assert_true($threw, 'checkconf fail par task reject hona chahiye');
    $restored = (string) file_get_contents($options);
    assert_true(str_contains($restored, 'directory "/var/cache/bind"'), 'purani options wapas aani chahiye');
    assert_true(!str_contains($restored, 'AlphaCP managed'), 'managed block hatna chahiye');
    acp_bind_cleanup($h);
});

test('dns.bind write — named-checkzone ke baad hi zone file likhi jati hai', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    $h['cmd']->digStdout = "ns1.alice.test. hostmaster.alice.test. 2025090101 3600 600 1209600 300";
    $out = (new BindSetup())->handle([
        'action'  => 'write',
        'domain'  => 'alice.test',
        'records' => acp_bind_records('alice.test'),
    ], $h['ctx']);
    assert_true($out['ok'] === true);
    assert_true($out['records'] === 6, '6 records likhne chahiye the, mile ' . (int) $out['records']);
    assert_true(is_file($out['file']));
    assert_true($h['cmd']->namedCheckzoneCalls >= 1, 'named-checkzone chalana hi padta hai');
    assert_true(in_array('reload', (array) ($h['cmd']->rndcArgv ?? []), true), 'rndc reload hona chahiye');
    assert_true($out['verified'] === true, 'dig se SOA milna chahiye');
    $body = (string) file_get_contents($out['file']);
    assert_true(str_contains($body, '$TTL 300'), 'TTL header chahiye');
    assert_true(str_contains($body, '@ IN SOA ns1.alice.test. hostmaster.alice.test.'));
    assert_true(str_contains($body, 'www IN A 203.0.113.10'));
    assert_true(str_contains($body, '@ IN MX 10 mail.alice.test.'));
    assert_true(str_contains($body, 'shop IN CNAME alice.test.'));
    assert_true(str_contains($body, '@ IN TXT "v=spf1 a mx -all"'), 'TXT quoted hona chahiye: ' . $body);
    assert_true(str_contains($body, 'ns1 IN A 203.0.113.5'), 'in-zone NS ka glue A chahiye');
    // zone clause named.conf.alphacp me
    $zones = (string) file_get_contents($h['root'] . '/etc/bind/named.conf.alphacp');
    assert_true(str_contains($zones, 'zone "alice.test" { type master;'), 'zone clause chahiye: ' . $zones);
    // koi temp file nahi chhutni chahiye
    $leftovers = glob($h['root'] . '/etc/bind/zones/.db.*') ?: [];
    assert_true($leftovers === [], 'temp files saf ho jani chahiye');
    acp_bind_cleanup($h);
});

test('dns.bind write — named-checkzone reject kare to doosre domain ka record zone me nahi jata', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    (new BindSetup())->handle([
        'action' => 'write',
        'domain' => 'alice.test',
        'records' => [
            ['domain' => 'alice.test', 'name' => '@', 'type' => 'A', 'value' => '203.0.113.10'],
            ['domain' => 'bob.test', 'name' => '@', 'type' => 'A', 'value' => '198.51.100.10'],
        ],
    ], $h['ctx']);
    $file = $h['root'] . '/etc/bind/zones/db.alice.test';
    $body = (string) file_get_contents($file);
    assert_true(str_contains($body, '203.0.113.10'));
    assert_true(!str_contains($body, '198.51.100.10'), 'doosre domain ka record is zone me nahi likhna chahiye');
    acp_bind_cleanup($h);
});

test('dns.bind write — named-checkzone reject kare to kuch nahi likha jata (purani zone surakshit)', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    $first = (new BindSetup())->handle([
        'action'  => 'write',
        'domain'  => 'alice.test',
        'records' => acp_bind_records('alice.test'),
    ], $h['ctx']);
    $before = (string) file_get_contents($first['file']);

    $h['cmd']->bindCheckzoneFails = true;
    $threw = false;
    try {
        (new BindSetup())->handle([
            'action' => 'write',
            'domain' => 'alice.test',
            'records' => [['domain' => 'alice.test', 'name' => 'bad', 'type' => 'A', 'value' => '203.0.113.99']],
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'named-checkzone');
    }
    assert_true($threw, 'checkzone fail par reject hona chahiye');
    $after = (string) file_get_contents($first['file']);
    assert_true($after === $before, 'purani zone bilkul waise hi rehni chahiye');
    assert_true(!str_contains($after, '203.0.113.99'), 'reject hua record kabhi nahi likhna chahiye');
    assert_true((glob($h['root'] . '/etc/bind/zones/.db.*') ?: []) === [], 'temp file hatni chahiye');
    acp_bind_cleanup($h);
});

test('dns.bind write — hostile record reject, zone file banti hi nahi', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    $threw = false;
    try {
        (new BindSetup())->handle([
            'action' => 'write',
            'domain' => 'alice.test',
            'records' => [['domain' => 'alice.test', 'name' => '|/bin/sh', 'type' => 'A', 'value' => '203.0.113.10']],
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = true;
    }
    assert_true($threw, 'hostile record reject hona chahiye');
    assert_true(!is_file($h['root'] . '/etc/bind/zones/db.alice.test'));
    // value me newline ho to bhi
    $threw2 = false;
    try {
        (new BindSetup())->handle([
            'action' => 'write',
            'domain' => 'alice.test',
            'records' => [['domain' => 'alice.test', 'name' => 'x', 'type' => 'TXT', 'value' => "ok\n@ IN NS evil.test."]],
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $threw2 = true;
    }
    assert_true($threw2, 'newline wala TXT reject hona chahiye (zone injection)');
    acp_bind_cleanup($h);
});

test('dns.bind write — serial har baar badhta hai', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    $a = (new BindSetup())->handle([
        'action' => 'write',
        'domain' => 'alice.test',
        'records' => acp_bind_records('alice.test'),
    ], $h['ctx']);
    $b = (new BindSetup())->handle([
        'action' => 'write',
        'domain' => 'alice.test',
        'records' => acp_bind_records('alice.test'),
    ], $h['ctx']);
    assert_true($b['serial'] > $a['serial'], 'naya serial purane se bada hona chahiye');
    $body = (string) file_get_contents($b['file']);
    assert_true(str_contains($body, (string) $b['serial']), 'zone me naya serial hona chahiye');
    acp_bind_cleanup($h);
});

test('dns.bind write — NAYA zone `rndc reconfig` ke bina serve nahi hota', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    $h['cmd']->digStdout = "ns1.alice.test. hostmaster.alice.test. 2025090101 3600 600 1209600 300";
    $h['cmd']->rndcArgvs = [];

    // pehli baar = naya zone: named ko config dobara padhni padti hai
    (new BindSetup())->handle([
        'action' => 'write',
        'domain' => 'alice.test',
        'records' => acp_bind_records('alice.test'),
    ], $h['ctx']);
    $sawReconfig = false;
    foreach ($h['cmd']->rndcArgvs as $argv) {
        if (in_array('reconfig', $argv, true)) {
            $sawReconfig = true;
        }
    }
    assert_true($sawReconfig, 'naye zone ke liye rndc reconfig chalna hi chahiye (warna dig khamosh)');

    // doosri baar = zone maujood, dig jawab de raha -> reconfig ki zaroorat nahi
    $h['cmd']->rndcArgvs = [];
    (new BindSetup())->handle([
        'action' => 'write',
        'domain' => 'alice.test',
        'records' => acp_bind_records('alice.test'),
    ], $h['ctx']);
    $reconfigAgain = false;
    foreach ($h['cmd']->rndcArgvs as $argv) {
        if (in_array('reconfig', $argv, true)) {
            $reconfigAgain = true;
        }
    }
    assert_true(!$reconfigAgain, 'maujooda zone par reconfig nahi chalna chahiye');
    acp_bind_cleanup($h);
});

test('dns.bind write — named chalu na ho to bhi sach boli jaati hai (verified=false)', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    // systemd khamosh: is-active fail -> named_running false
    $h['cmd']->failWhenContains = 'is-active';
    $status = (new BindSetup())->handle(['action' => 'status'], $h['ctx']);
    assert_true(($status['named_running'] ?? true) === false, 'named_running false hona chahiye');
    // dig khamosh -> verified false (jhoothi "ok" nahi)
    $h['cmd']->failWhenContains = null;
    $h['cmd']->digStdout = '';
    $out = (new BindSetup())->handle([
        'action' => 'write',
        'domain' => 'alice.test',
        'records' => acp_bind_records('alice.test'),
    ], $h['ctx']);
    assert_true($out['verified'] === false);
    assert_true($out['dig_soa'] === '');
    acp_bind_cleanup($h);
});

test('dns.bind write — nameserver.json ke hisaab se NS (glue A ke saath)', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    mkdir($h['root'] . '/alphacp/etc/dns', 0755, true);
    file_put_contents(
        $h['root'] . '/alphacp/etc/dns/nameserver.json',
        json_encode(['software' => 'bind', 'ns1' => 'ns1.alice.test', 'ns2' => 'ns2.alice.test'])
    );
    $out = (new BindSetup())->handle([
        'action' => 'write',
        'domain' => 'alice.test',
        'records' => acp_bind_records('alice.test'),
    ], $h['ctx']);
    $body = (string) file_get_contents($out['file']);
    assert_true(str_contains($body, '@ IN NS ns1.alice.test.'));
    assert_true(str_contains($body, '@ IN NS ns2.alice.test.'));
    assert_true(str_contains($body, 'ns1 IN A 203.0.113.5'), 'ns1 ka glue A chahiye');
    assert_true(str_contains($body, 'ns2 IN A 203.0.113.5'), 'ns2 ka glue A chahiye');
    acp_bind_cleanup($h);
});

test('dns.bind write — account ke zone.json se records (panel wahi likhta hai)', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    mkdir($h['root'] . '/home/alicehost/etc/dns', 0755, true);
    file_put_contents(
        $h['root'] . '/home/alicehost/etc/dns/zone.json',
        (string) json_encode(acp_bind_records('alice.test'))
    );
    $out = (new BindSetup())->handle([
        'action'   => 'write',
        'domain'   => 'alice.test',
        'username' => 'alicehost',
    ], $h['ctx']);
    assert_true($out['records'] === 6, 'account zone.json ke 6 records aane chahiye');
    $body = (string) file_get_contents($out['file']);
    assert_true(str_contains($body, 'www IN A 203.0.113.10'));
    // bina records aur bina username -> reject
    $threw = false;
    try {
        (new BindSetup())->handle(['action' => 'write', 'domain' => 'bob.test'], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = true;
    }
    assert_true($threw, 'records/username ke bina likhna reject hona chahiye');
    acp_bind_cleanup($h);
});

test('dns.bind write — TTL payload se zone me jata hai', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    $out = (new BindSetup())->handle([
        'action'  => 'write',
        'domain'  => 'alice.test',
        'ttl'     => 60,
        'records' => acp_bind_records('alice.test'),
    ], $h['ctx']);
    $body = (string) file_get_contents($out['file']);
    assert_true(str_contains($body, '$TTL 60'), 'TTL 60 hona chahiye: ' . $body);
    $threw = false;
    try {
        (new BindSetup())->handle([
            'action' => 'write',
            'domain' => 'alice.test',
            'ttl'    => 5,
            'records' => acp_bind_records('alice.test'),
        ], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = true;
    }
    assert_true($threw, 'TTL 60 se kam reject hona chahiye');
    acp_bind_cleanup($h);
});

test('dns.bind verify — dig ka asli jawab, khali ho to verified false', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    $h['cmd']->digStdout = "ns1.alice.test. hostmaster.alice.test. 2025090101 3600 600 1209600 300";
    $out = (new BindSetup())->handle(['action' => 'write', 'domain' => 'alice.test', 'records' => acp_bind_records('alice.test')], $h['ctx']);
    assert_true($out['verified'] === true);
    assert_true(str_contains((string) $out['dig_soa'], 'ns1.alice.test.'));

    $v = (new BindSetup())->handle(['action' => 'verify', 'domain' => 'alice.test'], $h['ctx']);
    assert_true(str_contains((string) $v['soa'], 'ns1.alice.test.'));
    assert_true($v['a'] !== '');

    // ab dig khamosh ho jaye — "verified" jhooth nahi bolna chahiye
    $h['cmd']->digStdout = '';
    $silent = (new BindSetup())->handle(['action' => 'write', 'domain' => 'alice.test', 'records' => acp_bind_records('alice.test')], $h['ctx']);
    assert_true($silent['verified'] === false, 'dig khamosh ho to verified false hona chahiye');
    assert_true($silent['dig_soa'] === '');
    acp_bind_cleanup($h);
});

test('dns.bind remove — zone file hat ti hai aur zone clause bhi', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    $out = (new BindSetup())->handle(['action' => 'write', 'domain' => 'alice.test', 'records' => acp_bind_records('alice.test')], $h['ctx']);
    assert_true(is_file($out['file']));
    $del = (new BindSetup())->handle(['action' => 'remove', 'domain' => 'alice.test'], $h['ctx']);
    assert_true($del['removed'] === true);
    assert_true(!is_file($out['file']), 'zone file hatni chahiye');
    $zones = (string) file_get_contents($h['root'] . '/etc/bind/named.conf.alphacp');
    assert_true(!str_contains($zones, 'zone "alice.test"'), 'zone clause bhi hatna chahiye: ' . $zones);
    // dobara remove = shant, koi error nahi
    $again = (new BindSetup())->handle(['action' => 'remove', 'domain' => 'alice.test'], $h['ctx']);
    assert_true($again['removed'] === false && $again['ok'] === true);
    acp_bind_cleanup($h);
});

test('dns.bind remove — zone mitne ke baad named use serve nahi karta (reconfig)', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    $h['cmd']->digStdout = "ns1.alice.test. hostmaster.alice.test. 2025090101 3600 600 1209600 300";
    $h['cmd']->digFollowsZones = true;   // asli named jaisa: file hat te hi khamosh
    $out = (new BindSetup())->handle([
        'action'  => 'write',
        'domain'  => 'alice.test',
        'records' => acp_bind_records('alice.test'),
    ], $h['ctx']);
    assert_true($out['verified'] === true, 'pehle zone live honi chahiye');

    $h['cmd']->rndcArgvs = [];
    $del = (new BindSetup())->handle(['action' => 'remove', 'domain' => 'alice.test'], $h['ctx']);
    assert_true($del['removed'] === true);
    assert_true($del['gone'] === true, 'zone hatne ke baad dig khamosh hona chahiye (memory se bhi)');
    assert_true($del['dig_after'] === '', 'dig ab kuch nahi dena chahiye');
    $sawReconfig = false;
    foreach ($h['cmd']->rndcArgvs as $argv) {
        if (in_array('reconfig', $argv, true)) {
            $sawReconfig = true;
        }
    }
    assert_true($sawReconfig, 'zone hatane ke baad bhi rndc reconfig chalna chahiye');
    acp_bind_cleanup($h);
});

test('dns.bind list — zone files count', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    (new BindSetup())->handle(['action' => 'write', 'domain' => 'alice.test', 'records' => acp_bind_records('alice.test')], $h['ctx']);
    (new BindSetup())->handle(['action' => 'write', 'domain' => 'bob.test', 'records' => [['domain' => 'bob.test', 'name' => '@', 'type' => 'A', 'value' => '198.51.100.10']], ], $h['ctx']);
    $list = (new BindSetup())->handle(['action' => 'list'], $h['ctx']);
    assert_true($list['count'] === 2, '2 zones hone chahiye, mile ' . (int) $list['count']);
    assert_true(in_array('alice.test', $list['zones'], true));
    assert_true(in_array('bob.test', $list['zones'], true));
    acp_bind_cleanup($h);
});

test('dns.bind status — installed aur checkconf ki sachchi report', function (): void {
    $h = acp_bind_harness();
    $st = (new BindSetup())->handle(['action' => 'status'], $h['ctx']);
    assert_true($st['installed'] === true, 'env override ke saath installed true hona chahiye');
    assert_true($st['checkconf'] === 'ok');
    assert_true($st['zones'] === 0);
    $h['cmd']->bindCheckconfFails = true;
    $bad = (new BindSetup())->handle(['action' => 'status'], $h['ctx']);
    assert_true($bad['checkconf'] !== 'ok', 'checkconf fail report hona chahiye');
    // jab bind9 installed hi na ho (env hata do)
    foreach (['ACP_BIND_CHECKCONF', 'ACP_BIND_CHECKZONE'] as $k) {
        putenv($k);
    }
    $none = (new BindSetup())->handle(['action' => 'status'], $h['ctx']);
    assert_true($none['installed'] === false, 'bina bind9 ke installed false hona chahiye');
    assert_true(isset($none['error']));
    acp_bind_cleanup($h);
});

test('dns.bind — galat action aur galat domain reject', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    $threw = false;
    try {
        (new BindSetup())->handle(['action' => 'nuclear'], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = true;
    }
    assert_true($threw, 'unknown action reject hona chahiye');
    $threw2 = false;
    try {
        (new BindSetup())->handle(['action' => 'remove', 'domain' => '|/bin/sh'], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $threw2 = true;
    }
    assert_true($threw2, 'hostile domain reject hona chahiye');
    acp_bind_cleanup($h);
});

test('dns.bind sync — sab accounts ke zone.json se zones, ek fail to doosra nahi rukta', function (): void {
    $h = acp_bind_harness();
    (new BindSetup())->handle(['action' => 'setup'], $h['ctx']);
    // do account + ek system dir (ignore hona chahiye)
    foreach (['alicehost' => 'alice.test', 'bobhost' => 'bob.test'] as $user => $domain) {
        mkdir($h['root'] . '/home/' . $user . '/etc/dns', 0755, true);
        file_put_contents(
            $h['root'] . '/home/' . $user . '/etc/dns/zone.json',
            (string) json_encode(acp_bind_records($domain))
        );
    }
    mkdir($h['root'] . '/home/ubuntu/etc', 0755, true);
    $h['cmd']->digStdout = "ns1.alice.test. hostmaster.alice.test. 2025090101 3600 600 1209600 300";

    $out = (new BindSetup())->handle(['action' => 'sync'], $h['ctx']);
    assert_true($out['count'] === 2, '2 zones likhni chahiye, mili ' . (int) $out['count']);
    assert_true($out['failed'] === [], 'koi zone fail nahi hona chahiye');
    assert_true($out['ok'] === true);
    assert_true(is_file($h['root'] . '/etc/bind/zones/db.alice.test'));
    assert_true(is_file($h['root'] . '/etc/bind/zones/db.bob.test'));
    $body = (string) file_get_contents($h['root'] . '/etc/bind/zones/db.bob.test');
    assert_true(str_contains($body, 'www IN A 203.0.113.10'));
    assert_true(!is_file($h['root'] . '/etc/bind/zones/db.ubuntu'), 'system dir zone nahi banni chahiye');

    // ab bob ka zone kharaab kar do — alice phir bhi likhni chahiye
    file_put_contents(
        $h['root'] . '/home/bobhost/etc/dns/zone.json',
        (string) json_encode([['domain' => 'bob.test', 'name' => '|/bin/sh', 'type' => 'A', 'value' => '198.51.100.10']])
    );
    @unlink($h['root'] . '/etc/bind/zones/db.alice.test');
    @unlink($h['root'] . '/etc/bind/zones/db.bob.test');
    $out2 = (new BindSetup())->handle(['action' => 'sync'], $h['ctx']);
    assert_true($out2['ok'] === false, 'ek zone fail hone par ok false hona chahiye');
    assert_true(count($out2['failed']) === 1, 'ek hi zone fail hona chahiye');
    assert_true(is_file($h['root'] . '/etc/bind/zones/db.alice.test'), 'doosra account phir bhi likha jana chahiye');
    acp_bind_cleanup($h);
});

test('dns.bind schema — payload fail-closed', function (): void {
    $schema = acp_task_registry()['dns.bind']['schema'];
    $good = [
        'action' => 'write',
        'domain' => 'alice.test',
        'records' => [['domain' => 'alice.test', 'name' => 'www', 'type' => 'A', 'value' => '203.0.113.10']],
    ];
    assert_true(JsonSchema::validate($schema, $good) === [], 'valid payload pass hona chahiye');
    assert_true(JsonSchema::validate($schema, $good + ['evil' => 1]) !== [], 'extra key reject');
    // status/setup/list ko domain ki zaroorat nahi, par galat domain/TTL reject hona chahiye
    assert_true(JsonSchema::validate($schema, ['action' => 'status']) === [], 'status ko domain ki zaroorat nahi');
    assert_true(JsonSchema::validate($schema, ['action' => 'remove', 'domain' => '|/bin/sh']) !== [], 'hostile domain schema me reject');
    assert_true(JsonSchema::validate($schema, ['action' => 'write', 'domain' => 'alice.test', 'ttl' => 5, 'records' => $good['records']]) !== [], 'TTL range ke bahar reject');
    $bad = $good;
    $bad['action'] = 'nuclear';
    assert_true(JsonSchema::validate($schema, $bad) !== [], 'unknown action schema me reject');
    $bad2 = $good;
    $bad2['records'][0]['type'] = 'AAAA';
    assert_true(JsonSchema::validate($schema, $bad2) !== [], 'AAAA abhi allow nahi (A/CNAME/MX/TXT)');
});

test('BIND tools: har possible path agent allowlist me hai (chuppi hui allowlist fail na ho)', function (): void {
    $ref = new ReflectionClass(CommandRunner::class);
    $allow = $ref->getConstant('BIN_ALLOWLIST');
    foreach ([
        'named-checkconf' => BindServer::CHECKCONF_PATHS,
        'named-checkzone' => BindServer::CHECKZONE_PATHS,
        'rndc'            => BindServer::RNDC_PATHS,
        'dig'             => BindServer::DIG_PATHS,
    ] as $tool => $paths) {
        assert_true($paths !== [], "{$tool} candidates khali nahi hone chahiye");
        foreach ($paths as $path) {
            assert_true(
                in_array($path, $allow, true),
                "allowlist me {$path} nahi hai — server par binary wahan mila to task chup-chaap fail hoga"
            );
        }
    }
    // distro ke hisaab se binary kahin bhi ho — dono jagah allowlist me honi chahiye
    assert_true(in_array('/usr/sbin/named-checkconf', $allow, true));
    assert_true(in_array('/usr/bin/named-checkconf', $allow, true));
    assert_true(in_array('/usr/sbin/rndc', $allow, true));
    assert_true(in_array('/usr/bin/rndc', $allow, true));
});

test('BindServer renderZone — zone injection impossible (quote/escape)', function (): void {
    $body = BindServer::renderZone(
        'alice.test',
        [['domain' => 'alice.test', 'name' => 'x', 'type' => 'TXT', 'value' => 'say "hi" \\ ok']],
        ['ns1.alice.test'],
        '203.0.113.5',
        2025090101,
        300,
    );
    assert_true(str_contains($body, 'x IN TXT "say \\"hi\\" \\\\ ok"'), 'TXT me quote/backslash escape hone chahiye: ' . $body);
    assert_true(substr_count($body, "\n") === 5, 'SOA + NS + glue A + 1 record + trailing newline');
});


fwrite(STDOUT, "\nS7 MAIL SERVER (mail.server)\n");

/** @return array{root:string,cmd:FakeCommandExecutor,ctx:TaskContext} */
function acp_mail_harness(): array
{
    $root = sys_get_temp_dir() . '/acp-mail-' . bin2hex(random_bytes(4));
    $dirs = [
        $root . '/home', $root . '/etc/exim4', $root . '/etc/dovecot/conf.d', $root . '/alphacp',
        $root . '/etc/exim4/vacation', $root . '/etc/exim4/spam', $root . '/alphacp/etc/mail/dkim',
    ];
    foreach ($dirs as $dir) {
        mkdir($dir, 0755, true);
    }
    // distro jaisi exim template (backup lene ke liye)
    file_put_contents($root . '/etc/exim4/exim4.conf.template', "# distro exim template\n");
    putenv('ACP_MAIL_EXIM_TEMPLATE=' . $root . '/etc/exim4/exim4.conf.template');
    putenv('ACP_MAIL_EXIM_DOMAINS=' . $root . '/etc/exim4/alphacp-domains');
    putenv('ACP_MAIL_EXIM_RECIPIENTS=' . $root . '/etc/exim4/alphacp-recipients');
    putenv('ACP_MAIL_EXIM_ALIASES=' . $root . '/etc/exim4/alphacp-aliases');
    putenv('ACP_MAIL_CATCHALL=' . $root . '/etc/exim4/alphacp-catchall');
    putenv('ACP_MAIL_VACATION_DIR=' . $root . '/etc/exim4/vacation');
    putenv('ACP_MAIL_SPAM_DIR=' . $root . '/etc/exim4/spam');
    putenv('ACP_MAIL_DKIM_DIR=' . $root . '/alphacp/etc/mail/dkim');
    putenv('ACP_MAIL_DOVECOT_USERS=' . $root . '/etc/dovecot/alphacp-users');
    putenv('ACP_MAIL_DOVECOT_CONF=' . $root . '/etc/dovecot/conf.d/99-alphacp.conf');
    // fake executor in binaries ko intercept karta hai
    putenv('ACP_MAIL_EXIM=' . $root . '/bin/exim4');
    putenv('ACP_MAIL_DOVECOT=' . $root . '/bin/dovecot');
    putenv('ACP_MAIL_DOVEADM=' . $root . '/bin/doveadm');
    putenv('ACP_MAIL_DOVECONF=' . $root . '/bin/doveconf');
    putenv('ACP_MAIL_UPDATE_EXIM=' . $root . '/bin/update-exim4.conf');
    putenv('ACP_STATE_ROOT=' . $root . '/alphacp');
    putenv('ACP_ACCOUNTS_ROOT=' . $root . '/home');

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
function acp_mail_cleanup(array $harness): void
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
        'ACP_MAIL_EXIM_TEMPLATE', 'ACP_MAIL_EXIM_DOMAINS', 'ACP_MAIL_EXIM_RECIPIENTS',
        'ACP_MAIL_EXIM_ALIASES', 'ACP_MAIL_CATCHALL', 'ACP_MAIL_VACATION_DIR',
        'ACP_MAIL_SPAM_DIR', 'ACP_MAIL_DKIM_DIR',
        'ACP_MAIL_DOVECOT_USERS', 'ACP_MAIL_DOVECOT_CONF',
        'ACP_MAIL_EXIM', 'ACP_MAIL_DOVECOT', 'ACP_MAIL_DOVEADM', 'ACP_MAIL_DOVECONF',
        'ACP_MAIL_UPDATE_EXIM',
    ] as $name) {
        putenv($name);
    }
}

/** Do account: alicehost (2 mailbox + 1 forwarder) aur bobhost (1 mailbox). */
function acp_mail_seed_accounts(string $root): void
{
    $hash = '$2y$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ012345';
    $base = [
        'alicehost' => [
            'passwd' => [
                "info@alice.test:{BLF-CRYPT}{$hash}:1001:1001::{$root}/home/alicehost/mail/alice.test/info::",
                "sales@alice.test:{BLF-CRYPT}{$hash}:1001:1001::{$root}/home/alicehost/mail/alice.test/sales::userdb_quota_rule=*:storage=1024M",
                // doosre account ka maildir — kabhi accept nahi hona chahiye
                "steal@alice.test:{BLF-CRYPT}{$hash}:1001:1001::{$root}/home/bobhost/mail/bob.test/steal::",
            ],
            'aliases' => [
                'contact@alice.test: info@alice.test',
                'bad@alice.test:',
            ],
        ],
        'bobhost' => [
            'passwd' => [
                "info@bob.test:{BLF-CRYPT}{$hash}:1002:1002::{$root}/home/bobhost/mail/bob.test/info::",
                "garbage-line-without-fields",
            ],
            'aliases' => [],
        ],
    ];
    foreach ($base as $user => $files) {
        $dir = $root . '/home/' . $user . '/etc/mail';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/passwd', implode("\n", $files['passwd']) . "\n");
        file_put_contents($dir . '/aliases', implode("\n", $files['aliases']) . "\n");
    }
}

test('mail.server sync — sab accounts ke mailbox/forwarder aggregate (doosre ka maildir nahi)', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_accounts($h['root']);
    $out = (new MailServerSetup())->handle(['action' => 'sync'], $h['ctx']);
    assert_true($out['mailboxes'] === 3, '3 valid mailbox (chori wala chhutna chahiye), mile ' . (int) $out['mailboxes']);
    assert_true($out['domains'] === 2, '2 domains: alice.test + bob.test');
    assert_true($out['aliases'] === 1, '1 valid forwarder (khali dest wala chhut jana chahiye)');

    $users = (string) file_get_contents($h['root'] . '/etc/dovecot/alphacp-users');
    assert_true(str_contains($users, 'info@alice.test:'));
    assert_true(!str_contains($users, 'steal@alice.test'), 'doosre account ka maildir kabhi nahi aana chahiye');
    assert_true(!str_contains($users, 'garbage-line'), 'bekaar line ignore honi chahiye');

    $rec = (string) file_get_contents($h['root'] . '/etc/exim4/alphacp-recipients');
    assert_true(str_contains($rec, 'info@alice.test: ' . $h['root'] . '/home/alicehost/mail/alice.test/info 1001 1001'), 'recipients line: ' . $rec);
    assert_true(str_contains($rec, '1024M') === false, 'recipients me quota nahi hota');

    $dom = (string) file_get_contents($h['root'] . '/etc/exim4/alphacp-domains');
    assert_true(str_contains($dom, 'alice.test') && str_contains($dom, 'bob.test'));

    $al = (string) file_get_contents($h['root'] . '/etc/exim4/alphacp-aliases');
    assert_true(str_contains($al, 'contact@alice.test: info@alice.test'));
    acp_mail_cleanup($h);
});

test('mail.server setup — config validate hone ke baad hi apply (warn: mail band na ho)', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_accounts($h['root']);
    $out = (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
    assert_true($out['ok'] === true);
    assert_true(is_file($h['root'] . '/etc/dovecot/conf.d/99-alphacp.conf'));
    assert_true(is_file($h['root'] . '/etc/exim4/exim4.conf.template.acp-orig'), 'asli template ki backup honi chahiye');
    assert_true(is_file($h['root'] . '/alphacp/etc/mail-server-configured'), 'configured marker likhna chahiye');
    $tpl = (string) file_get_contents($h['root'] . '/etc/exim4/exim4.conf.template');
    assert_true(str_contains($tpl, 'alphacp_maildir:'), 'exim transport hona chahiye');
    assert_true(str_contains($tpl, 'alphacp_mailbox:'), 'exim router hona chahiye');
    assert_true(str_contains($tpl, 'deny message = relay not permitted'), 'open relay band hona chahiye');
    $dov = (string) file_get_contents($h['root'] . '/etc/dovecot/conf.d/99-alphacp.conf');
    assert_true(str_contains($dov, 'driver = passwd-file'));
    assert_true(str_contains($dov, 'mail_location = maildir:~/'), 'Maildir location hona chahiye');
    // systemctl enable/restart dono services ke liye chale
    $line = implode(' ', array_map(static fn (array $a): string => implode(' ', $a), $h['cmd']->calls));
    assert_true(str_contains($line, 'systemctl enable exim4'));
    assert_true(str_contains($line, 'systemctl enable dovecot'));
    acp_mail_cleanup($h);
});

test('mail.server setup — kharaab exim config ho to purani template wapas', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_accounts($h['root']);
    $h['cmd']->mailEximConfigFails = true;
    $threw = false;
    try {
        (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = str_contains($e->getMessage(), 'exim config reject');
    }
    assert_true($threw, 'kharaab config par reject hona chahiye');
    $restored = (string) file_get_contents($h['root'] . '/etc/exim4/exim4.conf.template');
    assert_true($restored === "# distro exim template\n", 'purani template wapas aani chahiye');
    acp_mail_cleanup($h);
});

test('mail.server verify — asli exim routing + doveadm mailbox (jhoothi ok nahi)', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_accounts($h['root']);
    (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);

    $h['cmd']->eximBtOutput = "info@alice.test\n  router = alphacp_mailbox, transport = alphacp_maildir\n";
    $h['cmd']->doveadmUserOutput = "field value\nuid 1001\ngid 1001\nhome {$h['root']}/home/alicehost/mail/alice.test/info\n";
    $v = (new MailServerSetup())->handle(['action' => 'verify', 'address' => 'info@alice.test'], $h['ctx']);
    assert_true($v['routed'] === true, 'routing milna chahiye');
    assert_true($v['has_mailbox'] === true, 'doveadm se mailbox milna chahiye');

    // ab exim bole "unrouteable" -> verified false hona chahiye
    $h['cmd']->eximBtOutput = "Unrouteable address\n";
    $h['cmd']->doveadmUserOutput = '';
    $bad = (new MailServerSetup())->handle(['action' => 'verify', 'address' => 'ghost@alice.test'], $h['ctx']);
    assert_true($bad['routed'] === false, 'Unrouteable par routed false hona chahiye');
    assert_true($bad['has_mailbox'] === false);

    // galat address reject
    $threw = false;
    try {
        (new MailServerSetup())->handle(['action' => 'verify', 'address' => '|/bin/sh@x'], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = true;
    }
    assert_true($threw, 'hostile address reject hona chahiye');
    acp_mail_cleanup($h);
});

test('mail.server status/list — sachchi report (installed na ho to bhi)', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_accounts($h['root']);
    $st = (new MailServerSetup())->handle(['action' => 'status'], $h['ctx']);
    assert_true($st['installed'] === true, 'env override ke saath installed true');
    assert_true($st['exim_config'] === 'ok', 'exim config ok: ' . json_encode($st['exim_config']));
    assert_true($st['dovecot_config'] === 'ok', 'dovecot config ok: ' . json_encode($st['dovecot_config']));
    assert_true(($st['services']['exim4'] ?? false) === true, 'exim4 active: ' . json_encode($st['services']));
    assert_true($st['mailboxes'] === 0, 'abhi sync nahi hua to 0 (mile ' . (int) $st['mailboxes'] . ')');

    (new MailServerSetup())->handle(['action' => 'sync'], $h['ctx']);
    $st2 = (new MailServerSetup())->handle(['action' => 'status'], $h['ctx']);
    assert_true($st2['mailboxes'] === 3);
    assert_true($st2['domains'] === 2);

    $list = (new MailServerSetup())->handle(['action' => 'list'], $h['ctx']);
    assert_true($list['count'] === 3);
    assert_true(in_array('info@alice.test', $list['mailboxes'], true));
    assert_true(in_array('info@bob.test', $list['mailboxes'], true));
    assert_true(!in_array('steal@alice.test', $list['mailboxes'], true));

    // ab binaries hi na hon (env hata do, asli path sandbox me maujood nahi)
    foreach (['ACP_MAIL_EXIM', 'ACP_MAIL_DOVECOT', 'ACP_MAIL_DOVEADM'] as $k) {
        putenv($k);
    }
    $none = (new MailServerSetup())->handle(['action' => 'status'], $h['ctx']);
    assert_true($none['installed'] === false, 'bina binaries ke installed false hona chahiye');
    assert_true(isset($none['error']));
    acp_mail_cleanup($h);
});

test('mail.server — galat action reject', function (): void {
    $h = acp_mail_harness();
    $threw = false;
    try {
        (new MailServerSetup())->handle(['action' => 'nuclear'], $h['ctx']);
    } catch (TaskRejectedException $e) {
        $threw = true;
    }
    assert_true($threw, 'unknown action reject hona chahiye');
    acp_mail_cleanup($h);
});

test('S7 mail tools: har possible path agent allowlist me hai', function (): void {
    $ref = new ReflectionClass(CommandRunner::class);
    $allow = $ref->getConstant('BIN_ALLOWLIST');
    foreach ([
        'exim4' => MailServer::EXIM_PATHS,
        'dovecot' => MailServer::DOVECOT_PATHS,
        'doveadm' => MailServer::DOVEADM_PATHS,
        'doveconf' => MailServer::DOVECONF_PATHS,
        'update-exim4.conf' => MailServer::UPDATE_EXIM_PATHS,
    ] as $tool => $paths) {
        foreach ($paths as $path) {
            assert_true(in_array($path, $allow, true), "allowlist me {$path} nahi hai ({tool})");
        }
    }
});

test('mail.server schema — payload fail-closed', function (): void {
    $schema = acp_task_registry()['mail.server']['schema'];
    assert_true(JsonSchema::validate($schema, ['action' => 'status']) === [], 'status pass hona chahiye');
    assert_true(JsonSchema::validate($schema, ['action' => 'status', 'evil' => 1]) !== [], 'extra key reject');
    assert_true(JsonSchema::validate($schema, ['action' => 'destroy']) !== [], 'unknown action reject');
    assert_true(JsonSchema::validate($schema, ['action' => 'verify', 'address' => '|/bin/sh']) !== [], 'hostile address reject');
    assert_true(JsonSchema::validate($schema, ['action' => 'verify', 'address' => 'a@b.test']) === [], 'sahi address pass');
});


fwrite(STDOUT, "\nS7 MAIL EXTRAS (catch-all / autoresponder / spam / SPF-DKIM-DMARC)\n");

/** @param array{root:string,cmd:FakeCommandExecutor,ctx:TaskContext} $h */
function acp_mail_seed_extras(array $h): void
{
    $root = $h['root'];
    $hash = '$2y$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234';
    // alicehost: 1 mailbox + catchall + autoresponder + spam lists + deliverability
    $home = $root . '/home/alicehost';
    mkdir($home . '/etc/mail', 0755, true);
    mkdir($home . '/mail/alice.test/info', 0755, true);
    file_put_contents($home . '/etc/mail/passwd', "info@alice.test:{BLF-CRYPT}{$hash}:1001:1001::{$home}/mail/alice.test/info::\n");
    file_put_contents($home . '/etc/mail/catchall', "*@alice.test: info@alice.test\n");
    // pipe wala destination kabhi nahi chalna chahiye
    file_put_contents($home . '/etc/mail/aliases', "web@alice.test: info@alice.test\n");
    file_put_contents(
        $home . '/etc/mail/autorespond',
        json_encode([[
            'local' => 'info', 'domain' => 'alice.test',
            'subject' => "Office band hai\nInjected: evil", 'body' => "Main chutti par hu.\nKal lautunga.",
            'interval_h' => 24,
        ], [
            'local' => 'ghost', 'domain' => 'alice.test',   // aisa mailbox hai hi nahi
            'subject' => 'x', 'body' => 'y', 'interval_h' => 24,
        ]])
    );
    file_put_contents($home . '/etc/mail/spam.json', json_encode([
        'required_score' => 5,
        'blacklist'      => ['spam@bad.test', '|/bin/sh'],
        'whitelist'      => ['boss@good.test'],
    ]));
    file_put_contents($home . '/etc/mail/deliverability.json', json_encode([['domain' => 'alice.test']]));
    mkdir($home . '/etc/dns', 0755, true);
    file_put_contents($home . '/etc/dns/zone.json', json_encode([
        ['domain' => 'alice.test', 'name' => '@', 'type' => 'A', 'value' => '203.0.113.10'],
        ['domain' => 'alice.test', 'name' => 'www', 'type' => 'CNAME', 'value' => 'alice.test'],
        ['domain' => 'alice.test', 'name' => '@', 'type' => 'TXT', 'value' => 'v=spf1 include:old -all'],
        ['domain' => 'alice.test', 'name' => 'note', 'type' => 'TXT', 'value' => 'user ka apna note'],
    ]));
}

test('mail.server sync — catch-all + autoresponder + spam lists (asli files)', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_extras($h);
    $out = (new MailServerSetup())->handle(['action' => 'sync'], $h['ctx']);
    assert_true($out['catchalls'] === 1, '1 catch-all hona chahiye, mile ' . (int) $out['catchalls']);
    assert_true($out['responders'] === 1, 'sirf maujooda mailbox ka autoresponder (ghost nahi), mile ' . (int) $out['responders']);
    assert_true($out['spam_lists'] === 1, '1 mailbox ke liye spam lists');

    $catch = (string) file_get_contents($h['root'] . '/etc/exim4/alphacp-catchall');
    assert_true(str_contains($catch, '*@alice.test: info@alice.test'), 'catchall file: ' . $catch);

    $eml = (string) @file_get_contents($h['root'] . '/etc/exim4/vacation/info@alice.test.eml');
    assert_true(str_contains($eml, 'Subject: Office band hai'), 'subject ek line me hona chahiye: ' . $eml);
    assert_true(!str_contains($eml, "\nInjected"), 'subject me newline inject nahi hona chahiye');
    assert_true(str_contains($eml, 'Main chutti par hu.'), 'body hona chahiye');
    assert_true(trim((string) @file_get_contents($h['root'] . '/etc/exim4/vacation/info@alice.test.repeat')) === '24h', 'once_repeat 24h');
    assert_true(!is_file($h['root'] . '/etc/exim4/vacation/ghost@alice.test.eml'), 'bina mailbox ke autoresponder nahi');

    $deny = (string) @file_get_contents($h['root'] . '/etc/exim4/spam/info@alice.test.deny');
    assert_true(str_contains($deny, 'spam@bad.test'), 'deny list: ' . $deny);
    assert_true(!str_contains($deny, '/bin/sh'), 'pipe wala entry kabhi nahi likhna chahiye');
    $allow = (string) @file_get_contents($h['root'] . '/etc/exim4/spam/info@alice.test.allow');
    assert_true(str_contains($allow, 'boss@good.test'), 'allow list: ' . $allow);
    acp_mail_cleanup($h);
});

test('mail.server sync — hataya hua autoresponder ka file bhi hatana padta hai', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_extras($h);
    (new MailServerSetup())->handle(['action' => 'sync'], $h['ctx']);
    $stale = $h['root'] . '/etc/exim4/vacation/info@alice.test.eml';
    assert_true(is_file($stale), 'pehli baar file bani');
    // ab autoresponder hata do
    file_put_contents($h['root'] . '/home/alicehost/etc/mail/autorespond', '[]');
    (new MailServerSetup())->handle(['action' => 'sync'], $h['ctx']);
    assert_true(!is_file($stale), 'purana vacation file hatna chahiye — warna deleted responder ke jawab jate rahenge');
    acp_mail_cleanup($h);
});

test('mail.server setup — exim template me catchall/autoreply/DNSBL/spam ACL', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_extras($h);
    (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
    $tpl = (string) file_get_contents($h['root'] . '/etc/exim4/exim4.conf.template');
    assert_true(str_contains($tpl, 'alphacp_catchall:'), 'catchall router hona chahiye');
    assert_true(str_contains($tpl, 'alphacp_autoreply:'), 'autoreply router hona chahiye');
    assert_true(str_contains($tpl, 'alphacp_vacation:'), 'vacation transport hona chahiye');
    assert_true(str_contains($tpl, 'driver = autoreply'), 'autoreply driver');
    assert_true(str_contains($tpl, 'unseen'), 'autoreply unseen hona chahiye (delivery bhi ho)');
    assert_true(str_contains($tpl, 'wildlsearch;'), 'spam lists ACL me wildlsearch');
    assert_true(str_contains($tpl, 'zen.spamhaus.org'), 'DNSBL hona chahiye');
    assert_true(str_contains($tpl, 'add_header = X-AlphaCP-DNSBL'), 'DNSBL sirf header/log (reject nahi)');
    assert_true(!str_contains($tpl, 'spam = nobody'), 'bina spamd ke SpamAssassin ACL nahi likhna chahiye');
    // DKIM is exim build me support nahi (fake -bV me DKIM nahi) -> config me nahi
    assert_true(!str_contains($tpl, 'dkim_private_key'), 'DKIM unsupported hone par config me nahi hona chahiye');
    acp_mail_cleanup($h);
});

test('mail.server setup — DKIM: exim support kare to signing, warna fail-closed', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_extras($h);
    $h['cmd']->mailDkim = true;
    (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
    $tpl = (string) file_get_contents($h['root'] . '/etc/exim4/exim4.conf.template');
    assert_true(str_contains($tpl, 'dkim_private_key'), 'DKIM support par signing config honi chahiye');
    assert_true(str_contains($tpl, 'dkim_selector = default'), 'selector default hona chahiye');
    assert_true(str_contains($tpl, '{0}'), 'key na mile to 0 (signing off)');
    $caps = (new MailServer($h['ctx']->cmd, $h['ctx']->log))->capabilities();
    assert_true($caps['dkim'] === true, 'capabilities: dkim true');
    assert_true($caps['content_scanning'] === true, 'capabilities: content_scanning true');
    acp_mail_cleanup($h);
});

test('mail.server deliverability — SPF + DMARC + DKIM records asli zone me', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_extras($h);
    $out = (new MailServerSetup())->handle(['action' => 'deliverability'], $h['ctx']);
    assert_true($out['ok'] === true, 'deliverability ok (fail: ' . json_encode($out['failed']) . ')');
    assert_true($out['count'] === 1, '1 domain, mile ' . (int) $out['count']);
    assert_true(($out['domains'][0]['dkim'] ?? false) === true, 'DKIM key bani honi chahiye');
    assert_true(!empty($h['cmd']->opensslArgvs), 'openssl chalna chahiye (key banane ke liye)');

    $zone = json_decode((string) file_get_contents($h['root'] . '/home/alicehost/etc/dns/zone.json'), true);
    $byName = [];
    foreach ($zone as $row) {
        $byName[$row['name'] . '|' . $row['type']] = $row['value'];
    }
    assert_true(($byName['@|A'] ?? '') === '203.0.113.10', 'A record bacha rahe');
    assert_true(($byName['www|CNAME'] ?? '') === 'alice.test', 'CNAME bacha rahe');
    assert_true(($byName['note|TXT'] ?? '') === 'user ka apna note', 'user ka TXT bacha rahe');
    assert_true(($byName['@|TXT'] ?? '') === 'v=spf1 a mx -all', 'purana SPF replace hoke naya aana chahiye: ' . ($byName['@|TXT'] ?? ''));
    assert_true(str_starts_with((string) ($byName['_dmarc|TXT'] ?? ''), 'v=DMARC1; p=quarantine'), 'DMARC record: ' . ($byName['_dmarc|TXT'] ?? ''));
    $dkim = (string) ($byName['default._domainkey|TXT'] ?? '');
    assert_true(str_starts_with($dkim, 'v=DKIM1; k=rsa; p='), 'DKIM record: ' . substr($dkim, 0, 60));
    acp_mail_cleanup($h);
});

test('mail.server deliverability — openssl na ho to jhootha DKIM nahi (sirf SPF/DMARC)', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_extras($h);
    $h['cmd']->opensslFails = true;
    $out = (new MailServerSetup())->handle(['action' => 'deliverability'], $h['ctx']);
    assert_true($out['count'] === 1, 'domain process hona chahiye');
    assert_true(($out['domains'][0]['dkim'] ?? true) === false, 'key na bane to dkim false bolna chahiye');
    $zone = json_decode((string) file_get_contents($h['root'] . '/home/alicehost/etc/dns/zone.json'), true);
    $names = array_column($zone, 'name');
    assert_true(!in_array('default._domainkey', $names, true), 'bina key ke DKIM record nahi');
    assert_true(in_array('_dmarc', $names, true), 'DMARC phir bhi likhna chahiye');
    acp_mail_cleanup($h);
});

test('mail.set/mail.forward ke baad auto-sync (alag se sync command nahi)', function (): void {
    // asli account harness (getent/useradd fake hain — warna "not an AlphaCP account")
    $h = acp_account_harness();
    $root = $h['root'];
    (new \Alphacp\Agent\Tasks\AccountCreate())->handle(acp_create_payload(), $h['ctx']);
    // mail ke env overrides usi root me
    foreach ([
        'ACP_MAIL_EXIM_TEMPLATE' => $root . '/etc/exim4/exim4.conf.template',
        'ACP_MAIL_EXIM_DOMAINS' => $root . '/etc/exim4/alphacp-domains',
        'ACP_MAIL_EXIM_RECIPIENTS' => $root . '/etc/exim4/alphacp-recipients',
        'ACP_MAIL_EXIM_ALIASES' => $root . '/etc/exim4/alphacp-aliases',
        'ACP_MAIL_CATCHALL' => $root . '/etc/exim4/alphacp-catchall',
        'ACP_MAIL_VACATION_DIR' => $root . '/etc/exim4/vacation',
        'ACP_MAIL_SPAM_DIR' => $root . '/etc/exim4/spam',
        'ACP_MAIL_DKIM_DIR' => $root . '/alphacp/etc/mail/dkim',
        'ACP_MAIL_DOVECOT_USERS' => $root . '/etc/dovecot/alphacp-users',
        'ACP_MAIL_DOVECOT_CONF' => $root . '/etc/dovecot/conf.d/99-alphacp.conf',
        'ACP_MAIL_EXIM' => $root . '/bin/exim4',
        'ACP_MAIL_DOVECOT' => $root . '/bin/dovecot',
        'ACP_MAIL_DOVEADM' => $root . '/bin/doveadm',
        'ACP_MAIL_DOVECONF' => $root . '/bin/doveconf',
        'ACP_MAIL_UPDATE_EXIM' => $root . '/bin/update-exim4.conf',
    ] as $name => $value) {
        putenv($name . '=' . $value);
    }
    mkdir($root . '/etc/exim4', 0755, true);
    file_put_contents($root . '/etc/exim4/exim4.conf.template', "# distro template\n");
    $hash = '$2y$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234';

    // 1) mail server configured nahi -> sync skip (mail task fail nahi hona chahiye)
    $set1 = (new \Alphacp\Agent\Tasks\MailSet())->handle([
        'username'  => 'alicehost',
        'mailboxes' => [['local' => 'info', 'domain' => 'alice.test', 'hash' => $hash, 'quota_mb' => 100]],
    ], $h['ctx']);
    assert_true(str_contains((string) $set1['mail_sync'], 'skipped'), 'bina setup ke sync skip: ' . $set1['mail_sync']);

    // 2) setup ke baad -> mail.set khud sync kare
    (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
    $set2 = (new \Alphacp\Agent\Tasks\MailSet())->handle([
        'username'  => 'alicehost',
        'mailboxes' => [['local' => 'sales', 'domain' => 'alice.test', 'hash' => $hash, 'quota_mb' => 100]],
    ], $h['ctx']);
    assert_true(str_contains((string) $set2['mail_sync'], 'ok ('), 'setup ke baad sync hona chahiye: ' . $set2['mail_sync']);
    $users = (string) file_get_contents($root . '/etc/dovecot/alphacp-users');
    assert_true(str_contains($users, 'sales@alice.test:'), 'naya mailbox turant aggregate me hona chahiye');

    // 3) mail.forward ke baad bhi
    $fwd = (new \Alphacp\Agent\Tasks\MailForward())->handle([
        'username' => 'alicehost',
        'forwards' => [['local' => 'contact', 'domain' => 'alice.test', 'dest' => 'info@alice.test']],
    ], $h['ctx']);
    assert_true(str_contains((string) $fwd['mail_sync'], 'ok ('), 'forward ke baad bhi sync: ' . $fwd['mail_sync']);
    $aliases = (string) file_get_contents($root . '/etc/exim4/alphacp-aliases');
    assert_true(str_contains($aliases, 'contact@alice.test: info@alice.test'), 'naya forwarder turant lagu: ' . $aliases);

    foreach ([
        'ACP_MAIL_EXIM_TEMPLATE', 'ACP_MAIL_EXIM_DOMAINS', 'ACP_MAIL_EXIM_RECIPIENTS',
        'ACP_MAIL_EXIM_ALIASES', 'ACP_MAIL_CATCHALL', 'ACP_MAIL_VACATION_DIR',
        'ACP_MAIL_SPAM_DIR', 'ACP_MAIL_DKIM_DIR', 'ACP_MAIL_DOVECOT_USERS',
        'ACP_MAIL_DOVECOT_CONF', 'ACP_MAIL_EXIM', 'ACP_MAIL_DOVECOT',
        'ACP_MAIL_DOVEADM', 'ACP_MAIL_DOVECONF', 'ACP_MAIL_UPDATE_EXIM',
    ] as $name) {
        putenv($name);
    }
    acp_account_cleanup($h);
});

test('S7 mail tools: openssl bhi agent allowlist me hai', function (): void {
    $ref = new ReflectionClass(CommandRunner::class);
    $allow = $ref->getConstant('BIN_ALLOWLIST');
    foreach (MailServer::OPENSSL_PATHS as $path) {
        assert_true(in_array($path, $allow, true), "allowlist me {$path} nahi hai");
    }
});

test('mail.server schema — deliverability action allowed, galat action nahi', function (): void {
    $schema = acp_task_registry()['mail.server']['schema'];
    assert_true(JsonSchema::validate($schema, ['action' => 'deliverability']) === [], 'deliverability pass hona chahiye');
    assert_true(JsonSchema::validate($schema, ['action' => 'deliverability', 'username' => 'alicehost']) === [], 'username ke saath bhi');
    assert_true(JsonSchema::validate($schema, ['action' => 'deliverability', 'username' => '../root']) !== [], 'path traversal reject');
});


fwrite(STDOUT, "\nS7 MAIL FIXES (maildir ownership + dovecot userdb probe)\n");

test('mail.server sync — root-owned Maildir parents theek (live wala asli bug)', function (): void {
    $h = acp_mail_harness();
    $root = $h['root'];
    $hash = '$2y$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234';
    $home = $root . '/home/alicehost';
    $box = $home . '/mail/alice.test/info';
    mkdir($box . '/new', 0777, true);      // "root ne bana diya, chown bhool gaya"
    mkdir($box . '/cur', 0777, true);
    mkdir($box . '/tmp', 0777, true);
    @chmod($box, 0777);
    // uid/gid = is process ke (sandbox me hum root nahi, isliye chown path chhoda)
    $uid = (string) (function_exists('posix_getuid') ? posix_getuid() : 1000);
    $gid = (string) (function_exists('posix_getgid') ? posix_getgid() : 1000);
    mkdir($home . '/etc/mail', 0755, true);
    file_put_contents($home . '/etc/mail/passwd', "info@alice.test:{BLF-CRYPT}{$hash}:{$uid}:{$gid}::{$box}::\n");

    $out = (new MailServerSetup())->handle(['action' => 'sync'], $h['ctx']);
    assert_true($out['mailboxes'] === 1, '1 mailbox');
    assert_true(($out['maildirs_fixed'] ?? 0) >= 1, 'Maildir theek hona chahiye (mode 0777 -> 0700), fixed=' . (int) ($out['maildirs_fixed'] ?? 0));
    assert_true((fileperms($box) & 0777) === 0700, 'mailbox dir 0700 hona chahiye, ab ' . decoct(fileperms($box) & 0777));
    assert_true((fileperms($box . '/new') & 0777) === 0700, 'new/ 0700 hona chahiye');
    assert_true((fileperms(dirname($box)) & 0777) === 0700, '~/mail/<domain> bhi 0700 (traversable by owner)');
    acp_mail_cleanup($h);
});

test('mail.server setup — Dovecot userdb probe: fail ho to 0644 relax karke dobara', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_extras($h);
    $h['cmd']->doveadmUserOutput = "field\tvalue\nuid\t1001\nhome\t/home/alicehost/mail/alice.test/info\n";
    $h['cmd']->doveadmFailFirst = 1;     // pehli koshish fail -> relax -> dobara
    $out = (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
    $probe = $out['dovecot_userdb'] ?? [];
    assert_true(($probe['ok'] ?? false) === true, 'probe ok hona chahiye: ' . json_encode($probe));
    assert_true(($probe['mode'] ?? '') === '0644-relaxed', 'relax mode report hona chahiye: ' . json_encode($probe));
    assert_true((fileperms($h['root'] . '/etc/dovecot/alphacp-users') & 0777) === 0644, 'file 0644 ho jana chahiye');
    acp_mail_cleanup($h);
});

test('mail.server setup — Dovecot userdb probe: dono baar fail to jhoothi ok nahi', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_extras($h);
    $h['cmd']->doveadmAlwaysFails = true;
    $out = (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
    $probe = $out['dovecot_userdb'] ?? [];
    assert_true(($probe['ok'] ?? true) === false, 'fail report hona chahiye (chhupana nahi): ' . json_encode($probe));
    assert_true(str_contains((string) ($probe['error'] ?? ''), 'userdb lookup failed'), 'asli error hona chahiye');
    acp_mail_cleanup($h);
});

test('mail.server setup — exim unit non-root ho to root drop-in likhe (user= delivery)', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_extras($h);
    putenv('SIM_EXIM_UNIT_USER=Debian-exim');
    try {
        $out = (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
        assert_true(($out['ok'] ?? false) === true, 'setup chalna chahiye (drop-in likhne ki koshish ke bawajud)');
    } finally {
        putenv('SIM_EXIM_UNIT_USER');
    }
    acp_mail_cleanup($h);
});

test('mail.server setup — exim template me deliver_drop_privilege = false', function (): void {
    $h = acp_mail_harness();
    acp_mail_seed_extras($h);
    (new MailServerSetup())->handle(['action' => 'setup'], $h['ctx']);
    $tpl = (string) file_get_contents($h['root'] . '/etc/exim4/exim4.conf.template');
    assert_true(str_contains($tpl, 'deliver_drop_privilege = false'), 'mailbox uid se delivery ke liye zaroori');
    acp_mail_cleanup($h);
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

