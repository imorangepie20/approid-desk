<?php

namespace App\Services;

use App\Enums\WorkLogStatus;
use App\Models\Company;
use App\Models\ContractMonth;
use App\Models\User;
use App\Models\WorkLog;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

class OperatorCompanyTimeOverview
{
    /**
     * @return array{
     *   month: CarbonImmutable,
     *   rows: Collection<int, array{company: Company, month_count: int<0, max>, provided_minutes: int, reserved_minutes: int, customer_charged_minutes: int, billable_minutes: int, non_billable_minutes: int, total_worked_minutes: int, available_minutes: int}>,
     *   totals: array{provided_minutes: int, reserved_minutes: int, customer_charged_minutes: int, billable_minutes: int, non_billable_minutes: int, total_worked_minutes: int, available_minutes: int}
     * }
     */
    public function forMonth(User $user, CarbonInterface $asOf): array
    {
        $user = User::query()->findOrFail($user->id);
        if (! $user->canAccessWorkspace() || ! $user->role->isSystemRole()) {
            throw new AuthorizationException;
        }

        $month = CarbonImmutable::instance($asOf)->startOfMonth();
        $companies = Company::query()->visibleTo($user)->active()->orderBy('name')->orderBy('id')->get();
        $companyIds = $companies->modelKeys();
        $months = ContractMonth::query()->whereIn('company_id', $companyIds)
            ->whereDate('month', $month)->with('entries')->orderBy('id')->get();
        $logs = WorkLog::query()->visibleTo($user)
            ->whereIn('company_id', $companyIds)
            ->where('status', WorkLogStatus::Confirmed->value)
            ->whereBetween('worked_on', [$month->toDateString(), $month->endOfMonth()->toDateString()])
            ->with('workRequest:id,service_contract_id')
            ->orderBy('id')->get();
        $logsByContract = $logs->groupBy(fn (WorkLog $log): string => $log->company_id.':'.($log->workRequest->service_contract_id ?? 0));
        $monthsByCompany = $months->groupBy('company_id');
        $ledgerTotals = new ContractMonthTotals;

        $rows = $companies->map(function (Company $company) use ($monthsByCompany, $logsByContract, $ledgerTotals): array {
            $companyMonths = $monthsByCompany->get($company->id, collect());
            $provided = 0;
            $reserved = 0;
            $charged = 0;
            $available = 0;
            $companyLogs = collect();
            foreach ($companyMonths as $contractMonth) {
                $totals = $ledgerTotals->calculate($contractMonth->entries);
                $provided += $totals['provided'];
                $reserved += $totals['remaining_reserved'];
                $charged += $totals['net_usage'];
                $available += $totals['available'];
                $companyLogs = $companyLogs->concat(
                    $logsByContract->get($company->id.':'.$contractMonth->service_contract_id, collect()),
                );
            }
            $companyLogs = $companyLogs->unique('id');
            $billable = (int) $companyLogs->where('is_billable', true)->sum('minutes');
            $nonBillable = (int) $companyLogs->where('is_billable', false)->sum('minutes');

            return [
                'company' => $company,
                'month_count' => $companyMonths->count(),
                'provided_minutes' => $provided,
                'reserved_minutes' => $reserved,
                'customer_charged_minutes' => $charged,
                'billable_minutes' => $billable,
                'non_billable_minutes' => $nonBillable,
                'total_worked_minutes' => $billable + $nonBillable,
                'available_minutes' => $available,
            ];
        })->values();
        $keys = ['provided_minutes', 'reserved_minutes', 'customer_charged_minutes', 'billable_minutes',
            'non_billable_minutes', 'total_worked_minutes', 'available_minutes'];
        $totals = [];
        foreach ($keys as $key) {
            $totals[$key] = (int) $rows->sum($key);
        }

        /** @var array{provided_minutes: int, reserved_minutes: int, customer_charged_minutes: int, billable_minutes: int, non_billable_minutes: int, total_worked_minutes: int, available_minutes: int} $totals */
        return ['month' => $month, 'rows' => $rows, 'totals' => $totals];
    }
}
