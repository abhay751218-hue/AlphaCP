    'ports.apply' => [
        'handler'     => Tasks\PortsApply::class,
        'safety'      => 'mutating',
        'timeout'     => 120,
        'description' => 'Owner port control: WHM/cPanel/link/webmail vhosts regen + reload (backup/restore safe).',
        'paths'       => ['/usr/local/alphacp', '/etc/nginx', '/usr/share/roundcube'],
        'schema'      => [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'action' => ['type' => 'string', 'enum' => ['apply', 'status']],
            ],
            'required'             => ['action'],
        ],
    ],
