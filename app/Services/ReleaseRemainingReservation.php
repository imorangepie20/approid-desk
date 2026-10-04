<?php

namespace App\Services;

use App\Enums\TimeLedgerType;
use App\Enums\WorkRequestStatus;
use App\Models\ContractMonth;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkRequest;
use App\Models\WorkRequestStatusChange;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Internal to TransitionWorkRequest; caller holds the request lock. */
class ReleaseRemainingReservation
{
    public function handle(WorkRequest $request, WorkRequestStatusChange $change, User $actor): void
    {
        if (DB::transactionLevel() === 0 || ! in_array($change->to_status, [WorkRequestStatus::Completed, WorkRequestStatus::Cancelled], true)) {
            throw new LogicException('잔여 예약 해제는 완료·취소 전환 트랜잭션 안에서만 실행해야 합니다.');
        }

        $monthIds = TimeLedgerEntry::query()
            ->where('company_id', $request->company_id)
            ->where('work_request_id', $request->id)
            ->whereIn('type', [TimeLedgerType::Reserve->value, TimeLedgerType::Release->value])
            ->distinct()->pluck('contract_month_id');
        $months = ContractMonth::query()->whereIn('id', $monthIds)->orderBy('id')->lockForUpdate()->get();
        $remaining = [];
        foreach ($months as $month) {
            $entries = TimeLedgerEntry::query()->where('company_id', $request->company_id)
                ->where('contract_month_id', $month->id)->where('work_request_id', $request->id)
                ->whereIn('type', [TimeLedgerType::Reserve->value, TimeLedgerType::Release->value])
                ->orderBy('id')->lockForUpdate()->get();
            $minutes = $entries->sum(fn (TimeLedgerEntry $entry): int => $entry->type === TimeLedgerType::Reserve
                ? $entry->minutes : -$entry->minutes);
            if ($minutes < 0) {
                throw ValidationException::withMessages(['ledger' => '요청의 예약 원장 잔액이 올바르지 않습니다.']);
            }
            if ($minutes > 0) {
                $remaining[] = [$month, $minutes];
            }
        }
        // The workflow releases an old reservation before approving another month (3.8/3.9).
        if (count($remaining) > 1) {
            throw ValidationException::withMessages(['ledger' => '여러 월의 남은 예약을 먼저 정리해 주세요.']);
        }
        foreach ($remaining as [$month, $minutes]) {
            if ($month->status !== 'open') {
                throw ValidationException::withMessages(['month' => '마감된 월의 남은 예약은 해제할 수 없습니다.']);
            }
            (new TimeLedgerEntry)->forceFill([
                'company_id' => $request->company_id,
                'contract_month_id' => $month->id,
                'work_request_id' => $request->id,
                'type' => TimeLedgerType::Release,
                'minutes' => $minutes,
                'source_type' => 'work_request_status_change',
                'source_id' => $change->id,
                'actor_id' => $actor->id,
                'reason' => $change->to_status === WorkRequestStatus::Completed
                    ? '요청 완료 잔여 예약 해제' : '요청 취소 잔여 예약 해제',
                'occurred_at' => $change->occurred_at,
            ])->save();
        }
    }
}
