<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Enums\WorkRequestStatus;
use App\Models\EstimateVersion;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class VerifyCustomerADemoUserApprovalBlock
{
    public function handle(): EstimateVersion
    {
        $request = WorkRequest::query()
            ->where('source_reference', CreateCustomerADemoRequest::REQUEST_REFERENCE)
            ->first();

        if (! $request instanceof WorkRequest) {
            throw ValidationException::withMessages([
                'demo_request' => '4.27 고객사 A 데모 요청을 먼저 생성해 주세요.',
            ]);
        }

        $estimate = $request->estimateVersions()
            ->where('included_scope', SubmitCustomerADemoEstimate::INCLUDED_SCOPE)
            ->first();

        if (! $estimate instanceof EstimateVersion
            || $estimate->submitted_at === null
            || $request->status !== WorkRequestStatus::AwaitingApproval) {
            throw ValidationException::withMessages([
                'estimate' => '4.28 제출 완료 상태의 데모 견적이 필요합니다.',
            ]);
        }

        $customerUser = new User;
        $customerUser->forceFill([
            'company_id' => $request->company_id,
            'role' => UserRole::CustomerUser,
            'is_active' => true,
        ]);
        $gate = Gate::forUser($customerUser);

        if (! $gate->allows('view', $request)
            || ! $gate->allows('view', $estimate)
            || $gate->allows(Permission::ApproveEstimates->value)
            || $gate->allows('approve', $estimate)
            || $gate->allows('decide', $estimate)
            || $gate->allows('requestRevision', $estimate)) {
            throw ValidationException::withMessages([
                'authorization' => '일반 사용자의 견적 조회·승인 권한 경계가 예상과 다릅니다.',
            ]);
        }

        try {
            $gate->authorize('approve', $estimate);
        } catch (AuthorizationException) {
            return $estimate;
        }

        throw ValidationException::withMessages([
            'authorization' => '일반 사용자의 견적 승인이 차단되지 않았습니다.',
        ]);
    }
}
