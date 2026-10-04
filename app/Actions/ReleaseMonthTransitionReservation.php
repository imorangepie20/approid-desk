<?php

namespace App\Actions;

use App\Enums\TimeLedgerType;
use App\Models\ContractMonth;
use App\Models\MonthTransitionNotice;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReleaseMonthTransitionReservation
{
    public function handle(User $actor, MonthTransitionNotice $notice, string $reason): TimeLedgerEntry
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 10000) {
            throw ValidationException::withMessages(['reason' => '월 전환 사유를 10,000자 이내로 입력해 주세요.']);
        }

        return DB::transaction(function () use ($actor, $notice, $reason): TimeLedgerEntry {
            $requestId = MonthTransitionNotice::query()->findOrFail($notice->id)->work_request_id;
            $request = WorkRequest::query()->lockForUpdate()->findOrFail($requestId);
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $notice = MonthTransitionNotice::query()->lockForUpdate()->findOrFail($notice->id);
            $month = ContractMonth::query()->lockForUpdate()->findOrFail($notice->contract_month_id);
            Gate::forUser($actor)->authorize('close', $month);

            $existing = TimeLedgerEntry::query()->where('source_type', 'month_transition_notice')
                ->where('source_id', $notice->id)->where('type', TimeLedgerType::Release)->first();
            if ($existing !== null) {
                if ($existing->actor_id !== $actor->id || $existing->reason !== $reason) {
                    throw ValidationException::withMessages(['notice' => '이미 다른 처리 정보로 월 전환 예약을 해제했습니다.']);
                }

                return $existing;
            }
            if ($notice->work_request_id !== $request->id || $notice->company_id !== $request->company_id
                || $month->company_id !== $request->company_id || $month->service_contract_id !== $request->service_contract_id) {
                throw ValidationException::withMessages(['notice' => '월 전환 대상 연결이 올바르지 않습니다.']);
            }
            if ($request->status->isTerminal()) {
                throw ValidationException::withMessages(['request' => '완료 또는 취소된 요청은 월 전환할 수 없습니다.']);
            }
            if ($month->status !== 'open' || today()->lt($month->month->copy()->endOfMonth()->startOfDay())) {
                throw ValidationException::withMessages(['month' => '열린 계약 월의 마지막 날부터 예약을 해제할 수 있습니다.']);
            }
            $entries = TimeLedgerEntry::query()->where('company_id', $request->company_id)
                ->where('contract_month_id', $month->id)->where('work_request_id', $request->id)
                ->whereIn('type', [TimeLedgerType::Reserve->value, TimeLedgerType::Release->value])
                ->orderBy('id')->lockForUpdate()->get();
            $remaining = $entries->sum(fn (TimeLedgerEntry $entry): int => $entry->type === TimeLedgerType::Reserve
                ? $entry->minutes : -$entry->minutes);
            if ($remaining <= 0) {
                throw ValidationException::withMessages(['ledger' => '해제할 남은 예약이 없습니다.']);
            }
            $releasedAt = now();
            $entry = (new TimeLedgerEntry)->forceFill([
                'company_id' => $request->company_id,
                'contract_month_id' => $month->id,
                'work_request_id' => $request->id,
                'type' => TimeLedgerType::Release,
                'minutes' => $remaining,
                'source_type' => 'month_transition_notice',
                'source_id' => $notice->id,
                'actor_id' => $actor->id,
                'reason' => $reason,
                'occurred_at' => $releasedAt,
            ]);
            $entry->save();

            return $entry;
        }, 5);
    }
}
