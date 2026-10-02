<x-layouts::app title="고객 대시보드">
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6">
        <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold tracking-[0.18em] text-cyan-700 uppercase dark:text-cyan-300">Client workspace</p>
                <h1 class="mt-2 text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">{{ $companyName }} 대시보드</h1>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">프로젝트와 요청 진행 상황을 한곳에서 확인합니다.</p>
            </div>
            <p class="font-mono text-sm text-zinc-500 dark:text-zinc-400">기준일 {{ $asOf }}</p>
        </header>

        <section aria-labelledby="customer-metrics-heading">
            <h2 id="customer-metrics-heading" class="sr-only">고객 업무 현황</h2>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-dashboard.stat-card
                    title="활성 프로젝트"
                    :value="$metrics['activeProjects']"
                    description="현재 운영 중인 자사 프로젝트"
                    icon="folder-open"
                    data-test="metric-active-projects"
                />
                <x-dashboard.stat-card
                    title="진행 요청"
                    :value="$metrics['openRequests']"
                    description="완료·취소 전인 전체 요청"
                    icon="arrow-path"
                    tone="emerald"
                    data-test="metric-open-requests"
                />
                <x-dashboard.stat-card
                    title="승인 대기"
                    :value="$metrics['awaitingApprovalRequests']"
                    description="견적 확인과 승인이 필요한 요청"
                    icon="document-check"
                    tone="amber"
                    data-test="metric-awaiting-approval"
                />
                <x-dashboard.stat-card
                    title="검수 대기"
                    :value="$metrics['awaitingReviewRequests']"
                    description="결과 확인을 기다리는 요청"
                    icon="magnifying-glass-circle"
                    tone="red"
                    data-test="metric-awaiting-review"
                />
            </div>
        </section>

        <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
            <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="projects-heading">
                <div class="flex items-start justify-between gap-4 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700/80">
                    <div>
                        <h2 id="projects-heading" class="font-semibold text-zinc-950 dark:text-white">자사 프로젝트</h2>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">최근 변경된 순으로 최대 6개를 표시합니다.</p>
                    </div>
                    <a href="{{ route('projects.index') }}" class="shrink-0 text-sm font-semibold text-cyan-700 hover:text-cyan-800 dark:text-cyan-300 dark:hover:text-cyan-200">전체 보기</a>
                </div>

                @if ($projects->isEmpty())
                    <div class="px-5 py-12 text-center" data-test="project-empty-state">
                        <div class="mx-auto grid size-11 place-items-center rounded-full bg-cyan-50 text-cyan-700 dark:bg-cyan-400/10 dark:text-cyan-300">
                            <flux:icon.folder-plus class="size-5" />
                        </div>
                        <p class="mt-3 font-medium text-zinc-900 dark:text-white">등록된 프로젝트가 없습니다.</p>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">프로젝트가 등록되면 이곳에서 확인할 수 있습니다.</p>
                    </div>
                @else
                    <ul class="divide-y divide-zinc-200 dark:divide-zinc-700/80" data-test="project-list">
                        @foreach ($projects as $project)
                            @php
                                $projectStatusClass = match ($project->status) {
                                    \App\Enums\ProjectStatus::Active => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300',
                                    \App\Enums\ProjectStatus::OnHold => 'bg-amber-50 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300',
                                    \App\Enums\ProjectStatus::Archived => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300',
                                };
                            @endphp
                            <li>
                                <a href="{{ route('projects.show', $project) }}" class="block px-5 py-4 transition-colors hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="min-w-0">
                                        <p class="truncate font-medium text-zinc-950 dark:text-white">{{ $project->name }}</p>
                                        <p class="mt-1 line-clamp-2 text-sm text-zinc-500 dark:text-zinc-400">
                                            {{ $project->description ?: '프로젝트 설명이 아직 등록되지 않았습니다.' }}
                                        </p>
                                    </div>
                                    <span class="inline-flex shrink-0 rounded px-2 py-0.5 text-xs font-semibold {{ $projectStatusClass }}">
                                        {{ $project->status->label() }}
                                    </span>
                                </div>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="recent-requests-heading">
                <div class="flex items-start justify-between gap-4 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700/80">
                    <div>
                        <h2 id="recent-requests-heading" class="font-semibold text-zinc-950 dark:text-white">최근 요청</h2>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">요청일 기준 최신 8건을 표시합니다.</p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-2 sm:flex-row sm:items-center">
                        <span class="inline-flex items-center rounded-full bg-cyan-50 px-2.5 py-1 text-xs font-semibold text-cyan-700 dark:bg-cyan-400/10 dark:text-cyan-300">
                            {{ number_format($recentRequests->count()) }}건
                        </span>
                        <a href="{{ route('requests.index') }}" class="text-sm font-semibold text-cyan-700 hover:text-cyan-800 dark:text-cyan-300 dark:hover:text-cyan-200">전체 보기</a>
                    </div>
                </div>

                @if ($recentRequests->isEmpty())
                    <div class="px-5 py-12 text-center" data-test="request-empty-state">
                        <div class="mx-auto grid size-11 place-items-center rounded-full bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300">
                            <flux:icon.check-circle class="size-5" />
                        </div>
                        <p class="mt-3 font-medium text-zinc-900 dark:text-white">등록된 요청이 없습니다.</p>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">새 요청이 접수되면 진행 상태가 이곳에 표시됩니다.</p>
                    </div>
                @else
                    <ul class="divide-y divide-zinc-200 dark:divide-zinc-700/80" data-test="recent-request-list">
                        @foreach ($recentRequests as $workRequest)
                            <li class="flex flex-col gap-3 px-5 py-4 transition-colors hover:bg-zinc-50 dark:hover:bg-white/[0.03] sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if ($workRequest->is_urgent)
                                            <span class="inline-flex rounded bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-400/10 dark:text-red-300">긴급</span>
                                        @endif
                                        <span class="inline-flex rounded bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">
                                            {{ $workRequest->status->label() }}
                                        </span>
                                    </div>
                                    <p class="mt-2 truncate font-medium text-zinc-950 dark:text-white">{{ $workRequest->title }}</p>
                                    <p class="mt-1 truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $workRequest->project->name }}</p>
                                </div>
                                <div class="shrink-0 text-left sm:text-right">
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400">요청일</p>
                                    <p class="mt-1 font-mono text-sm text-zinc-700 dark:text-zinc-200">{{ $workRequest->requested_at->format('Y.m.d') }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
</x-layouts::app>
