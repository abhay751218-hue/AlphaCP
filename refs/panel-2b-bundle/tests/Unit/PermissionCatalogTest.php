<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PermissionCatalog;
use PHPUnit\Framework\TestCase;

class PermissionCatalogTest extends TestCase
{
    public function test_keys_are_namespaced_unique_strings(): void
    {
        $all = PermissionCatalog::all();
        $this->assertNotEmpty($all);

        foreach ($all as $key => $def) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9]*\.[a-z_]+$/', $key, "bad key: {$key}");
            $this->assertNotEmpty($def['label']);
            $this->assertNotEmpty($def['module']);
        }

        $this->assertSame(count($all), count(array_unique(array_keys($all))));
    }

    public function test_root_defaults_cover_every_permission(): void
    {
        $this->assertSame(
            array_keys(PermissionCatalog::all()),
            PermissionCatalog::defaultsForRole('root'),
        );
    }

    public function test_other_roles_never_receive_privileged_keys(): void
    {
        $forbidden = ['users.manage', 'license.manage', 'updates.manage', 'roles.manage'];

        foreach (['user', 'mail'] as $role) {
            $granted = PermissionCatalog::defaultsForRole($role);
            foreach ($forbidden as $key) {
                $this->assertNotContains($key, $granted, "{$role} must not have {$key}");
            }
            $this->assertContains('core.access', $granted);
        }
    }

    public function test_every_default_key_exists_in_the_catalog(): void
    {
        $all = array_keys(PermissionCatalog::all());

        foreach (['root', 'reseller', 'user', 'mail'] as $role) {
            foreach (PermissionCatalog::defaultsForRole($role) as $key) {
                $this->assertContains($key, $all, "{$role} has unknown permission {$key}");
            }
        }
    }

    public function test_unknown_role_gets_minimal_access(): void
    {
        $this->assertSame(['core.access'], PermissionCatalog::defaultsForRole('whatever'));
    }
}
