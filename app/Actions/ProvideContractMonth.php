<?php

namespace App\Actions;

use App\Enums\TimeLedgerType;
use App\Models\ContractMonth;
use App\Models\ServiceContract;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProvideContractMonth
{
    public function handle(User $actor, ServiceContract $contract, string $month, int $minutes): ContractMonth
    {
        Gate::forUser($actor)->authorize('provideMonth', $contract);
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])-01$/D', $month) || substr($month, 0, 4) < '1000'
            || $minutes < 1 || $minutes > 10000000) {
            throw ValidationException::withMessages(['month' => '기준 월의 첫날과 유효한 제공 분을 입력해 주세요.']);
        }

        return DB::transaction(function () use ($actor, $contract, $month, $minutes): ContractMonth {
            $contract = ServiceContract::query()->lockForUpdate()->findOrFail($contract->id);
            $actor = User::query()->findOrFail($actor->id);
            Gate::forUser($actor)->authorize('provideMonth', $contract);
            $date = CarbonImmutable::parse($month);
            if (! $contract->permitsWork() || $date->endOfMonth()->lt($contract->starts_on)
                || ($contract->ends_on !== null && $date->gt($contract->ends_on))) {
                throw ValidationException::withMessages(['month' => '유효한 서명 계약의 기간에 해당하는 월만 제공할 수 있습니다.']);
            }
            $existing = ContractMonth::query()->where('service_contract_id', $contract->id)->where('month', $month)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->provided_minutes !== $minutes) {
                    throw ValidationException::withMessages(['minutes' => '이미 제공된 시간은 조정 원장으로 변경해야 합니다.']);
                }

                return $existing;
            }
            $record = new ContractMonth;
            $record->forceFill(['company_id' => $contract->company_id, 'service_contract_id' => $contract->id,
                'month' => $month, 'provided_minutes' => $minutes, 'status' => 'open'])->save();
            (new TimeLedgerEntry)->forceFill(['company_id' => $contract->company_id, 'contract_month_id' => $record->id,
                'type' => TimeLedgerType::Provided, 'minutes' => $minutes, 'source_type' => 'contract_month',
                'source_id' => $record->id, 'actor_id' => $actor->id, 'reason' => '월 계약시간 제공', 'occurred_at' => now()])->save();

            return $record;
        }, 5);
    }
}
