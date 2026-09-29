<?php
// AlphaCP installer self-test endpoint (token protected; see /usr/local/alphacp/etc/check.token)
$token_file = '/usr/local/alphacp/etc/check.token';
$expected = is_readable($token_file) ? trim((string)file_get_contents($token_file)) : '';
if ($expected === '' || !hash_equals($expected, (string)($_GET['token'] ?? ''))) {
    http_response_code(404); exit;
}
header('Content-Type: application/json');
echo json_encode([
    'ok' => true,
    'php' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
    'extensions_ok' => [
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'mbstring'  => extension_loaded('mbstring'),
        'gd'        => extension_loaded('gd'),
        'zip'       => extension_loaded('zip'),
        'curl'      => extension_loaded('curl'),
        'redis'     => extension_loaded('redis'),
        'opcache'   => extension_loaded('Zend OPcache'),
    ],
], JSON_PRETTY_PRINT);
