<?php

namespace App\Enums;

enum Permission: string
{
    case ManageCompanies = 'manage_companies';
    case ManageCompanyUsers = 'manage_company_users';
    case ManageCompanyRequests = 'manage_company_requests';
    case ManageContracts = 'manage_contracts';
    case CreateEstimates = 'create_estimates';
    case ManageWork = 'manage_work';
    case LogWorkTime = 'log_work_time';
    case CloseContractMonths = 'close_contract_months';
    case CreateRequests = 'create_requests';
    case ViewCompanyRequests = 'view_company_requests';
    case CommentOnRequests = 'comment_on_requests';
    case ApproveEstimates = 'approve_estimates';
    case CompleteReviews = 'complete_reviews';
    case ViewUsage = 'view_usage';
}
