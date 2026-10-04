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
class ReplaceEstimateReservation
{
    public function handle(WorkRequest $request, EstimateVersion $estimate, EstimateApproval $approval): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('예약 교체는 승인 트랜잭션 안에서 실행해야 합니다.');
        }

        $reservationMonthIds = $request->approved_estimate_version_id === null
            ? collect()
            : TimeLedgerEntry::query()
                ->where('company_id', $request->company_id)
                ->where('work_request_id', $request->id)
                ->whereIn('type', [TimeLedgerType::Reserve->value, TimeLedgerType::Release->value])
                ->distinct()->pluck('contract_month_id');
        $targetMonthId = ContractMonth::query()
            ->where('company_id', $request->company_id)
            ->where('service_contract_id', $request->service_contract_id)
            ->whereDate('month', $estimate->usage_month)
            ->value('id');

        // Lock every old and new month in one deterministic order. This also
        // serializes replacements that move reservations in opposite directions.
        $monthIds = $reservationMonthIds->concat($targetMonthId === null ? [] : [$targetMonthId])
            ->unique()->sort()->values();
        $months = ContractMonth::query()->whereIn('id', $monthIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $remaining = [];
        foreach ($reservationMonthIds as $monthId) {
            $entries = TimeLedgerEntry::query()
                ->where('company_id', $request->company_id)
                ->where('contract_month_id', $monthId)
                ->where('work_request_id', $request->id)
                ->whereIn('type', [TimeLedgerType::Reserve->value, TimeLedgerType::Release->value])
                ->orderBy('id')->lockForUpdate()->get();
            $minutes = $entries->sum(fn (TimeLedgerEntry $entry): int => $entry->type === TimeLedgerType::Reserve
                ? $entry->minutes : -$entry->minutes);
            if ($minutes < 0) {
                throw ValidationException::withMessages(['ledger' => '요청의 예약 원장 잔액이 올바르지 않습니다.']);
            }
            if ($minutes > 0) {
                $remaining[] = [$months->get($monthId), $minutes];
            }
        }
        if (count($remaining) > 1) {
            throw ValidationException::withMessages(['ledger' => '여러 월의 남은 예약을 먼저 정리해 주세요.']);
        }

        foreach ($remaining as [$month, $minutes]) {
            if (! $month instanceof ContractMonth || $month->status !== 'open') {
                throw ValidationException::withMessages(['month' => '마감된 월의 남은 예약은 교체할 수 없습니다.']);
            }
            (new TimeLedgerEntry)->forceFill([
                'company_id' => $request->company_id,
                'contract_month_id' => $month->id,
                'work_request_id' => $request->id,
                'type' => TimeLedgerType::Release,
                'minutes' => $minutes,
                'source_type' => 'estimate_replacement',
                'source_id' => $approval->id,
                'actor_id' => $approval->approved_by,
                'reason' => '견적 변경 기존 예약 해제',
                'occurred_at' => $approval->approved_at,
            ])->save();
        }

        (new ReserveEstimateTime)->handle($request, $estimate, $approval);
    }
}
