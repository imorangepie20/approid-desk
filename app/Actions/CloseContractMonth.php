<?php

namespace App\Actions;

use App\Enums\WorkLogStatus;
use App\Models\ContractMonth;
use App\Models\MonthClosure;
use App\Models\User;
use App\Models\WorkLog;
use App\Services\ContractMonthTotals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CloseContractMonth
{
    public function handle(User $actor, ContractMonth $month): MonthClosure
    {
        return DB::transaction(function () use ($actor, $month): MonthClosure {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $month = ContractMonth::query()->lockForUpdate()->findOrFail($month->id);
            Gate::forUser($actor)->authorize('close', $month);
            if ($month->status === 'closed') {
                return MonthClosure::query()->where('contract_month_id', $month->id)->firstOrFail();
            }
            if ($month->month->copy()->endOfMonth()->gte(today())) {
                throw ValidationException::withMessages(['month' => '종료된 월만 마감할 수 있습니다.']);
            }
            $drafts = WorkLog::query()->where('company_id', $month->company_id)
                ->whereHas('workRequest', fn ($query) => $query->where('service_contract_id', $month->service_contract_id))
                ->whereBetween('worked_on', [$month->month->toDateString(), $month->month->copy()->endOfMonth()->toDateString()])
                ->where('status', WorkLogStatus::Draft)->lockForUpdate()->get();
            if ($drafts->isNotEmpty()) {
                throw ValidationException::withMessages(['work_logs' => '해당 월의 임시저장 작업기록을 먼저 처리해 주세요.']);
            }
            $entries = $month->entries()->orderBy('id')->lockForUpdate()->get();
            $totals = (new ContractMonthTotals)->calculate($entries);
            if ($totals['remaining_reserved'] !== 0 || $totals['net_usage'] < 0 || $totals['available'] < 0
                || $totals['provided'] !== $month->provided_minutes) {
                throw ValidationException::withMessages(['ledger' => '남은 예약과 원장 잔액을 확인한 뒤 마감해 주세요.']);
            }
            $closure = (new MonthClosure)->forceFill(['company_id' => $month->company_id, 'contract_month_id' => $month->id,
                'totals' => $totals, 'entry_count' => $entries->count(), 'last_entry_id' => $entries->last()?->id,
                'closed_by' => $actor->id, 'closed_at' => now()]);
            $closure->save();
            $month->forceFill(['status' => 'closed', 'closed_by' => $actor->id, 'closed_at' => $closure->closed_at])->save();

            return $closure;
        }, 5);
    }
}
