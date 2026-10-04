<?php

namespace App\Services;

use App\Enums\TimeLedgerType;
use App\Models\ContractMonth;
use App\Models\EstimateApproval;
use App\Models\EstimateVersion;
use App\Models\TimeLedgerEntry;
use App\Models\WorkRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Internal to ApproveEstimateVersion; caller holds the request lock. */
class ReserveEstimateTime
{
    public function handle(WorkRequest $request, EstimateVersion $estimate, EstimateApproval $approval): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('예약은 승인 트랜잭션 안에서 실행해야 합니다.');
        }
        $month = ContractMonth::query()->where('company_id', $request->company_id)
            ->where('service_contract_id', $request->service_contract_id)
            ->whereDate('month', $estimate->usage_month)->lockForUpdate()->first();
        if ($month === null || $month->status !== 'open') {
            throw ValidationException::withMessages(['month' => '사용 대상 월의 계약시간이 없거나 마감되었습니다.']);
        }
        // Locking reads see the latest committed entries even under MySQL REPEATABLE READ.
        $available = $month->entries()->orderBy('id')->lockForUpdate()->get()
            ->sum(fn (TimeLedgerEntry $entry): int => $entry->minutes * $entry->type->availableSign());
        if ($available < $estimate->estimated_minutes) {
            throw ValidationException::withMessages(['minutes' => '사용 가능한 월 계약시간이 부족합니다.']);
        }
        (new TimeLedgerEntry)->forceFill(['company_id' => $request->company_id, 'contract_month_id' => $month->id,
            'work_request_id' => $request->id, 'type' => TimeLedgerType::Reserve, 'minutes' => $estimate->estimated_minutes,
            'source_type' => 'estimate_approval', 'source_id' => $approval->id, 'actor_id' => $approval->approved_by,
            'reason' => '견적 승인 예상시간 예약', 'occurred_at' => $approval->approved_at])->save();
    }
}
