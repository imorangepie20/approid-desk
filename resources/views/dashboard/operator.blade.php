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

        <x-dashboard.major-incidents :requests="$majorIncidents" :count="$metrics['majorIncidents']" />

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
                    title="주요 장애"
                    :value="$metrics['majorIncidents']"
                    description="일반 요청보다 먼저 확인할 장애"
                    icon="exclamation-triangle"
                    tone="red"
                    data-test="metric-major-incidents"
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

        <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="company-time-heading" data-test="company-time-overview">
            <div class="flex flex-col gap-2 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700/80 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-semibold tracking-[0.16em] text-cyan-700 uppercase dark:text-cyan-300">Monthly capacity</p>
                    <h2 id="company-time-heading" class="mt-1 font-semibold text-zinc-950 dark:text-white">이번 달 고객별 시간 현황</h2>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">제공·예약·실제 고객 차감과 비차감 투입을 고객사별로 비교합니다.</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <p class="font-mono text-sm text-zinc-500 dark:text-zinc-400">{{ $companyTime['month']->format('Y.m') }}</p>
                    <a href="{{ route('usage.index', ['month' => $companyTime['month']->format('Y-m')]) }}" class="text-sm text-cyan-700 dark:text-cyan-300">월별 내역</a>
                </div>
            </div>

            <div class="grid border-b border-zinc-200 dark:border-zinc-700/80 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    ['label' => '제공 총계', 'key' => 'provided_minutes', 'tone' => 'text-cyan-700 dark:text-cyan-300'],
                    ['label' => '고객 차감', 'key' => 'customer_charged_minutes', 'tone' => 'text-amber-700 dark:text-amber-300'],
                    ['label' => '예약 중', 'key' => 'reserved_minutes', 'tone' => 'text-violet-700 dark:text-violet-300'],
                    ['label' => '사용 가능', 'key' => 'available_minutes', 'tone' => 'text-emerald-700 dark:text-emerald-300'],
                ] as $summary)
                    <div class="border-zinc-200 px-5 py-4 dark:border-zinc-700/80 sm:[&:not(:nth-child(2n+1))]:border-l xl:[&:not(:first-child)]:border-l">
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $summary['label'] }}</p>
                        <p class="mt-1 font-mono text-xl font-semibold {{ $summary['tone'] }}" data-test="company-time-total-{{ str_replace('_minutes', '', $summary['key']) }}">{{ number_format($companyTime['totals'][$summary['key']]) }}분</p>
                    </div>
                @endforeach
            </div>

            @if ($companyTime['rows']->isEmpty())
                <div class="px-5 py-12 text-center" data-test="company-time-empty">
                    <div class="mx-auto grid size-11 place-items-center rounded-full bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-300"><flux:icon.clock class="size-5" /></div>
                    <p class="mt-3 font-medium text-zinc-900 dark:text-white">표시할 활성 고객사가 없습니다.</p>
                </div>
            @else
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full min-w-[900px] text-left text-sm">
                        <thead class="bg-zinc-50 text-xs text-zinc-500 dark:bg-white/[0.025] dark:text-zinc-400">
                            <tr><th class="px-5 py-3 font-medium">고객사</th><th class="px-4 py-3 text-right font-medium">제공</th><th class="px-4 py-3 text-right font-medium">고객 차감</th><th class="px-4 py-3 text-right font-medium">예약 중</th><th class="px-4 py-3 text-right font-medium">비차감</th><th class="px-4 py-3 text-right font-medium">전체 투입</th><th class="px-5 py-3 text-right font-medium">사용 가능</th></tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700/80">
                            @foreach ($companyTime['rows'] as $row)
                                <tr data-test="company-time-row-{{ $row['company']->id }}" class="hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                                    <td class="px-5 py-4"><a href="{{ route('companies.show', $row['company']) }}" class="font-semibold text-zinc-950 hover:text-cyan-700 dark:text-white dark:hover:text-cyan-300">{{ $row['company']->name }}</a>@if ($row['month_count'] === 0)<span class="ml-2 inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-400/10 dark:text-amber-300">월 시간 미설정</span>@elseif ($row['month_count'] > 1)<span class="ml-2 text-xs text-zinc-500">계약 {{ $row['month_count'] }}건</span>@endif</td>
                                    <td class="px-4 py-4 text-right font-mono">{{ number_format($row['provided_minutes']) }}분</td>
                                    <td class="px-4 py-4 text-right font-mono">{{ number_format($row['customer_charged_minutes']) }}분</td>
                                    <td class="px-4 py-4 text-right font-mono">{{ number_format($row['reserved_minutes']) }}분</td>
                                    <td class="px-4 py-4 text-right font-mono text-amber-700 dark:text-amber-300">{{ number_format($row['non_billable_minutes']) }}분</td>
                                    <td class="px-4 py-4 text-right font-mono">{{ number_format($row['total_worked_minutes']) }}분</td>
                                    <td class="px-5 py-4 text-right font-mono font-semibold text-emerald-700 dark:text-emerald-300">{{ number_format($row['available_minutes']) }}분</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="divide-y divide-zinc-200 dark:divide-zinc-700/80 md:hidden">
                    @foreach ($companyTime['rows'] as $row)
                        <article class="px-5 py-4" data-test="company-time-card-{{ $row['company']->id }}">
                            <div class="flex items-start justify-between gap-3"><a href="{{ route('companies.show', $row['company']) }}" class="font-semibold text-zinc-950 dark:text-white">{{ $row['company']->name }}</a>@if ($row['month_count'] === 0)<span class="shrink-0 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-400/10 dark:text-amber-300">월 시간 미설정</span>@endif</div>
                            <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                                <div><dt class="text-xs text-zinc-500">제공 / 사용 가능</dt><dd class="mt-1 font-mono">{{ number_format($row['provided_minutes']) }}분 / <span class="text-emerald-700 dark:text-emerald-300">{{ number_format($row['available_minutes']) }}분</span></dd></div>
                                <div><dt class="text-xs text-zinc-500">고객 차감 / 예약</dt><dd class="mt-1 font-mono">{{ number_format($row['customer_charged_minutes']) }}분 / {{ number_format($row['reserved_minutes']) }}분</dd></div>
                                <div><dt class="text-xs text-zinc-500">전체 투입</dt><dd class="mt-1 font-mono">{{ number_format($row['total_worked_minutes']) }}분</dd></div>
                                <div><dt class="text-xs text-zinc-500">비차감</dt><dd class="mt-1 font-mono text-amber-700 dark:text-amber-300">{{ number_format($row['non_billable_minutes']) }}분</dd></div>
                            </dl>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <div class="grid items-start gap-6 xl:grid-cols-2">
            <x-dashboard.pending-requests title="승인 대기" :status="\App\Enums\WorkRequestStatus::AwaitingApproval" :requests="$awaitingApprovalRequests" :count="$metrics['awaitingApprovalRequests']" />
            <x-dashboard.pending-requests title="검수 대기" :status="\App\Enums\WorkRequestStatus::AwaitingReview" :requests="$awaitingReviewRequests" :count="$metrics['awaitingReviewRequests']" />
        </div>

    </div>
</x-layouts::app>
