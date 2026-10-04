<?php

namespace App\Services;

use App\Enums\TimeLedgerType;
use App\Models\ContractMonth;
use App\Models\TimeLedgerEntry;
use App\Models\WorkRequest;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class MonthTransitionCandidates
{
    public const NOTICE_LEAD_DAYS = 3;

    /**
     * @return Collection<int, array{month: ContractMonth, request: WorkRequest, reserve_entry_id: int, remaining_minutes: int}>
     */
    public function get(CarbonInterface $asOf): Collection
    {
        $date = CarbonImmutable::instance($asOf)->startOfDay();
        $months = ContractMonth::query()->where('status', 'open')
            ->whereDate('month', '<=', $date->startOfMonth())->orderBy('id')->get()
            ->filter(fn (ContractMonth $month): bool => $month->month->copy()->endOfMonth()->lte($date->addDays(self::NOTICE_LEAD_DAYS)->endOfDay()));

        return $months->flatMap(function (ContractMonth $month) use ($date): Collection {
            $requestIds = $month->entries()->whereNotNull('work_request_id')
                ->whereIn('type', [TimeLedgerType::Reserve->value, TimeLedgerType::Release->value])
                ->distinct()->pluck('work_request_id');
            $requests = WorkRequest::query()->whereIn('id', $requestIds)->orderBy('id')->get();

            return $requests->map(fn (WorkRequest $request): ?array => $this->for($request, $month, $date))
                ->filter()->values();
        })->values();
    }

    /**
     * @return array{month: ContractMonth, request: WorkRequest, reserve_entry_id: int, remaining_minutes: int}|null
     */
    public function for(WorkRequest $request, ContractMonth $month, CarbonInterface $asOf, bool $lock = false): ?array
    {
        $date = CarbonImmutable::instance($asOf)->startOfDay();
        if ($month->status !== 'open' || $request->status->isTerminal()
            || $request->company_id !== $month->company_id
            || $request->service_contract_id !== $month->service_contract_id
            || $month->month->copy()->endOfMonth()->gt($date->addDays(self::NOTICE_LEAD_DAYS)->endOfDay())) {
            return null;
        }
        $query = TimeLedgerEntry::query()->where('company_id', $request->company_id)
            ->where('contract_month_id', $month->id)->where('work_request_id', $request->id)
            ->whereIn('type', [TimeLedgerType::Reserve->value, TimeLedgerType::Release->value])->orderBy('id');
        $entries = $lock ? $query->lockForUpdate()->get() : $query->get();
        $remaining = $entries->sum(fn (TimeLedgerEntry $entry): int => $entry->type === TimeLedgerType::Reserve
            ? $entry->minutes : -$entry->minutes);
        $reserveEntryId = $entries->where('type', TimeLedgerType::Reserve)->max('id');

        return $remaining > 0 && is_int($reserveEntryId)
            ? ['month' => $month, 'request' => $request, 'reserve_entry_id' => $reserveEntryId, 'remaining_minutes' => $remaining]
            : null;
    }
}
