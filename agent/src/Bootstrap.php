<?php
declare(strict_types=1);

/**
 * AlphaCP paneld — bootstrap: version, paths, PSR-4-ish autoloader.
 *
 * Design rule: a root daemon must have ZERO third-party dependencies.
 * No Composer, no vendor/, only the PHP standard library.
 */

define('ACP_AGENT_VERSION', '0.4.0');
define('ACP_AGENT_ROOT', dirname(__DIR__));                       // .../agent
define('ACP_HOME', rtrim(getenv('ACP_HOME') ?: '/usr/local/alphacp', '/'));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Alphacp\\Agent\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel  = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = ACP_AGENT_ROOT . '/src/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/** Load the task registry (allowlist). */
function acp_task_registry(): array
{
    static $registry = null;
    if ($registry === null) {
        $registry = require ACP_AGENT_ROOT . '/config/tasks.php';
    }
    return $registry;
}
