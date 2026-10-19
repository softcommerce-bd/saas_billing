<?php

namespace Tests\Unit;

use App\Modules\Tenancy\Enums\Permission;
use App\Modules\Tenancy\Enums\Role;
use PHPUnit\Framework\TestCase;

class RolePermissionTest extends TestCase
{
    public function test_owner_has_every_permission(): void
    {
        $this->assertCount(count(Permission::cases()), Role::Owner->permissions());
    }

    public function test_only_owner_can_manage_subscription(): void
    {
        foreach (Role::cases() as $role) {
            $this->assertSame(
                $role === Role::Owner,
                in_array(Permission::SubscriptionManage, $role->permissions(), true),
                $role->value,
            );
        }
    }

    public function test_viewer_is_read_only(): void
    {
        foreach (Role::Viewer->permissions() as $permission) {
            $this->assertStringEndsWith('.view', $permission->value);
        }
    }

    public function test_multiple_roles_union_permissions(): void
    {
        $permissions = Role::permissionsFor([Role::Viewer, Role::CollectionExecutive]);

        $this->assertContains(Permission::PaymentsRecord->value, $permissions);
        $this->assertContains(Permission::CatalogView->value, $permissions);
        $this->assertNotContains(Permission::InvoicesVoid->value, $permissions);
        $this->assertSame(array_values(array_unique($permissions)), $permissions);
    }
}
