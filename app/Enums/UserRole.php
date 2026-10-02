<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Operator = 'operator';
    case CustomerAdmin = 'customer_admin';
    case CustomerUser = 'customer_user';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => '최고 관리자',
            self::Operator => '운영자',
            self::CustomerAdmin => '고객사 관리자',
            self::CustomerUser => '고객사 일반 사용자',
        };
    }

    public function isSystemRole(): bool
    {
        return match ($this) {
            self::SuperAdmin, self::Operator => true,
            self::CustomerAdmin, self::CustomerUser => false,
        };
    }

    public function isCustomerRole(): bool
    {
        return ! $this->isSystemRole();
    }

    public function hasPermission(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::SuperAdmin => Permission::cases(),
            self::Operator => [
                Permission::ManageCompanies,
                Permission::ManageCompanyUsers,
                Permission::ManageCompanyRequests,
                Permission::ManageContracts,
                Permission::CreateEstimates,
                Permission::ManageWork,
                Permission::LogWorkTime,
                Permission::CloseContractMonths,
                Permission::CreateRequests,
                Permission::ViewCompanyRequests,
                Permission::CommentOnRequests,
                Permission::ViewUsage,
            ],
            self::CustomerAdmin => [
                Permission::ManageCompanyUsers,
                Permission::ManageCompanyRequests,
                Permission::CreateRequests,
                Permission::ViewCompanyRequests,
                Permission::CommentOnRequests,
                Permission::ApproveEstimates,
                Permission::CompleteReviews,
                Permission::ViewUsage,
            ],
            self::CustomerUser => [
                Permission::CreateRequests,
                Permission::ViewCompanyRequests,
                Permission::CommentOnRequests,
            ],
        };
    }
}
