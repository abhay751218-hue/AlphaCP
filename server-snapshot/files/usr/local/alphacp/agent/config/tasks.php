<?php
declare(strict_types=1);

/**
 * ============================================================================
 *  AlphaCP paneld — TASK ALLOWLIST  (the security heart of the agent)
 * ============================================================================
 *
 *  Hard rules:
 *  1. A task type that is NOT in this file can never run — the agent refuses it.
 *  2. Every task declares a safety class:
 *       readonly    = no side effects            (safe to auto-run, no confirm)
 *       mutating    = changes server state       (panel shows a warning)
 *       destructive = deletes/irreversible       (needs `_confirm` in payload)
 *  3. Every payload is validated against its JSON schema BEFORE execution.
 *  4. `paths` (if present) becomes the task's PathGuard allowlist.
 *  5. `timeout` is the hard second-limit for any command the handler runs.
 *
 *  When adding a task (checklist in docs/08-module-blueprint.md):
 *    - write the handler in src/Tasks/, keep it idempotent,
 *    - write the tightest possible schema (additionalProperties = false),
 *    - pick the LOWEST safety class that is honest,
 *    - add a test under tests/ and mention it in CHANGELOG.md.
 */

use Alphacp\Agent\Tasks;

return [

    // ---------------------------------------------------------------------
    //  HEALTH / DIAGNOSTICS
    // ---------------------------------------------------------------------
    'agent.ping' => [
        'handler'     => Tasks\AgentPing::class,
        'safety'      => 'readonly',
        'timeout'     => 10,
        'description' => 'Queue liveness check — replies pong with agent version.',
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [],
        ],
    ],

    'system.info' => [
        'handler'     => Tasks\SystemInfo::class,
        'safety'      => 'readonly',
        'timeout'     => 15,
        'description' => 'Hostname, kernel, CPU, memory, swap, load, disk, uptime.',
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [],
        ],
    ],

    'service.status' => [
        'handler'     => Tasks\ServiceStatus::class,
        'safety'      => 'readonly',
        'timeout'     => 60,
        'description' => 'systemd active/enabled state for allowlisted hosting services.',
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'services' => [
                    'type'  => 'array',
                    'items' => ['type' => 'string', 'maxLength' => 60],
                    'maxItems' => 40,
                ],
            ],
        ],
        // The ONLY service names the agent will ever ask systemd about.
        'services'    => [
            'apache2', 'nginx', 'mariadb', 'redis-server', 'bind9', 'fail2ban',
            'exim4', 'dovecot', 'clamav-daemon', 'opendkim', 'pure-ftpd', 'paneld', 'ufw',
        ],
    ],

    // ---------------------------------------------------------------------
    //  ACCOUNTS (Step 3)
    // ---------------------------------------------------------------------
    'account.create' => [
        'handler'     => Tasks\AccountCreate::class,
        'safety'      => 'mutating',
        'timeout'     => 90,
        'description' => 'Create Linux user, home, Apache vhost, PHP-FPM pool, quota.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'domain', 'shadow_hash'],
            'properties'           => [
                'username'    => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'domain'      => ['type' => 'string', 'pattern' => '^[a-z0-9.-]+$', 'maxLength' => 190],
                'shadow_hash' => ['type' => 'string', 'minLength' => 20, 'maxLength' => 200, 'pattern' => '^\\$6\\$.+'],
                'quota_mb'    => ['type' => 'integer', 'minimum' => -1, 'maximum' => 10485760],
                'php_version' => ['type' => 'string', 'pattern' => '^(7\\.4|8\\.[0-9])$'],
            ],
        ],
    ],

    'account.suspend' => [
        'handler'     => Tasks\AccountSuspend::class,
        'safety'      => 'mutating',
        'timeout'     => 60,
        'description' => 'Lock user, swap vhost to suspended page, disable PHP-FPM pool.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'domain'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'domain'   => ['type' => 'string', 'pattern' => '^[a-z0-9.-]+$', 'maxLength' => 190],
                'reason'   => ['type' => 'string', 'maxLength' => 255],
            ],
        ],
    ],

    'account.unsuspend' => [
        'handler'     => Tasks\AccountUnsuspend::class,
        'safety'      => 'mutating',
        'timeout'     => 60,
        'description' => 'Unlock user and restore vhost + PHP-FPM pool.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'domain'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'domain'   => ['type' => 'string', 'pattern' => '^[a-z0-9.-]+$', 'maxLength' => 190],
            ],
        ],
    ],

    'account.terminate' => [
        'handler'     => Tasks\AccountTerminate::class,
        'safety'      => 'destructive',
        'timeout'     => 90,
        'confirm'     => 'account.terminate',
        'description' => 'Remove vhost, pool, quota and Linux user+home.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', '_confirm'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                '_confirm' => ['type' => 'string', 'enum' => ['account.terminate']],
            ],
        ],
    ],

    'domain.add' => [
        'handler'     => Tasks\DomainAdd::class,
        'safety'      => 'mutating',
        'timeout'     => 45,
        'description' => 'Add addon/sub/parked/redirect vhost under an account.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'domain', 'type', 'document_root'],
            'properties'           => [
                'username'       => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'domain'         => ['type' => 'string', 'pattern' => '^[a-z0-9.-]+$', 'maxLength' => 190],
                'type'           => ['type' => 'string', 'enum' => ['addon', 'sub', 'parked', 'redirect']],
                'document_root'  => ['type' => 'string', 'minLength' => 2, 'maxLength' => 255],
                'redirect_url'   => ['type' => 'string', 'maxLength' => 500],
                'redirect_code'  => ['type' => 'integer', 'enum' => [301, 302]],
            ],
        ],
    ],

    'domain.remove' => [
        'handler'     => Tasks\DomainRemove::class,
        'safety'      => 'mutating',
        'timeout'     => 45,
        'description' => 'Remove extra vhost; document root files stay.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'domain'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'domain'   => ['type' => 'string', 'pattern' => '^[a-z0-9.-]+$', 'maxLength' => 190],
            ],
        ],
    ],

    'php.setVersion' => [
        'handler'     => Tasks\PhpSetVersion::class,
        'safety'      => 'mutating',
        'timeout'     => 45,
        'description' => 'Move account PHP-FPM pool to another MultiPHP version.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'php_version'],
            'properties'           => [
                'username'    => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'php_version' => ['type' => 'string', 'pattern' => '^(7\\.4|8\\.[0-9])$'],
            ],
        ],
    ],

    'php.setIni' => [
        'handler'     => Tasks\PhpSetIni::class,
        'safety'      => 'mutating',
        'timeout'     => 45,
        'description' => 'Write allowlisted MultiPHP INI directives into the account FPM pool.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'directives'],
            'properties'           => [
                'username'   => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'directives' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        'display_errors'         => ['type' => 'string', 'maxLength' => 8],
                        'log_errors'             => ['type' => 'string', 'maxLength' => 8],
                        'allow_url_fopen'        => ['type' => 'string', 'maxLength' => 8],
                        'short_open_tag'         => ['type' => 'string', 'maxLength' => 8],
                        'max_execution_time'     => ['type' => 'string', 'maxLength' => 8],
                        'max_input_time'         => ['type' => 'string', 'maxLength' => 8],
                        'max_input_vars'         => ['type' => 'string', 'maxLength' => 8],
                        'memory_limit'           => ['type' => 'string', 'maxLength' => 12],
                        'post_max_size'          => ['type' => 'string', 'maxLength' => 12],
                        'upload_max_filesize'    => ['type' => 'string', 'maxLength' => 12],
                        'date.timezone'          => ['type' => 'string', 'maxLength' => 60],
                        'error_reporting'        => ['type' => 'string', 'maxLength' => 40],
                        'session.gc_maxlifetime' => ['type' => 'string', 'maxLength' => 12],
                        'default_charset'        => ['type' => 'string', 'maxLength' => 20],
                    ],
                ],
            ],
        ],
    ],

    'errorpages.set' => [
        'handler'     => Tasks\ErrorPagesSet::class,
        'safety'      => 'mutating',
        'timeout'     => 30,
        'description' => 'Write custom 4xx/5xx HTML and Apache ErrorDocument snippet.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'pages'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'pages'    => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        '400' => ['type' => 'string', 'maxLength' => 16384],
                        '401' => ['type' => 'string', 'maxLength' => 16384],
                        '403' => ['type' => 'string', 'maxLength' => 16384],
                        '404' => ['type' => 'string', 'maxLength' => 16384],
                        '500' => ['type' => 'string', 'maxLength' => 16384],
                        '503' => ['type' => 'string', 'maxLength' => 16384],
                    ],
                ],
            ],
        ],
    ],

    'indexes.set' => [
        'handler'     => Tasks\IndexesSet::class,
        'safety'      => 'mutating',
        'timeout'     => 30,
        'description' => 'Set Apache directory listing mode (off/simple/fancy) for an account.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'mode'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'mode'     => ['type' => 'string', 'enum' => ['off', 'simple', 'fancy']],
            ],
        ],
    ],

    'mime.set' => [
        'handler'     => Tasks\MimeTypesSet::class,
        'safety'      => 'mutating',
        'timeout'     => 30,
        'description' => 'Replace account Apache AddType MIME mappings (Content-Type only).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'mappings'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'mappings' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['mime', 'ext'],
                        'properties'           => [
                            'mime' => ['type' => 'string', 'maxLength' => 80],
                            'ext'  => ['type' => 'string', 'maxLength' => 16],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'handlers.set' => [
        'handler'     => Tasks\HandlersSet::class,
        'safety'      => 'mutating',
        'timeout'     => 30,
        'description' => 'Replace account Apache AddHandler mappings (allowlisted handlers only).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'mappings'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'mappings' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['handler', 'ext'],
                        'properties'           => [
                            'handler' => ['type' => 'string', 'maxLength' => 64],
                            'ext'     => ['type' => 'string', 'maxLength' => 16],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'files.list' => [
        'handler'     => Tasks\FilesList::class,
        'safety'      => 'readonly',
        'timeout'     => 15,
        'description' => 'List files under the account home (relative path).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'path'     => ['type' => 'string', 'maxLength' => 240],
            ],
        ],
    ],

    'files.usage' => [
        'handler'     => Tasks\FilesUsage::class,
        'safety'      => 'readonly',
        'timeout'     => 20,
        'description' => 'Folder-wise disk usage under the account home (relative path).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'path'     => ['type' => 'string', 'maxLength' => 240],
            ],
        ],
    ],

    'files.set' => [
        'handler'     => Tasks\FilesSet::class,
        'safety'      => 'mutating',
        'timeout'     => 30,
        'description' => 'mkdir/write/delete/rename a path under the account home.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'op', 'path'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'op'       => ['type' => 'string', 'enum' => ['mkdir', 'write', 'delete', 'rename']],
                'path'     => ['type' => 'string', 'maxLength' => 240],
                'to'       => ['type' => 'string', 'maxLength' => 240],
                'content'  => ['type' => 'string', 'maxLength' => 262144],
            ],
        ],
    ],

    'privacy.set' => [
        'handler'     => Tasks\PrivacySet::class,
        'safety'      => 'mutating',
        'timeout'     => 30,
        'description' => 'Apache Basic Auth (Directory Privacy) for folders under the account home.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'entries'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'entries'  => [
                    'type'     => 'array',
                    'maxItems' => 20,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['path', 'realm', 'users'],
                        'properties'           => [
                            'path'  => ['type' => 'string', 'maxLength' => 240],
                            'realm' => ['type' => 'string', 'maxLength' => 64],
                            'users' => [
                                'type'     => 'array',
                                'maxItems' => 20,
                                'items'    => [
                                    'type'                 => 'object',
                                    'additionalProperties' => false,
                                    'required'             => ['name', 'hash'],
                                    'properties'           => [
                                        'name' => ['type' => 'string', 'maxLength' => 32],
                                        'hash' => ['type' => 'string', 'maxLength' => 64],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'mail.webmail' => [
        'handler'     => Tasks\MailWebmail::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write webmail enabled + client (JSON; no Roundcube/Horde install).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'enabled', 'client'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'enabled'  => ['type' => 'boolean'],
                'client'   => ['type' => 'string', 'enum' => ['roundcube', 'horde']],
            ],
        ],
    ],

    'db.set' => [
        'handler'     => Tasks\MysqlSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write prefixed MySQL database names (JSON; no mysql binary).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'databases'],
            'properties'           => [
                'username'  => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'databases' => [
                    'type'  => 'array',
                    'maxItems' => 50,
                    'items' => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['name'],
                        'properties'           => [
                            'name' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,15}$', 'maxLength' => 16],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'db.phpmyadmin' => [
        'handler'     => Tasks\PhpmyadminSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write phpMyAdmin enabled flag (JSON; no phpMyAdmin install).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'enabled'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'enabled'  => ['type' => 'boolean'],
            ],
        ],
    ],

    'db.remote' => [
        'handler'     => Tasks\RemoteMysqlSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write Remote MySQL access hosts (JSON; no mysql GRANT).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'hosts'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'hosts'    => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['host'],
                        'properties'           => [
                            'host' => ['type' => 'string', 'maxLength' => 190],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'dns.zone' => [
        'handler'     => Tasks\ZoneSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write A/CNAME/MX/TXT records (JSON; no BIND rewrite).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'records'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'records'  => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['domain', 'name', 'type', 'value'],
                        'properties'           => [
                            'domain' => ['type' => 'string', 'maxLength' => 190],
                            'name'   => ['type' => 'string', 'maxLength' => 63],
                            'type'   => ['type' => 'string', 'enum' => ['A', 'CNAME', 'MX', 'TXT']],
                            'value'  => ['type' => 'string', 'maxLength' => 255],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'mail.usage' => [
        'handler'     => Tasks\MailUsage::class,
        'safety'      => 'readonly',
        'timeout'     => 20,
        'description' => 'Folder-wise size under ~/mail (relative path, no purge, no symlink).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'path'     => ['type' => 'string', 'maxLength' => 240],
            ],
        ],
    ],

    'mail.calendar' => [
        'handler'     => Tasks\MailCalendar::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write calendar + contact names (JSON; no CalDAV/CardDAV daemon).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'calendars', 'contacts'],
            'properties'           => [
                'username'  => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'calendars' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['name'],
                        'properties'           => [
                            'name' => ['type' => 'string', 'maxLength' => 64],
                        ],
                    ],
                ],
                'contacts' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['name'],
                        'properties'           => [
                            'name' => ['type' => 'string', 'maxLength' => 64],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'mail.boxtrapper' => [
        'handler'     => Tasks\MailBoxtrapper::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write BoxTrapper enabled + allowlist (JSON, email only, no daemon).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'enabled'],
            'properties'           => [
                'username'  => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'enabled'   => ['type' => 'boolean'],
                'allowlist' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => ['type' => 'string', 'maxLength' => 190],
                ],
            ],
        ],
    ],

    'mail.encrypt' => [
        'handler'     => Tasks\MailEncrypt::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Replace GnuPG identity rows (JSON; no gpg binary, no private key).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'keys'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'keys'     => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['local', 'domain', 'comment'],
                        'properties'           => [
                            'local'   => ['type' => 'string', 'maxLength' => 32],
                            'domain'  => ['type' => 'string', 'maxLength' => 190],
                            'comment' => ['type' => 'string', 'maxLength' => 100],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'mail.gfilter' => [
        'handler'     => Tasks\MailGfilter::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Replace account-wide email filters (JSON, contains-match, no pipe).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'filters'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'filters'  => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['domain', 'field', 'needle', 'action'],
                        'properties'           => [
                            'domain' => ['type' => 'string', 'maxLength' => 190],
                            'field'  => ['type' => 'string', 'enum' => ['from', 'subject', 'to']],
                            'needle' => ['type' => 'string', 'maxLength' => 100],
                            'action' => ['type' => 'string', 'enum' => ['discard', 'folder']],
                            'folder' => ['type' => 'string', 'maxLength' => 32],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'mail.track' => [
        'handler'     => Tasks\MailTrack::class,
        'safety'      => 'readonly',
        'timeout'     => 20,
        'description' => 'Search jailed track.json by recipient email (no Exim log, no pipe).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'query'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'query'    => ['type' => 'string', 'maxLength' => 190],
            ],
        ],
    ],

    'mail.routing' => [
        'handler'     => Tasks\MailRouting::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Replace per-domain mail routing mode (JSON; auto/local/backup/remote).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'routes'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'routes'   => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['domain', 'mode'],
                        'properties'           => [
                            'domain' => ['type' => 'string', 'maxLength' => 190],
                            'mode'   => ['type' => 'string', 'enum' => ['auto', 'local', 'backup', 'remote']],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'mail.list' => [
        'handler'     => Tasks\MailList::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Replace mailing lists (JSON, owner email only, no mailman).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'lists'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'lists'    => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['local', 'domain', 'owner'],
                        'properties'           => [
                            'local'  => ['type' => 'string', 'maxLength' => 32],
                            'domain' => ['type' => 'string', 'maxLength' => 190],
                            'owner'  => ['type' => 'string', 'maxLength' => 190],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'mail.spam' => [
        'handler'     => Tasks\MailSpam::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write spam score + blacklist/whitelist (JSON, email only).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'required_score'],
            'properties'           => [
                'username'       => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'required_score' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
                'blacklist'      => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => ['type' => 'string', 'maxLength' => 190],
                ],
                'whitelist'      => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => ['type' => 'string', 'maxLength' => 190],
                ],
            ],
        ],
    ],

    'mail.deliverability' => [
        'handler'     => Tasks\MailDeliverability::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write recommended SPF/DMARC records (no DNS write).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'domains'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'domains'  => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => ['type' => 'string', 'maxLength' => 190],
                ],
            ],
        ],
    ],

    'mail.filter' => [
        'handler'     => Tasks\MailFilter::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Replace per-mailbox filters (JSON, contains-match, no pipe).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'filters'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'filters'  => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['local', 'domain', 'field', 'needle', 'action'],
                        'properties'           => [
                            'local'  => ['type' => 'string', 'maxLength' => 32],
                            'domain' => ['type' => 'string', 'maxLength' => 190],
                            'field'  => ['type' => 'string', 'enum' => ['from', 'subject', 'to']],
                            'needle' => ['type' => 'string', 'maxLength' => 100],
                            'action' => ['type' => 'string', 'enum' => ['discard', 'folder']],
                            'folder' => ['type' => 'string', 'maxLength' => 32],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'mail.catchall' => [
        'handler'     => Tasks\MailCatchall::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Replace default address catch-alls (email dest only).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'catchalls'],
            'properties'           => [
                'username'  => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'catchalls' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['domain', 'dest'],
                        'properties'           => [
                            'domain' => ['type' => 'string', 'maxLength' => 190],
                            'dest'   => ['type' => 'string', 'maxLength' => 190],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'mail.autorespond' => [
        'handler'     => Tasks\MailAutorespond::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Replace vacation autoresponders (JSON file, no pipe/shell).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'responders'],
            'properties'           => [
                'username'   => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'responders' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['local', 'domain', 'subject', 'body'],
                        'properties'           => [
                            'local'      => ['type' => 'string', 'maxLength' => 32],
                            'domain'     => ['type' => 'string', 'maxLength' => 190],
                            'subject'    => ['type' => 'string', 'maxLength' => 200],
                            'body'       => ['type' => 'string', 'maxLength' => 4000],
                            'interval_h' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 168],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'mail.forward' => [
        'handler'     => Tasks\MailForward::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Replace email forwarders (aliases file, email dest only).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'forwards'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'forwards' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['local', 'domain', 'dest'],
                        'properties'           => [
                            'local'  => ['type' => 'string', 'maxLength' => 32],
                            'domain' => ['type' => 'string', 'maxLength' => 190],
                            'dest'   => ['type' => 'string', 'maxLength' => 190],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'mail.set' => [
        'handler'     => Tasks\MailSet::class,
        'safety'      => 'mutating',
        'timeout'     => 30,
        'description' => 'Replace virtual mailboxes (passwd-file + Maildir) under the account home.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'mailboxes'],
            'properties'           => [
                'username'  => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'mailboxes' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['local', 'domain', 'hash'],
                        'properties'           => [
                            'local'    => ['type' => 'string', 'maxLength' => 32],
                            'domain'   => ['type' => 'string', 'maxLength' => 190],
                            'hash'     => ['type' => 'string', 'maxLength' => 80],
                            'quota_mb' => ['type' => 'integer', 'minimum' => -1, 'maximum' => 102400],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'ssh.set' => [
        'handler'     => Tasks\SshSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write ~/.ssh/authorized_keys and optional nologin/bash shell.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'keys'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'shell'    => ['type' => 'string', 'enum' => ['nologin', 'bash']],
                'keys'     => [
                    'type'     => 'array',
                    'maxItems' => 20,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['type', 'key'],
                        'properties'           => [
                            'type'    => ['type' => 'string', 'enum' => ['ssh-ed25519', 'ssh-rsa', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521']],
                            'key'     => ['type' => 'string', 'maxLength' => 8192],
                            'comment' => ['type' => 'string', 'maxLength' => 64],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'dns.dynamic' => [
        'handler'     => Tasks\DynamicSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write Dynamic DNS hosts (JSON; no BIND rewrite).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'hosts'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'hosts'    => [
                    'type'     => 'array',
                    'maxItems' => 20,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['domain', 'name', 'token', 'ip'],
                        'properties'           => [
                            'domain' => ['type' => 'string', 'maxLength' => 190],
                            'name'   => ['type' => 'string', 'maxLength' => 63],
                            'token'  => ['type' => 'string', 'pattern' => '^[a-f0-9]{32}$', 'maxLength' => 32],
                            'ip'     => ['type' => 'string', 'maxLength' => 15],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'dns.track' => [
        'handler'     => Tasks\DnsTrack::class,
        'safety'      => 'readonly',
        'timeout'     => 20,
        'description' => 'Search jailed zone/dynamic JSON by FQDN (no dig, no BIND).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'query', 'type'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'query'    => ['type' => 'string', 'maxLength' => 190],
                'type'     => ['type' => 'string', 'enum' => ['A', 'CNAME', 'MX', 'NS', 'TXT', 'ALL']],
            ],
        ],
    ],

    'dns.hostname' => [
        'handler'     => Tasks\HostnameASet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write hostname A record (JSON; no BIND rewrite).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['hostname', 'ip'],
            'properties'           => [
                'hostname' => ['type' => 'string', 'maxLength' => 190],
                'ip'       => ['type' => 'string', 'maxLength' => 15],
            ],
        ],
    ],

    'dns.templates' => [
        'handler'     => Tasks\TemplatesSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write DNS zone templates (JSON; no BIND rewrite).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['templates'],
            'properties'           => [
                'templates' => [
                    'type'     => 'array',
                    'maxItems' => 10,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['name', 'body'],
                        'properties'           => [
                            'name' => ['type' => 'string', 'maxLength' => 32],
                            'body' => ['type' => 'string', 'maxLength' => 2000],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'mail.globalrouting' => [
        'handler'     => Tasks\GlobalRoutingSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write WHM global email routing (JSON; no Exim rewrite).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['routes'],
            'properties'           => [
                'routes' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['domain', 'mode'],
                        'properties'           => [
                            'domain' => ['type' => 'string', 'maxLength' => 190],
                            'mode'   => ['type' => 'string', 'maxLength' => 16],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'dns.nsreport' => [
        'handler'     => Tasks\NsReportSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write nameserver record report (JSON; no BIND rewrite).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['records'],
            'properties'           => [
                'records' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['domain', 'nameserver'],
                        'properties'           => [
                            'domain'     => ['type' => 'string', 'maxLength' => 190],
                            'nameserver' => ['type' => 'string', 'maxLength' => 190],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'dns.park' => [
        'handler'     => Tasks\ParkSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write parked domain map (JSON; no BIND rewrite).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['parks'],
            'properties'           => [
                'parks' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['domain', 'target'],
                        'properties'           => [
                            'domain' => ['type' => 'string', 'maxLength' => 190],
                            'target' => ['type' => 'string', 'maxLength' => 190],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'dns.cleanup' => [
        'handler'     => Tasks\CleanupSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write DNS cleanup queue (JSON; no BIND rewrite).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['domains'],
            'properties'           => [
                'domains' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => ['type' => 'string', 'maxLength' => 190],
                ],
            ],
        ],
    ],

    'dns.ttl' => [
        'handler'     => Tasks\TtlSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write zone TTL map (JSON; no BIND rewrite).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['zones'],
            'properties'           => [
                'zones' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['domain', 'ttl'],
                        'properties'           => [
                            'domain' => ['type' => 'string', 'maxLength' => 190],
                            'ttl'    => ['type' => 'integer', 'minimum' => 60, 'maximum' => 86400],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'dns.forward' => [
        'handler'     => Tasks\ForwardSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write domain forwarding map (JSON; no BIND rewrite).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['forwards'],
            'properties'           => [
                'forwards' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['domain', 'url', 'code'],
                        'properties'           => [
                            'domain' => ['type' => 'string', 'maxLength' => 190],
                            'url'    => ['type' => 'string', 'maxLength' => 255],
                            'code'   => ['type' => 'integer', 'minimum' => 301, 'maximum' => 302],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'dns.sync' => [
        'handler'     => Tasks\SyncSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write DNS sync queue (JSON; no BIND rewrite).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['domains'],
            'properties'           => [
                'domains' => [
                    'type'     => 'array',
                    'maxItems' => 50,
                    'items'    => ['type' => 'string', 'maxLength' => 190],
                ],
            ],
        ],
    ],

    'dns.nameserver' => [
        'handler'     => Tasks\NameserverSet::class,
        'safety'      => 'mutating',
        'timeout'     => 20,
        'description' => 'Write nameserver selection (JSON; no BIND rewrite).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['software', 'ns1', 'ns2'],
            'properties'           => [
                'software' => ['type' => 'string', 'maxLength' => 16],
                'ns1'      => ['type' => 'string', 'maxLength' => 190],
                'ns2'      => ['type' => 'string', 'maxLength' => 190],
            ],
        ],
    ],

    'cron.set' => [
        'handler'     => Tasks\CronSet::class,
        'safety'      => 'mutating',
        'timeout'     => 30,
        'description' => 'Replace the account crontab (empty jobs clears it).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'jobs'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'jobs'     => [
                    'type'     => 'array',
                    'maxItems' => 100,
                    'items'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'required'             => ['minute', 'hour', 'day', 'month', 'weekday', 'command'],
                        'properties'           => [
                            'minute'  => ['type' => 'string', 'maxLength' => 40, 'pattern' => '^[0-9*,/-]+$'],
                            'hour'    => ['type' => 'string', 'maxLength' => 40, 'pattern' => '^[0-9*,/-]+$'],
                            'day'     => ['type' => 'string', 'maxLength' => 40, 'pattern' => '^[0-9*,/-]+$'],
                            'month'   => ['type' => 'string', 'maxLength' => 40, 'pattern' => '^[0-9*,/-]+$'],
                            'weekday' => ['type' => 'string', 'maxLength' => 40, 'pattern' => '^[0-9*,/-]+$'],
                            'command' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'ssl.issue' => [
        'handler'     => Tasks\SslIssue::class,
        'safety'      => 'mutating',
        'timeout'     => 120,
        'description' => 'Issue Let\'s Encrypt (certbot webroot) or self-signed cert + Apache :443 vhost.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'domain', 'document_root'],
            'properties'           => [
                'username'      => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'domain'        => ['type' => 'string', 'pattern' => '^[a-z0-9.-]+$', 'maxLength' => 190],
                'document_root' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 255],
                'mode'          => ['type' => 'string', 'enum' => ['selfsigned', 'letsencrypt']],
                'email'         => ['type' => 'string', 'maxLength' => 190],
            ],
        ],
    ],

    'ssl.remove' => [
        'handler'     => Tasks\SslRemove::class,
        'safety'      => 'mutating',
        'timeout'     => 30,
        'description' => 'Remove SSL vhost; cert files stay under the account home.',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'domain'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'domain'   => ['type' => 'string', 'pattern' => '^[a-z0-9.-]+$', 'maxLength' => 190],
            ],
        ],
    ],

    'account.setQuota' => [
        'handler'     => Tasks\AccountSetQuota::class,
        'safety'      => 'mutating',
        'timeout'     => 30,
        'description' => 'Set or clear disk quota for an account (MB, -1 unlimited).',
        'paths'       => ['/home', '/etc/apache2', '/etc/php', '/usr/local/alphacp'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['username', 'quota_mb'],
            'properties'           => [
                'username' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9]{2,15}$', 'maxLength' => 16],
                'quota_mb' => ['type' => 'integer', 'minimum' => -1, 'maximum' => 10485760],
            ],
        ],
    ],

];
