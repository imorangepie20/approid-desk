<?php

namespace App\Actions;

use App\Enums\TimeLedgerType;
use App\Models\ContractMonth;
use App\Models\MonthAdjustment;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Services\ContractMonthTotals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdjustContractMonth
{
    public function handle(User $actor, ContractMonth $month, TimeLedgerEntry $related, TimeLedgerType $type, int $minutes, string $reason, string $key): MonthAdjustment
    {
        $reason = trim($reason);
        if (! in_array($type, [TimeLedgerType::AdjustIncrease, TimeLedgerType::AdjustDecrease], true)
            || $minutes < 1 || $minutes > 10000000 || $reason === '' || mb_strlen($reason) > 10000 || ! Str::isUuid($key)) {
            throw ValidationException::withMessages(['adjustment' => '조정 종류·정수 분·사유·요청 키를 확인해 주세요.']);
        }
        $key = strtolower($key);

        return DB::transaction(function () use ($actor, $month, $related, $type, $minutes, $reason, $key): MonthAdjustment {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $month = ContractMonth::query()->lockForUpdate()->findOrFail($month->id);
            Gate::forUser($actor)->authorize('adjust', $month);
            $existing = MonthAdjustment::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if ($existing->contract_month_id !== $month->id || $existing->related_entry_id !== $related->id
                    || $existing->type !== $type || $existing->minutes !== $minutes || $existing->reason !== $reason
                    || $existing->approved_by !== $actor->id) {
                    throw ValidationException::withMessages(['idempotency_key' => '다른 조정에 사용된 요청 키입니다.']);
                }

                return $existing;
            }
            $related = TimeLedgerEntry::query()->findOrFail($related->id);
            if ($related->company_id !== $month->company_id || $related->contract_month_id !== $month->id) {
                throw ValidationException::withMessages(['related_entry' => '같은 고객사·월의 원장을 연결해 주세요.']);
            }
            $totals = (new ContractMonthTotals)->calculate($month->entries()->orderBy('id')->lockForUpdate()->get());
            if ($totals['available'] + $minutes * $type->availableSign() < 0) {
                throw ValidationException::withMessages(['minutes' => '사용 가능시간보다 많이 차감 조정할 수 없습니다.']);
            }
            $adjustment = (new MonthAdjustment)->forceFill(['company_id' => $month->company_id, 'contract_month_id' => $month->id,
                'related_entry_id' => $related->id, 'type' => $type, 'minutes' => $minutes, 'reason' => $reason,
                'approved_by' => $actor->id, 'approved_at' => now(), 'idempotency_key' => $key]);
            $adjustment->save();
            (new TimeLedgerEntry)->forceFill(['company_id' => $month->company_id, 'contract_month_id' => $month->id,
                'type' => $type, 'minutes' => $minutes, 'source_type' => 'month_adjustment', 'source_id' => $adjustment->id,
                'actor_id' => $actor->id, 'reason' => $reason, 'occurred_at' => $adjustment->approved_at])->save();

            return $adjustment;
        }, 5);
    }
}
