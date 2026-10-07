#!/usr/bin/env python3
"""Sandbox-only workaround: php-wasm crashes on Mockery's console-output mock.

Adds `public $mockConsoleOutput = false;` to a TEMP copy of tests/TestCase.php.
Never run this against the repository or a deployed panel — it weakens assertions
on `artisan` command tests. Kept as a real file (not an inline heredoc) so the
`$` cannot be mangled by shell escaping.
"""
import sys

path = sys.argv[1]
src = open(path, encoding="utf-8").read()
marker = "public $mockConsoleOutput"
if marker not in src:
    needle = "abstract class TestCase extends BaseTestCase\n{"
    if needle not in src:
        sys.exit("TestCase.php shape changed - update _patch-testcase-sandbox.py")
    src = src.replace(needle, needle + "\n    " + marker + " = false; // SANDBOX ONLY\n", 1)
    open(path, "w", encoding="utf-8").write(src)
print("sandbox patch applied:", path)
