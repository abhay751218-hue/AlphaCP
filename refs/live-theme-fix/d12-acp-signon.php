<?php
/**
 * AlphaCP phpMyAdmin SSO shim (D12) — panel ke one-time token se signon
 * session banata hai. Token panel-internal endpoint par verify hota hai
 * (shared secret, sirf 127.0.0.1) — wahi pattern jo webmail SSO me hai.
 */
declare(strict_types=1);

$token = (string) ($_GET['token'] ?? '');

if ($token === '' || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>AlphaCP phpMyAdmin</title>'
        . '<body style="font-family:system-ui;background:#f4f6f9;display:grid;place-items:center;height:100vh;margin:0">'
        . '<div style="background:#fff;padding:32px 40px;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08);text-align:center">'
        . '<h2 style="color:#FF6C2C;margin:0 0 8px">AlphaCP phpMyAdmin</h2>'
        . '<p style="color:#444">Yahan direct login nahi hota — apne <strong>cPanel (port 2083)</strong> me '
        . '<strong>Databases &rarr; phpMyAdmin &rarr; Open phpMyAdmin</strong> dabao.</p></div>';
    exit;
}

$secret = trim((string) (@file_get_contents('/usr/local/alphacp/etc/pma-sso.secret') ?: ''));
$urlFile = '/usr/local/alphacp/etc/pma-sso-internal.url';
$url = trim((string) (@file_get_contents($urlFile) ?: ''));
if ($url === '') {
    $url = 'https://127.0.0.1:2083/internal/pma-sso';
}

$ch = curl_init($url . '?token=' . rawurlencode($token));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['X-ACP-Secret: ' . $secret],
    CURLOPT_SSL_VERIFYPEER => false, // self-signed panel cert, loopback only
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_TIMEOUT        => 10,
]);
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

$data = $code === 200 ? json_decode((string) $body, true) : null;
if (! is_array($data) || ($data['user'] ?? '') === '' || ! array_key_exists('password', $data)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>SSO expired</title>'
        . '<body style="font-family:system-ui;background:#f4f6f9;display:grid;place-items:center;height:100vh;margin:0">'
        . '<div style="background:#fff;padding:32px 40px;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.08);text-align:center">'
        . '<h2 style="color:#dc2626;margin:0 0 8px">SSO token invalid/expired</h2>'
        . '<p style="color:#444">Panel me wapas ja kar <strong>Open phpMyAdmin</strong> dobara dabao (token 10 minute + one-time hota hai).</p></div>';
    exit;
}

session_name('AcpPmaSignon');
session_start();
$_SESSION['PMA_single_signon_user']     = (string) $data['user'];
$_SESSION['PMA_single_signon_password'] = (string) $data['password'];
$_SESSION['PMA_single_signon_host']     = 'localhost';
session_write_close();

header('Location: /index.php');
exit;
