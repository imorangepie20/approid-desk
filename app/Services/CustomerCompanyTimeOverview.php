<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ContractMonth;
use App\Models\ServiceContract;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

class CustomerCompanyTimeOverview
{
    /**
     * @return array{
     *   company: Company,
     *   month: CarbonImmutable,
     *   rows: Collection<int, array{contract: ServiceContract, provided_minutes: int, reserved_minutes: int, customer_charged_minutes: int, available_minutes: int}>,
     *   totals: array{provided_minutes: int, reserved_minutes: int, customer_charged_minutes: int, available_minutes: int}
     * }
     */
    public function forMonth(User $user, CarbonInterface $asOf): array
    {
        $user = User::query()->findOrFail($user->id);
        if (! $user->is_active || $user->role->isSystemRole() || $user->company_id === null) {
            throw new AuthorizationException;
        }

        $company = Company::query()->active()->find($user->company_id);
        if (! $company instanceof Company) {
            throw new AuthorizationException;
        }

        $month = CarbonImmutable::instance($asOf)->startOfMonth();
        $ledgerTotals = new ContractMonthTotals;
        $rows = ContractMonth::query()
            ->where('company_id', $company->id)
            ->whereDate('month', $month)
            ->with(['entries', 'serviceContract'])
            ->orderBy('service_contract_id')
            ->get()
            ->map(function (ContractMonth $contractMonth) use ($ledgerTotals): array {
                $totals = $ledgerTotals->calculate($contractMonth->entries);

                return [
                    'contract' => $contractMonth->serviceContract,
                    'provided_minutes' => $totals['provided'],
                    'reserved_minutes' => $totals['remaining_reserved'],
                    'customer_charged_minutes' => $totals['net_usage'],
                    'available_minutes' => $totals['available'],
                ];
            })
            ->values();

        $totals = [
            'provided_minutes' => (int) $rows->sum('provided_minutes'),
            'reserved_minutes' => (int) $rows->sum('reserved_minutes'),
            'customer_charged_minutes' => (int) $rows->sum('customer_charged_minutes'),
            'available_minutes' => (int) $rows->sum('available_minutes'),
        ];

        return ['company' => $company, 'month' => $month, 'rows' => $rows, 'totals' => $totals];
    }
}
