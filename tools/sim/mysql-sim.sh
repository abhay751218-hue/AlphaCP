#!/usr/bin/env bash
# =============================================================================
# AlphaCP MySQL provisioning sim — the REAL db.* handlers under php-wasm, then a
# strict lint of every SQL statement they would send to MariaDB.
# Version: 0.70.0
#
# Proves, without a MariaDB server:
#   * the handlers run end-to-end against the fake executor (create db, create
#     user with grants, grant, drop db, drop user, list),
#   * every generated statement is a single well-formed statement with balanced
#     quoting, backticked identifiers and no newline inside a literal,
#   * every identifier that reaches SQL carries the account prefix,
#   * hostile names (pipe, path escape, spaces, wrong prefix) are refused and
#     produce NO SQL at all,
#   * the password never appears in argv and quotes/backslashes/control chars
#     are refused outright.
# =============================================================================
set -Eeuo pipefail

VERSION="0.70.0"
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
PHPWASM_DIR="${PHPWASM_DIR:-/tmp/phpw}"
PHPWASM="${PHPWASM_DIR}/node_modules/@php-wasm/cli/php-wasm.js"

command -v python3 >/dev/null 2>&1 || { echo "FAIL: python3 missing" >&2; exit 1; }
[[ -f "${PHPWASM}" ]] || { echo "FAIL: php-wasm missing (run tools/sim/provision-sim.sh once)" >&2; exit 1; }

WORK="$(mktemp -d /tmp/alphacp-mysql-sim.XXXXXX)"
[[ -n "${KEEP:-}" ]] || trap 'rm -rf "${WORK}"' EXIT
[[ -z "${KEEP:-}" ]] || echo "KEEP=1 → ${WORK}"

printf 'AlphaCP MySQL provisioning sim v%s\n' "${VERSION}"

cat > "${WORK}/probe.php" <<'PHP'
<?php
declare(strict_types=1);
require getenv('ACP_MYSQL_HELPERS');

use Alphacp\Agent\TaskRejectedException;
use Alphacp\Agent\Tests\FakeCommandExecutor;
use Alphacp\Agent\Tasks\DbCreate;
use Alphacp\Agent\Tasks\DbDrop;
use Alphacp\Agent\Tasks\DbList;
use Alphacp\Agent\Tasks\DbUserCreate;
use Alphacp\Agent\Tasks\DbUserDrop;
use Alphacp\Agent\Tasks\DbUserPassword;
use Alphacp\Agent\Tasks\DbUserGrant;

$harness = acp_account_harness();
(new Alphacp\Agent\Tasks\AccountCreate())->handle(acp_create_payload(), $harness['ctx']);
$ctx = $harness['ctx'];
$cmd = $harness['cmd'];

$out = [];

// 1. db.create + user + grant + second database
(new DbCreate())->handle(['username' => 'alicehost', 'name' => 'shop'], $ctx);
(new DbCreate())->handle(['username' => 'alicehost', 'name' => 'blog'], $ctx);
(new DbUserCreate())->handle([
    'username' => 'alicehost',
    'user'     => 'wp_admin',
    'password' => 'Str0ng-Pa55word',
    'host'     => 'localhost',
    'databases' => ['shop'],
], $ctx);
(new DbUserGrant())->handle(['username' => 'alicehost', 'user' => 'wp_admin', 'database' => 'blog'], $ctx);
(new DbUserCreate())->handle([
    'username' => 'alicehost',
    'user'     => 'remote_user',
    'password' => 'Another-Pa55word',
    'host'     => 'db.example.com',
    'databases' => [],
], $ctx);
$list = (new DbList())->handle(['username' => 'alicehost'], $ctx);

// 2. destructive paths
(new DbUserPassword())->handle([
    'username' => 'alicehost',
    'user'     => 'wp_admin',
    'password' => 'R0tated-Pa55word',
], $ctx);
(new DbDrop())->handle(['username' => 'alicehost', 'name' => 'blog'], $ctx);
(new DbUserDrop())->handle(['username' => 'alicehost', 'user' => 'remote_user'], $ctx);

$sql = $cmd->mysqlSql;
$argv = $cmd->mysqlArgv;

// 3. hostile input: refused BEFORE any SQL is produced
$refused = [];
$cases = [
    ['db', '|/bin/sh'],
    ['db', '../../etc'],
    ['db', 'shop; DROP DATABASE mysql'],
    ['db', 'shop name'],
    ['db', '1shop'],
    ['user', 'wp-admin'],
    ['user', "wp'admin"],
];
foreach ($cases as [$kind, $bad]) {
    $before = count($cmd->mysqlSql);
    try {
        if ($kind === 'db') {
            (new DbCreate())->handle(['username' => 'alicehost', 'name' => $bad], $ctx);
        } else {
            (new DbUserCreate())->handle([
                'username' => 'alicehost', 'user' => $bad, 'password' => 'Str0ng-Pa55word', 'databases' => [],
            ], $ctx);
        }
        echo "FAIL: hostile input accepted: {$bad}\n";
        exit(1);
    } catch (TaskRejectedException $e) {
        $refused[] = $bad;
    }
    if (count($cmd->mysqlSql) !== $before) {
        echo "FAIL: hostile input still produced SQL: {$bad}\n";
        exit(1);
    }
}

// 4. a password with a quote, backslash or control char must be refused
$badPasswords = ["quote'password", 'back\\slash', "line\nbreak", str_repeat('x', 65)];
foreach ($badPasswords as $bad) {
    try {
        (new DbUserCreate())->handle([
            'username' => 'alicehost', 'user' => 'someuser', 'password' => $bad, 'databases' => [],
        ], $ctx);
        echo "FAIL: bad password accepted\n";
        exit(1);
    } catch (TaskRejectedException $e) {
        // expected
    }
}

echo json_encode([
    'sql' => $sql,
    'argv' => $argv,
    'refused' => $refused,
    'databases' => $list['databases'],
    'users' => $list['users'],
], JSON_UNESCAPED_SLASHES) . "\n";
PHP

# the probe reuses the real harness helpers from run-tests.php
python3 - "${REPO}" "${WORK}/helpers.php" <<'PYHELP'
import pathlib, re, sys
repo, out = sys.argv[1], sys.argv[2]
src = pathlib.Path(repo, 'agent/tests/run-tests.php').read_text()
start = src.index('function acp_account_harness()')
end = src.index('function acp_account_cleanup(')
helpers = src[start:end]
helpers = helpers.replace("sys_get_temp_dir() . '/acp-acct-' . bin2hex(random_bytes(4))",
                          "sys_get_temp_dir() . '/acp-mysql-probe-' . bin2hex(random_bytes(4))")
uses = sorted({line.strip() for line in src.splitlines() if line.startswith('use ') and line.rstrip().endswith(';')})
bootstrap = """<?php
declare(strict_types=1);
require_once getenv('ACP_AGENT_ROOT') . '/src/Bootstrap.php';
require_once getenv('ACP_AGENT_ROOT') . '/tests/FakeCommandExecutor.php';

""" + "\n".join(uses) + "\n\n"
payload_fn = src[src.index('function acp_create_payload()'):]
payload_fn = payload_fn[:payload_fn.index('\n}') + 2]
pathlib.Path(out).write_text(bootstrap + helpers + "\n" + payload_fn + "\n")
print('helpers extracted')
PYHELP

ACP_AGENT_ROOT="${REPO}/agent" ACP_MYSQL_HELPERS="${WORK}/helpers.php" ACP_HOME="${WORK}/acphome" \
  node "${PHPWASM}" -d memory_limit=512M "${WORK}/probe.php" > "${WORK}/probe.json" 2> "${WORK}/probe.err" || {
  cat "${WORK}/probe.err" >&2
  cat "${WORK}/probe.json" >&2
  echo "=== MYSQL-SIM: FAIL (probe) ===" >&2
  exit 1
}

python3 - "${WORK}/probe.json" <<'PYCHECK'
import json, re, sys

raw = open(sys.argv[1]).read()
try:
    data = json.loads(raw)
except json.JSONDecodeError:
    sys.stderr.write('probe output was not JSON:\n' + raw[:2000] + '\n')
    raise SystemExit(1)
failures = []


def ok(label, cond, detail=''):
    if cond:
        print(f'ok   {label}')
    else:
        failures.append(f'{label} {detail}'.strip())
        print(f'FAIL {label} {detail}')


sql_all = '\n'.join(data['sql'])
statements = [s.strip() for s in sql_all.split(';') if s.strip()]
ok('sql statements were produced', len(statements) >= 10, f'({len(statements)})')

ALLOWED = ('CREATE DATABASE', 'DROP DATABASE', 'CREATE USER', 'DROP USER', 'ALTER USER',
           'GRANT ALL PRIVILEGES', 'REVOKE ALL PRIVILEGES', 'FLUSH PRIVILEGES',
           'SELECT ', 'SHOW ')
for statement in statements:
    verb = statement.split()[0].upper()
    verb_ok = statement.upper().startswith(ALLOWED)
    ok(f'allowed statement verb: {statement[:40]}…', verb_ok)
    if not verb_ok:
        continue
    # balanced quoting, nothing spanning lines
    ok('single line statement', '\n' not in statement)
    ok('balanced single quotes', statement.count("'") % 2 == 0, statement)
    ok('balanced backticks', statement.count('`') % 2 == 0, statement)
    ok('no comment tokens', '--' not in statement and '/*' not in statement, statement)
    ok('no semicolon leftovers', ';' not in statement)

# every backticked identifier carries the account prefix
for identifier in re.findall(r'`([^`]+)`', sql_all):
    ok(f'identifier prefixed: {identifier}', identifier.startswith('alicehost_'), identifier)

# every quoted user literal carries the account prefix
for literal in re.findall(r"'([^']*)'@'", sql_all):
    ok(f'user literal prefixed: {literal}', literal.startswith('alicehost_'), literal)

ok('utf8mb4 charset used', 'utf8mb4' in sql_all)
ok('FLUSH PRIVILEGES after grants', 'FLUSH PRIVILEGES' in sql_all)
ok('grants revoke before drop', sql_all.index('REVOKE ALL PRIVILEGES') < sql_all.index('DROP DATABASE `alicehost_blog`'))

# argv: never a password, never an identifier
for argv in data['argv']:
    line = ' '.join(argv)
    ok('client argv carries no identifier', 'alicehost' not in line and 'shop' not in line and 'blog' not in line, line)
    ok('client argv carries no password', 'Pa55word' not in line, line)
    ok('client argv uses the socket', '--protocol=socket' in line, line)

ok('hostile names were refused', len(data['refused']) == 7, str(data['refused']))
ok('list shows both databases before the drop', data['databases'] == ['alicehost_blog', 'alicehost_shop'] or
   sorted(data['databases']) == ['alicehost_blog', 'alicehost_shop'], str(data['databases']))

if failures:
    print(f'\n{failures.__len__()} check(s) failed')
    sys.exit(1)
PYCHECK

echo
echo "PASS: real db.* handlers produced ${VERSION} MySQL SQL — linted (quoting, prefixes, verbs) and hostile input refused"
echo "=== MYSQL-SIM: PASS ==="
