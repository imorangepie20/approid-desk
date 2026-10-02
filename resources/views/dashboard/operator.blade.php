<x-layouts::app title="운영 대시보드">
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6">
        <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold tracking-[0.18em] text-cyan-700 uppercase dark:text-cyan-300">Operations overview</p>
                <h1 class="mt-2 text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">운영 대시보드</h1>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">접수 현황과 즉시 확인할 요청을 한곳에서 확인합니다.</p>
            </div>
            <p class="font-mono text-sm text-zinc-500 dark:text-zinc-400">기준일 {{ $asOf }}</p>
        </header>

        <section aria-labelledby="operation-metrics-heading">
            <h2 id="operation-metrics-heading" class="sr-only">운영 현황</h2>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-dashboard.stat-card
                    title="활성 고객사"
                    :value="$metrics['activeCompanies']"
                    description="현재 서비스를 이용 중인 고객사"
                    icon="building-office-2"
                    data-test="metric-active-companies"
                />
                <x-dashboard.stat-card
                    title="신규 요청"
                    :value="$metrics['newRequests']"
                    description="접수 후 분석을 기다리는 요청"
                    icon="inbox-arrow-down"
                    tone="amber"
                    data-test="metric-new-requests"
                />
                <x-dashboard.stat-card
                    title="긴급 요청"
                    :value="$metrics['urgentRequests']"
                    description="완료되지 않은 긴급 요청"
                    icon="exclamation-triangle"
                    tone="red"
                    data-test="metric-urgent-requests"
                />
                <x-dashboard.stat-card
                    title="진행 중"
                    :value="$metrics['inProgressRequests']"
                    description="현재 개발이 진행 중인 요청"
                    icon="bolt"
                    tone="emerald"
                    data-test="metric-in-progress-requests"
                />
            </div>
        </section>

        <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="urgent-requests-heading">
            <div class="flex items-start justify-between gap-4 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700/80">
                <div>
                    <h2 id="urgent-requests-heading" class="font-semibold text-zinc-950 dark:text-white">우선 확인할 긴급 요청</h2>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">최근 접수 순으로 최대 5건을 표시합니다.</p>
                </div>
                <div class="flex shrink-0 flex-col items-end gap-2 sm:flex-row sm:items-center">
                    <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700 dark:bg-red-400/10 dark:text-red-300">
                        {{ number_format($metrics['urgentRequests']) }}건
                    </span>
                    <a href="{{ route('requests.index', ['priority' => \App\Enums\WorkRequestPriority::High->value]) }}" class="text-sm font-semibold text-cyan-700 hover:text-cyan-800 dark:text-cyan-300 dark:hover:text-cyan-200">전체 보기</a>
                </div>
            </div>

            @if ($urgentRequests->isEmpty())
                <div class="px-5 py-12 text-center" data-test="urgent-empty-state">
                    <div class="mx-auto grid size-11 place-items-center rounded-full bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300">
                        <flux:icon.check-circle class="size-5" />
                    </div>
                    <p class="mt-3 font-medium text-zinc-900 dark:text-white">확인할 긴급 요청이 없습니다.</p>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">새 긴급 요청이 접수되면 이곳에 표시됩니다.</p>
                </div>
            @else
                <ul class="divide-y divide-zinc-200 dark:divide-zinc-700/80" data-test="urgent-request-list">
                    @foreach ($urgentRequests as $workRequest)
                        <li class="flex flex-col gap-3 px-5 py-4 transition-colors hover:bg-zinc-50 dark:hover:bg-white/[0.03] sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex rounded bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-400/10 dark:text-red-300">긴급</span>
                                    <span class="inline-flex rounded bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">
                                        {{ $workRequest->status->label() }}
                                    </span>
                                </div>
                                <p class="mt-2 truncate font-medium text-zinc-950 dark:text-white">{{ $workRequest->title }}</p>
                                <p class="mt-1 truncate text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ $workRequest->company->name }} · {{ $workRequest->project->name }}
                                </p>
                            </div>
                            <div class="shrink-0 text-left sm:text-right">
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">요청일</p>
                                <p class="mt-1 font-mono text-sm text-zinc-700 dark:text-zinc-200">{{ $workRequest->requested_at->format('Y.m.d H:i') }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-layouts::app>
