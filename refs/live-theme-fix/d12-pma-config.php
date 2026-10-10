<?php
/**
 * AlphaCP drop-in (D12): phpMyAdmin ko signon mode me chalao.
 * Session+creds /acp-signon.php shim banata hai (panel one-time token se).
 */
declare(strict_types=1);

$cfg['Servers'][1]['auth_type']       = 'signon';
$cfg['Servers'][1]['SignonSession']   = 'AcpPmaSignon';
$cfg['Servers'][1]['SignonURL']       = '/acp-signon.php';
$cfg['Servers'][1]['host']            = 'localhost';
$cfg['Servers'][1]['AllowNoPassword'] = false;

// blowfish secret — installer /etc/phpmyadmin/acp-blowfish.secret likhta hai
$acpBlowfish = trim((string) (@file_get_contents('/etc/phpmyadmin/acp-blowfish.secret') ?: ''));
if ($acpBlowfish !== '') {
    $cfg['blowfish_secret'] = $acpBlowfish;
}
