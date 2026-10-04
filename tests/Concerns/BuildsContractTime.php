<?php

namespace Tests\Concerns;

use App\Actions\AssessRequestPricing;
use App\Actions\CreateEstimateVersion;
use App\Actions\ProvideContractMonth;
use App\Actions\SubmitEstimateVersion;
use App\Enums\WorkDifficulty;
use App\Enums\WorkRequestStatus;
use App\Models\ContractMonth;
use App\Models\EstimateVersion;
use App\Models\PricingRule;
use App\Models\User;
use App\Models\WorkRequest;

trait BuildsContractTime
{
    /** @return array{WorkRequest, User, User, ContractMonth, EstimateVersion} */
    private function timeFixture(int $budget = 100, int $minutes = 60): array
    {
        $operator = User::factory()->operator()->create();
        $request = WorkRequest::factory()->withSignedContract()->create(['status' => WorkRequestStatus::AwaitingApproval]);
        $admin = User::factory()->customerAdmin()->for($request->company)->create();
        PricingRule::factory()->create();
        $month = (new ProvideContractMonth)->handle($operator, $request->serviceContract, today()->startOfMonth()->toDateString(), $budget);

        return [$request, $operator, $admin, $month, $this->timeEstimate($operator, $request, $minutes)];
    }

    private function timeEstimate(User $operator, WorkRequest $request, int $minutes = 60, int $monthOffset = 0): EstimateVersion
    {
        $assessment = (new AssessRequestPricing)->handle($operator, $request, WorkDifficulty::Normal, $minutes, today(), '시간 예약 검증');
        $estimate = (new CreateEstimateVersion)->handle($operator, $request, $assessment, '포함', '제외', today()->addWeek(), today()->startOfMonth()->addMonths($monthOffset));

        return (new SubmitEstimateVersion)->handle($operator, $estimate);
    }
}
