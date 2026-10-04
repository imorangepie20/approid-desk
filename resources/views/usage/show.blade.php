<x-layouts::app title="월 사용내역 상세">
    <div class="mx-auto w-full max-w-6xl space-y-6">
        <header>
            <a href="{{ route('usage.index', ['month' => $contractMonth->month->format('Y-m'), 'company_id' => $isOperator ? $contractMonth->company_id : null]) }}" class="inline-flex items-center gap-1 text-sm text-cyan-700 dark:text-cyan-300"><flux:icon.chevron-left class="size-4" />월별 계약 목록</a>
            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-2xl font-bold">{{ $contractMonth->month->format('Y년 n월') }} 사용내역</h1>
                <span class="rounded px-2 py-1 text-xs {{ $contractMonth->status === 'closed' ? 'bg-zinc-100 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200' : 'bg-emerald-50 text-emerald-800 dark:bg-emerald-400/10 dark:text-emerald-300' }}">{{ $contractMonth->status === 'closed' ? '마감' : '진행 중' }}</span>
            </div>
            <p class="mt-2 break-words text-sm text-zinc-500">{{ $contractMonth->serviceContract->company->name }} · {{ $contractMonth->serviceContract->type->label() }} · 계약 #{{ $contractMonth->service_contract_id }}</p>
            @if ($contractMonth->closed_at)<p class="mt-1 text-xs text-zinc-500">마감 시각 {{ $contractMonth->closed_at->format('Y.m.d H:i') }}</p>@endif
        </header>
        @can('close', $contractMonth)
            <flux:button href="{{ route('usage.manage', $contractMonth) }}" icon="lock-closed">월 마감·조정</flux:button>
        @endcan
        @if ($errors->any())
            <div role="alert" class="text-sm text-red-600 dark:text-red-300"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <section aria-labelledby="usage-totals-heading">
            <h2 id="usage-totals-heading" class="text-sm font-semibold">현재 시간 현황</h2>
            <dl class="mt-3 grid grid-cols-2 gap-4 border-y border-zinc-200 py-4 dark:border-zinc-700 lg:grid-cols-4">
                @foreach (['provided' => '제공', 'net_usage' => '고객 차감', 'remaining_reserved' => '예약 중', 'available' => '사용 가능'] as $key => $label)
                    <div class="min-w-0"><dt class="text-xs text-zinc-500">{{ $label }}</dt><dd class="mt-1 break-words font-mono text-xl font-semibold {{ $key === 'available' ? 'text-emerald-700 dark:text-emerald-300' : '' }}" data-test="usage-total-{{ $key }}">{{ number_format($totals[$key]) }}분</dd></div>
                @endforeach
            </dl>
            <dl class="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm text-zinc-500">
                <div class="flex gap-2"><dt>조정 증가</dt><dd class="font-mono" data-test="usage-adjust-increase">+{{ number_format($totals['adjust_increase']) }}분</dd></div>
                <div class="flex gap-2"><dt>조정 감소</dt><dd class="font-mono" data-test="usage-adjust-decrease">-{{ number_format($totals['adjust_decrease']) }}분</dd></div>
            </dl>
        </section>
        <nav aria-label="월 내역 보기" class="flex gap-5 border-b border-zinc-200 dark:border-zinc-700">
            @foreach (['usage' => '사용내역', 'ledger' => '시간 원장'] as $key => $label)
                <a href="{{ route('usage.show', [$contractMonth, 'tab' => $key]) }}" @if ($tab === $key) aria-current="page" @endif class="border-b-2 px-1 py-3 text-sm font-semibold {{ $tab === $key ? 'border-cyan-600 text-cyan-700 dark:text-cyan-300' : 'border-transparent text-zinc-500' }}">{{ $label }}</a>
            @endforeach
        </nav>
        <div class="flex justify-end">
            <flux:button href="{{ route('usage.export', [$contractMonth, 'tab' => $tab, 'type' => $tab === 'ledger' ? $selectedType : null]) }}" icon="arrow-down-tray" data-test="usage-csv-download">CSV 다운로드</flux:button>
        </div>
        @if ($tab === 'usage')
            <section aria-label="확정 작업 내역" class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($logs as $log)
                    @php($cancelled = in_array($log->id, $cancelledLogIds))
                    <article class="space-y-3 py-4" data-test="usage-log-{{ $log->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                            <time datetime="{{ $log->worked_on->toDateString() }}" class="font-mono">{{ $log->worked_on->format('Y.m.d') }}</time>
                            <span class="font-medium">{{ ! $log->is_billable ? '비차감' : ($cancelled ? '사용 취소' : '고객 차감') }} · {{ number_format($log->is_billable && ! $cancelled ? $log->minutes : 0) }}분</span>
                        </div>
                        <h2 class="break-words text-sm font-semibold"><a href="{{ route('requests.show', $log->work_request_id) }}" class="text-cyan-700 dark:text-cyan-300">#{{ $log->work_request_id }} {{ $log->workRequest->title }}</a></h2>
                        <p class="whitespace-pre-wrap break-words text-sm">{{ $log->description }}</p>
                        <p class="text-xs text-zinc-500">{{ $cancelled ? '취소 전 차감' : '작업시간' }} {{ number_format($log->minutes) }}분@if ($isOperator) · {{ $log->worker->name }}@endif</p>
                        @if ($isOperator && ! $log->is_billable)<p class="whitespace-pre-wrap break-words text-sm text-zinc-500">비차감 사유: {{ $log->non_billable_reason }}</p>@endif
                    </article>
                @empty
                    <p class="py-12 text-center text-sm text-zinc-500" data-test="usage-logs-empty">이 월에 확정된 {{ $isOperator ? '작업' : '차감 작업' }} 내역이 없습니다.</p>
                @endforelse
            </section>
            {{ $logs->links() }}
        @else
            <form method="GET" action="{{ route('usage.show', $contractMonth) }}" class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="tab" value="ledger" />
                <label class="grid min-w-0 gap-2 text-sm">발생 종류
                    <select name="type" class="min-w-40 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">
                        <option value="">전체</option>
                        @foreach (\App\Enums\TimeLedgerType::cases() as $type)<option value="{{ $type->value }}" @selected($selectedType === $type->value)>{{ $type->label() }}</option>@endforeach
                    </select>
                </label>
                <flux:button type="submit" icon="funnel">조회</flux:button>
            </form>
            <section aria-label="시간 원장 내역" class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($entries as $entry)
                    <article class="space-y-3 py-4" data-test="usage-ledger-{{ $entry->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                            <h2 class="font-semibold">{{ $entry->type->label() }} · {{ number_format($entry->minutes) }}분</h2>
                            <p class="text-xs text-zinc-500">사용 가능 <span class="font-mono {{ $entry->type->availableSign() > 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300' }}">{{ $entry->type->availableSign() > 0 ? '+' : '-' }}{{ number_format($entry->minutes) }}분</span></p>
                        </div>
                        @if ($entry->workRequest)
                            <p class="break-words text-sm"><a href="{{ route('requests.show', $entry->work_request_id) }}" class="text-cyan-700 dark:text-cyan-300">#{{ $entry->work_request_id }} {{ $entry->workRequest->title }}</a></p>
                        @endif
                        <p class="text-xs text-zinc-500"><time datetime="{{ $entry->occurred_at->toAtomString() }}">{{ $entry->occurred_at->format('Y.m.d H:i') }}</time> · 원장 #{{ $entry->id }}</p>
                        @if ($isOperator)
                            @php($sourceLabel = match ($entry->source_type) {
                                'contract_month' => '계약 월', 'estimate_approval' => '견적 승인',
                                'estimate_replacement' => '견적 교체 승인', 'work_log' => '작업기록',
                                'work_log_usage_cancellation' => '작업기록 사용 취소', 'work_request_status_change' => '요청 상태 변경',
                                'month_adjustment' => '월 조정', 'month_transition_notice' => '월 전환', default => '기타 원인 자료',
                            })
                            <p class="break-words text-xs text-zinc-500">{{ $entry->actor?->name }} · {{ $sourceLabel }} #{{ $entry->source_id }}</p>
                            <p class="whitespace-pre-wrap break-words text-sm">{{ $entry->reason }}</p>
                            @can('adjust', $contractMonth)
                                <flux:button size="sm" href="{{ route('usage.adjust.create', [$contractMonth, $entry]) }}" icon="adjustments-horizontal">시간 조정</flux:button>
                            @endcan
                        @endif
                    </article>
                @empty
                    <p class="py-12 text-center text-sm text-zinc-500" data-test="usage-ledger-empty">해당 종류의 원장 내역이 없습니다.</p>
                @endforelse
            </section>
            {{ $entries->links() }}
        @endif
    </div>
</x-layouts::app>
