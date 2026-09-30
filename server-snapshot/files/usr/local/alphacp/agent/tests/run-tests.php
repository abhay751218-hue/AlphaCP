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
use Alphacp\Agent\AccountPaths;
use Alphacp\Agent\CommandRunner;
use Alphacp\Agent\JsonSchema;
use Alphacp\Agent\PathGuard;
use Alphacp\Agent\PathGuardException;
use Alphacp\Agent\SafeFs;
use Alphacp\Agent\TaskLogger;
use Alphacp\Agent\TaskRejectedException;
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
    foreach (['account.create', 'account.suspend', 'account.unsuspend', 'account.terminate', 'account.setQuota', 'domain.add', 'domain.remove', 'php.setVersion', 'php.setIni', 'errorpages.set', 'indexes.set', 'mime.set', 'handlers.set', 'files.list', 'files.usage', 'files.set', 'privacy.set', 'ssh.set', 'mail.set', 'mail.forward', 'mail.autorespond', 'mail.catchall', 'mail.filter', 'mail.deliverability', 'mail.spam', 'mail.list', 'mail.routing', 'mail.track', 'mail.gfilter', 'cron.set', 'ssl.issue', 'ssl.remove'] as $type) {
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
