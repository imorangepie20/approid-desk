<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\TimeLedgerType;
use App\Enums\WorkLogStatus;
use App\Models\Company;
use App\Models\ContractMonth;
use App\Models\TimeLedgerEntry;
use App\Models\User;
use App\Models\WorkLog;
use App\Services\ContractMonthTotals;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MonthlyUsageController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m', 'regex:/^[1-9][0-9]{3}-(0[1-9]|1[0-2])$/'],
            'company_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $selectedMonth = $data['month'] ?? today()->format('Y-m');
        $company = isset($data['company_id'])
            ? Company::query()->visibleTo($actor)->findOrFail((int) $data['company_id']) : null;
        $months = ContractMonth::query()->visibleTo($actor)
            ->whereDate('month', $selectedMonth.'-01')
            ->when($company !== null, fn ($query) => $query->where('company_id', $company->id))
            ->with('serviceContract.company')->orderBy('company_id')->orderBy('service_contract_id')
            ->paginate(15)->withQueryString();

        return view('usage.index', [
            'months' => $months,
            'totalsByMonth' => $this->totalsFor($months->getCollection()->modelKeys()),
            'selectedMonth' => $selectedMonth,
            'selectedCompany' => $company?->id,
            'companies' => $actor->role->isSystemRole()
                ? Company::query()->visibleTo($actor)->orderBy('name')->orderBy('id')->get(['id', 'name']) : collect(),
            'isOperator' => $actor->role->isSystemRole(),
        ]);
    }

    public function show(Request $request, ContractMonth $contractMonth): View
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('view', $contractMonth);
        $data = $this->detailFilters($request);
        $tab = $data['tab'] ?? 'usage';
        $type = $data['type'] ?? null;
        $isOperator = $actor->role->isSystemRole();
        $contractMonth->load('serviceContract.company');
        $logs = null;
        $entries = null;
        $cancelledLogIds = [];
        if ($tab === 'usage') {
            $logs = $this->workLogs($contractMonth, $isOperator)
                ->orderByDesc('worked_on')->orderByDesc('id')->paginate(20)->withQueryString();
            $cancelledLogIds = $contractMonth->entries()->where('type', TimeLedgerType::CancelUsage)
                ->where('source_type', 'work_log_usage_cancellation')->whereIn('source_id', $logs->getCollection()->modelKeys())
                ->pluck('source_id')->all();
        } else {
            $entries = $this->ledgerEntries($contractMonth, $isOperator, $type)
                ->orderByDesc('id')->paginate(20)->withQueryString();
        }

        return view('usage.show', [
            'contractMonth' => $contractMonth,
            'totals' => $this->totalsFor([$contractMonth->id])[$contractMonth->id],
            'isOperator' => $isOperator,
            'tab' => $tab, 'selectedType' => $type,
            'logs' => $logs, 'entries' => $entries, 'cancelledLogIds' => $cancelledLogIds,
        ]);
    }

    public function export(Request $request, ContractMonth $contractMonth): StreamedResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('view', $contractMonth);
        $data = $this->detailFilters($request);
        $tab = $data['tab'] ?? 'usage';
        $type = $data['type'] ?? null;
        $isOperator = $actor->role->isSystemRole();
        $contractMonth->load('serviceContract.company');
        $filename = 'monthly-'.$tab.'-'.$contractMonth->month->format('Y-m').'-'.$contractMonth->id.'.csv';

        return response()->streamDownload(function () use ($contractMonth, $tab, $type, $isOperator): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                throw new \RuntimeException('CSV output could not be opened.');
            }
            try {
                fwrite($stream, "\xEF\xBB\xBF");
                $context = [$contractMonth->month->format('Y-m'), $contractMonth->serviceContract->company->name,
                    $contractMonth->service_contract_id];
                $header = ['기준 월', '고객사', '계약 ID'];
                if ($tab === 'usage') {
                    $this->csvRow($stream, [...$header, '작업기록 ID', '요청 ID', '요청 제목', '작업일', '작업 내용',
                        '구분', '작업시간 (분)', '고객 차감 (분)', ...($isOperator ? ['작업자', '비차감 사유'] : [])]);
                    $this->workLogs($contractMonth, $isOperator)
                        ->chunkByIdDesc(500, function ($logs) use ($stream, $contractMonth, $context, $isOperator): void {
                            $cancelled = $contractMonth->entries()->where('type', TimeLedgerType::CancelUsage)
                                ->where('source_type', 'work_log_usage_cancellation')->whereIn('source_id', $logs->pluck('id'))
                                ->pluck('source_id')->all();
                            foreach ($logs as $log) {
                                $isCancelled = in_array($log->id, $cancelled, true);
                                $this->csvRow($stream, [...$context, $log->id, $log->work_request_id, $log->workRequest->title,
                                    $log->worked_on->toDateString(), $log->description,
                                    ! $log->is_billable ? '비차감' : ($isCancelled ? '사용 취소' : '고객 차감'),
                                    $log->minutes, $log->is_billable && ! $isCancelled ? $log->minutes : 0,
                                    ...($isOperator ? [$log->worker->name, $log->non_billable_reason ?? ''] : [])]);
                            }
                        });
                } else {
                    $this->csvRow($stream, [...$header, '원장 ID', '발생 시각', '발생 종류', '시간 (분)', '사용 가능 증감 (분)',
                        '요청 ID', '요청 제목', ...($isOperator ? ['처리자', '원인 유형', '원인 ID', '사유'] : [])]);
                    foreach ($this->ledgerEntries($contractMonth, $isOperator, $type)->lazyByIdDesc(500) as $entry) {
                        $this->csvRow($stream, [...$context, $entry->id, $entry->occurred_at->format('Y-m-d H:i:s'),
                            $entry->type->label(), $entry->minutes, $entry->minutes * $entry->type->availableSign(),
                            $entry->work_request_id ?? '', $entry->workRequest->title ?? '',
                            ...($isOperator ? [$entry->actor->name ?? '', $entry->source_type, $entry->source_id, $entry->reason ?? ''] : [])]);
                    }
                }
            } finally {
                fclose($stream);
            }
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff']);
    }

    /** @return array{tab?: string|null, type?: string|null, page?: mixed} */
    private function detailFilters(Request $request): array
    {
        return $request->validate([
            'tab' => ['nullable', Rule::in(['usage', 'ledger'])],
            'type' => ['nullable', Rule::enum(TimeLedgerType::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    /** @return Builder<WorkLog> */
    private function workLogs(ContractMonth $month, bool $isOperator): Builder
    {
        return WorkLog::query()->where('company_id', $month->company_id)->where('status', WorkLogStatus::Confirmed)
            ->whereBetween('worked_on', [$month->month->toDateString(), $month->month->copy()->endOfMonth()->toDateString()])
            ->whereHas('workRequest', fn ($query) => $query->where('service_contract_id', $month->service_contract_id))
            ->when(! $isOperator, fn ($query) => $query->where('is_billable', true))
            ->with($isOperator ? ['workRequest:id,title', 'worker:id,name'] : ['workRequest:id,title']);
    }

    /** @return Builder<TimeLedgerEntry> */
    private function ledgerEntries(ContractMonth $month, bool $isOperator, ?string $type): Builder
    {
        return TimeLedgerEntry::query()->where('contract_month_id', $month->id)
            ->when($type !== null, fn ($query) => $query->where('type', $type))
            ->with($isOperator ? ['workRequest:id,title', 'actor:id,name'] : ['workRequest:id,title']);
    }

    /**
     * @param  resource  $stream
     * @param  array<int, int|string>  $row
     */
    private function csvRow($stream, array $row): void
    {
        // Quote spreadsheet expressions in text cells; trusted numeric values stay numeric.
        $safe = array_map(static fn (int|string $cell): int|string => is_string($cell)
            && preg_match('/^(?:[\s\p{Z}\p{C}]*[=+@-]|[\t\r\n])/u', $cell) === 1 ? "'".$cell : $cell, $row);
        fputcsv($stream, $safe, ',', '"', '', "\r\n");
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $actor = User::query()->findOrFail($user->id);
        Gate::forUser($actor)->authorize(Permission::ViewUsage->value);

        return $actor;
    }

    /**
     * @param  array<int, int>  $monthIds  Authorized contract month IDs only.
     * @return array<int, array<string, int>>
     */
    private function totalsFor(array $monthIds): array
    {
        $groups = TimeLedgerEntry::query()->whereIn('contract_month_id', $monthIds)
            ->select(['contract_month_id', 'type'])->selectRaw('SUM(minutes) AS minutes')
            ->groupBy('contract_month_id', 'type')->get()->groupBy('contract_month_id');
        $calculator = new ContractMonthTotals;
        $totals = [];
        foreach ($monthIds as $id) {
            $totals[$id] = $calculator->calculate($groups->get($id, collect()));
        }

        return $totals;
    }
}
