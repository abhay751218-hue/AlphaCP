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
use Alphacp\Agent\Tasks\PhpSetVersion;
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
    foreach (['account.create', 'account.suspend', 'account.unsuspend', 'account.terminate', 'account.setQuota', 'domain.add', 'domain.remove', 'php.setVersion', 'cron.set'] as $type) {
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
