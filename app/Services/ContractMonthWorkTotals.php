<?php

namespace App\Services;

use App\Enums\WorkLogStatus;
use App\Models\ContractMonth;
use App\Models\WorkLog;

class ContractMonthWorkTotals
{
    /** @return array{total_minutes: int, billable_minutes: int, non_billable_minutes: int, customer_charged_minutes: int} */
    public function calculate(ContractMonth $month): array
    {
        $logs = WorkLog::query()->where('work_logs.company_id', $month->company_id)
            ->where('work_logs.status', WorkLogStatus::Confirmed->value)
            ->whereBetween('work_logs.worked_on', [
                $month->month->toDateString(),
                $month->month->copy()->endOfMonth()->toDateString(),
            ])
            ->whereHas('workRequest', fn ($query) => $query->where('service_contract_id', $month->service_contract_id))
            ->get(['minutes', 'is_billable']);
        $billable = $logs->where('is_billable', true)->sum('minutes');
        $nonBillable = $logs->where('is_billable', false)->sum('minutes');
        $ledger = (new ContractMonthTotals)->calculate($month->entries()->get());

        return [
            'total_minutes' => $billable + $nonBillable,
            'billable_minutes' => $billable,
            'non_billable_minutes' => $nonBillable,
            'customer_charged_minutes' => $ledger['net_usage'],
        ];
    }
}
