<?php

namespace Tests\Unit\Enums;

use App\Enums\Permission;
use App\Enums\UserRole;
use PHPUnit\Framework\TestCase;

class UserRoleTest extends TestCase
{
    public function test_roles_use_stable_database_values_and_korean_labels(): void
    {
        $this->assertSame('super_admin', UserRole::SuperAdmin->value);
        $this->assertSame('operator', UserRole::Operator->value);
        $this->assertSame('customer_admin', UserRole::CustomerAdmin->value);
        $this->assertSame('customer_user', UserRole::CustomerUser->value);

        $this->assertSame('최고 관리자', UserRole::SuperAdmin->label());
        $this->assertSame('운영자', UserRole::Operator->label());
        $this->assertSame('고객사 관리자', UserRole::CustomerAdmin->label());
        $this->assertSame('고객사 일반 사용자', UserRole::CustomerUser->label());
    }

    public function test_system_and_customer_roles_are_distinguished(): void
    {
        $this->assertTrue(UserRole::SuperAdmin->isSystemRole());
        $this->assertTrue(UserRole::Operator->isSystemRole());
        $this->assertFalse(UserRole::CustomerAdmin->isSystemRole());
        $this->assertFalse(UserRole::CustomerUser->isSystemRole());

        $this->assertFalse(UserRole::SuperAdmin->isCustomerRole());
        $this->assertTrue(UserRole::CustomerAdmin->isCustomerRole());
        $this->assertTrue(UserRole::CustomerUser->isCustomerRole());
    }

    public function test_super_admin_has_every_permission(): void
    {
        foreach (Permission::cases() as $permission) {
            $this->assertTrue(UserRole::SuperAdmin->hasPermission($permission));
        }
    }

    public function test_operator_has_operational_permissions_but_not_customer_approval_permissions(): void
    {
        $this->assertTrue(UserRole::Operator->hasPermission(Permission::ManageCompanies));
        $this->assertTrue(UserRole::Operator->hasPermission(Permission::CreateEstimates));
        $this->assertTrue(UserRole::Operator->hasPermission(Permission::LogWorkTime));
        $this->assertTrue(UserRole::Operator->hasPermission(Permission::CloseContractMonths));

        $this->assertFalse(UserRole::Operator->hasPermission(Permission::ApproveEstimates));
        $this->assertFalse(UserRole::Operator->hasPermission(Permission::CompleteReviews));
    }

    public function test_customer_admin_can_approve_and_review_but_customer_user_cannot(): void
    {
        $this->assertTrue(UserRole::CustomerAdmin->hasPermission(Permission::CreateRequests));
        $this->assertTrue(UserRole::CustomerAdmin->hasPermission(Permission::ApproveEstimates));
        $this->assertTrue(UserRole::CustomerAdmin->hasPermission(Permission::CompleteReviews));
        $this->assertTrue(UserRole::CustomerAdmin->hasPermission(Permission::ViewUsage));

        $this->assertTrue(UserRole::CustomerUser->hasPermission(Permission::CreateRequests));
        $this->assertTrue(UserRole::CustomerUser->hasPermission(Permission::CommentOnRequests));
        $this->assertFalse(UserRole::CustomerUser->hasPermission(Permission::ApproveEstimates));
        $this->assertFalse(UserRole::CustomerUser->hasPermission(Permission::CompleteReviews));
        $this->assertFalse(UserRole::CustomerUser->hasPermission(Permission::ViewUsage));
    }
}
