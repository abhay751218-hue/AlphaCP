<?php

declare(strict_types=1);

/**
 * AlphaCP panel configuration.
 *
 * Values come from the app .env (written by installer/step2b-install.sh).
 * Never hardcode paths or credentials in panel code — read them from here.
 */
return [
    // Panel + agent versions (shown in the UI footer and system page)
    'version'       => env('ACP_VERSION', '0.36.0'),
    'agent_version' => env('ACP_AGENT_VERSION', '0.32.0'),

    // AlphaCP install root (agent, etc/, logs/, panel/)
    'home'          => rtrim((string) env('ACP_HOME', '/usr/local/alphacp'), '/'),

    // This server's row in `servers`.
    //
    // Source of truth = etc/panel.env (written by the installer, readable by
    // the panel user). Dev machines fall back to etc/database.env / app .env.
    // This guarantees panel and agent always agree on the server id.
    'server_id'     => (int) App\Support\PanelEnv::get('ACP_SERVER_ID', (string) env('ACP_SERVER_ID', '1')),

    // Where customer data lives (used from Step 3 onward)
    'paths' => [
        'accounts' => env('ACP_ACCOUNTS_PATH', '/home'),
        'www'      => env('ACP_WWW_PATH', '/var/www'),
    ],

    // Seconds the panel waits for paneld after enqueueing account tasks.
    // Tests force 0 (see AccountProvisioner).
    'provision_wait' => (int) env('ACP_PROVISION_WAIT', 25),

    // First admin account (used once by AdminUserSeeder during install).
    // Kept here — not read with env() in the seeder — because env() is
    // unavailable when the config cache is warm on a production server.
    'admin' => [
        'user'         => env('ACP_ADMIN_USER', 'admin'),
        'password'     => env('ACP_ADMIN_PASSWORD'),
        'email'        => env('ACP_ADMIN_EMAIL'),
        'force_change' => (bool) env('ACP_ADMIN_FORCE_CHANGE', true),
    ],

    // License client (S2C): offline-first signed payload + local trial.
    // An empty API URL is safe: the panel starts a local trial and never blocks
    // customer websites/email because a license server is unavailable.
    'license' => [
        'api_url' => (string) env('ACP_LICENSE_API_URL', ''),
        'timeout' => (int) env('ACP_LICENSE_TIMEOUT', 8),
        'store_path' => (string) env('ACP_LICENSE_STORE_PATH', storage_path('app/private/license.json')),
        'public_key' => (string) env('ACP_LICENSE_PUBLIC_KEY', ''),
        'public_key_path' => (string) env('ACP_LICENSE_PUBLIC_KEY_PATH', config_path('license_public.pem')),
    ],

    // Password quality
    //
    // check_pwned queries haveibeenpwned.com (k-anonymity: only 5 hash chars
    // leave the server). On a host without outbound access that call is a
    // 30-second stall, so the timeout is short and the check can be turned off
    // with ACP_CHECK_PWNED=false — the rest of the policy still applies.
    'password_check_pwned'  => (bool) env('ACP_CHECK_PWNED', true),
    'password_pwned_timeout' => (int) env('ACP_PWNED_TIMEOUT', 3),

    // Login protection (cPHulk-lite — full cPHulk arrives in Step 13)
    'security' => [
        'max_login_attempts'   => (int) env('ACP_MAX_LOGIN_ATTEMPTS', 5),
        'lockout_minutes'      => (int) env('ACP_LOCKOUT_MINUTES', 15),
        'throttle_per_minute'  => (int) env('ACP_LOGIN_THROTTLE', 10),
        'session_lifetime'     => (int) env('ACP_SESSION_LIFETIME', 30),
        'password_min_length'  => (int) env('ACP_PASSWORD_MIN_LENGTH', 10),

        // Clickjacking protection: DENY (production default) | SAMEORIGIN | OFF.
        // OFF exists only for --insecure-http dev boxes that are viewed inside a
        // preview iframe; a default install never sets it.
        'frame_options'        => env('ACP_FRAME_OPTIONS', 'DENY'),
    ],
];
