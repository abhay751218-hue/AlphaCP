<?php

/**
 * AlphaCP acp_sso — Roundcube plugin: panel se one-time token ke saath aaye
 * user ko Dovecot MASTER-user se seamless login (cPanel-style webmail SSO).
 *
 * Flow: panel POST /webmail/open → token → redirect https://host:2096/?_acp_token=…
 *   → plugin token session me rakhta hai → 'authenticate' hook par panel ke
 *     internal endpoint se mailbox verify (shared secret) → IMAP login
 *     "<mailbox>*<masteruser>" + master password (etc/webmail-master.plain).
 *
 * Security: token one-time + 10 min; internal endpoint 127.0.0.1-only (nginx);
 * secret/master files root:www-data 0640; master passdb dovecot me alag hai
 * (normal user passwords isse affect nahi hote).
 */
class acp_sso extends rcube_plugin
{
    /** @var string */
    public $task = '';

    public function init(): void
    {
        $this->load_config();
        $this->add_hook('startup', [$this, 'capture']);
        $this->add_hook('authenticate', [$this, 'authenticate']);
    }

    /** ?_acp_token=… ko session me capture karo (sirf valid 64-hex). */
    public function capture(array $args): array
    {
        $tok = (string) ($_GET['_acp_token'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/', $tok) === 1) {
            $_SESSION['acp_sso_token'] = $tok;
        }

        return $args;
    }

    /** Token ho to panel se mailbox poochho aur master-user creds do. */
    public function authenticate(array $args): array
    {
        $tok = (string) ($_SESSION['acp_sso_token'] ?? '');
        if ($tok === '') {
            return $args;
        }
        unset($_SESSION['acp_sso_token']);

        $url    = (string) $this->get_config('acp_sso_internal_url', '');
        $secret = trim((string) @file_get_contents((string) $this->get_config('acp_sso_secret_file', '')));
        $master = trim((string) @file_get_contents((string) $this->get_config('acp_sso_master_plain', '')));
        $muser  = (string) $this->get_config('acp_sso_master_user', 'acpmaster');
        if ($url === '' || $secret === '' || $master === '') {
            return $args;
        }

        $ch = curl_init($url . '?token=' . urlencode($tok));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['X-ACP-Secret: ' . $secret],
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => false,   // self-signed panel cert (loopback call)
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code !== 200 || !is_string($body)) {
            return $args;
        }
        $j  = json_decode($body, true);
        $mb = is_array($j) ? (string) ($j['mailbox'] ?? '') : '';
        if ($mb === '') {
            return $args;
        }

        $args['user'] = $mb . '*' . $muser;
        $args['pass'] = $master;

        return $args;
    }
}
