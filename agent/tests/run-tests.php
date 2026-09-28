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

use Alphacp\Agent\CommandRunner;
use Alphacp\Agent\JsonSchema;
use Alphacp\Agent\PathGuard;
use Alphacp\Agent\PathGuardException;
use Alphacp\Agent\TaskRejectedException;

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
    $r = (new CommandRunner(5))->run(['/bin/hostname']);
    assert_true($r->ok() || $r->exitCode >= 0, 'hostname should execute');
    assert_true($r->stdoutTrimmed() !== '', 'hostname should print something');
});
test('passes arguments as argv (no shell interpretation)', function (): void {
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

fwrite(STDOUT, "\n" . str_repeat('-', 50) . "\n");
fwrite(STDOUT, sprintf("passed: %d   failed: %d\n", $passed, $failed));
exit($failed === 0 ? 0 : 1);
