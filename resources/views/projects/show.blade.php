<x-layouts::app :title="$project->name">
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6">
        <header>
            <a href="{{ route('projects.index') }}" class="inline-flex items-center gap-1 text-sm font-medium text-zinc-500 hover:text-cyan-700 dark:text-zinc-400 dark:hover:text-cyan-300">
                <flux:icon.chevron-left class="size-4" /> 프로젝트 목록
            </a>
            <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div class="min-w-0">
                    <p class="text-xs font-semibold tracking-[0.18em] text-cyan-700 uppercase dark:text-cyan-300">Project workspace</p>
                    <div class="mt-2 flex flex-wrap items-center gap-3">
                        <h1 class="break-words text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">{{ $project->name }}</h1>
                        <span @class([
                            'inline-flex shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold',
                            'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300' => $project->status === \App\Enums\ProjectStatus::Active,
                            'bg-amber-50 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300' => $project->status === \App\Enums\ProjectStatus::OnHold,
                            'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300' => $project->status === \App\Enums\ProjectStatus::Archived,
                        ])>{{ $project->status->label() }}</span>
                    </div>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $project->company->name }}</p>
                </div>
                @if ($project->site_url)
                    <a href="{{ $project->site_url }}" target="_blank" rel="noopener noreferrer" class="inline-flex w-fit items-center gap-2 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-semibold text-zinc-800 shadow-sm hover:border-cyan-500 hover:text-cyan-700 dark:border-zinc-600 dark:bg-[#141B2D] dark:text-zinc-100 dark:hover:border-cyan-400 dark:hover:text-cyan-300">
                        사이트 열기 <flux:icon.arrow-top-right-on-square class="size-4" />
                    </a>
                @endif
            </div>
        </header>

        <section aria-labelledby="project-metrics-heading">
            <h2 id="project-metrics-heading" class="sr-only">프로젝트 요청 현황</h2>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <x-dashboard.stat-card title="전체 요청" :value="$metrics['totalRequests']" description="프로젝트에 등록된 요청" icon="inbox-stack" data-test="metric-total-requests" />
                <x-dashboard.stat-card title="진행 요청" :value="$metrics['openRequests']" description="완료·취소 전인 요청" icon="arrow-path" tone="emerald" data-test="metric-open-requests" />
                <x-dashboard.stat-card title="완료 요청" :value="$metrics['completedRequests']" description="작업을 마친 요청" icon="check-circle" data-test="metric-completed-requests" />
                <x-dashboard.stat-card title="긴급 요청" :value="$metrics['urgentRequests']" description="처리가 필요한 긴급 요청" icon="exclamation-triangle" tone="red" data-test="metric-urgent-requests" />
            </div>
        </section>

        <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="space-y-6">
                <section class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="project-description-heading">
                    <h2 id="project-description-heading" class="font-semibold text-zinc-950 dark:text-white">프로젝트 개요</h2>
                    <p class="mt-4 whitespace-pre-line text-sm leading-6 text-zinc-700 dark:text-zinc-300">{{ $project->description ?: '프로젝트 설명이 아직 등록되지 않았습니다.' }}</p>
                </section>

                <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="recent-project-requests-heading">
                    <div class="flex items-start justify-between gap-4 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700/80">
                        <div>
                            <h2 id="recent-project-requests-heading" class="font-semibold text-zinc-950 dark:text-white">최근 요청</h2>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">요청일 기준 최신 10건을 표시합니다.</p>
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-2 sm:flex-row sm:items-center">
                            <span class="inline-flex items-center rounded-full bg-cyan-50 px-2.5 py-1 text-xs font-semibold text-cyan-700 dark:bg-cyan-400/10 dark:text-cyan-300">{{ number_format($metrics['totalRequests']) }}건</span>
                            <a href="{{ route('requests.index', ['project_id' => $project->id]) }}" class="text-sm font-semibold text-cyan-700 hover:text-cyan-800 dark:text-cyan-300 dark:hover:text-cyan-200">전체 보기</a>
                        </div>
                    </div>

                    @if ($recentRequests->isEmpty())
                        <div class="px-5 py-12 text-center" data-test="request-empty-state">
                            <div class="mx-auto grid size-11 place-items-center rounded-full bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300"><flux:icon.check-circle class="size-5" /></div>
                            <p class="mt-3 font-medium text-zinc-900 dark:text-white">등록된 요청이 없습니다.</p>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">새 요청이 접수되면 이곳에 표시됩니다.</p>
                        </div>
                    @else
                        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700/80" data-test="recent-request-list">
                            @foreach ($recentRequests as $workRequest)
                                <li class="px-5 py-4">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                @if ($workRequest->is_urgent)
                                                    <span class="inline-flex rounded bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-400/10 dark:text-red-300">긴급</span>
                                                @endif
                                                <span class="inline-flex rounded bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">{{ $workRequest->status->label() }}</span>
                                                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $workRequest->type->label() }} · {{ $workRequest->priority->label() }}</span>
                                            </div>
                                            <a href="{{ route('requests.show', $workRequest) }}" class="mt-2 block break-words font-medium text-zinc-950 hover:text-cyan-700 dark:text-white dark:hover:text-cyan-300">{{ $workRequest->title }}</a>
                                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">요청자 {{ $workRequest->submitter->name }}@if ($workRequest->assignee) · 담당자 {{ $workRequest->assignee->name }}@endif</p>
                                        </div>
                                        <time datetime="{{ $workRequest->requested_at->toDateString() }}" class="shrink-0 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $workRequest->requested_at->format('Y.m.d') }}</time>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>

            <aside class="space-y-6">
                <section class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="project-info-heading">
                    <h2 id="project-info-heading" class="font-semibold text-zinc-950 dark:text-white">운영 정보</h2>
                    <dl class="mt-4 divide-y divide-zinc-200 text-sm dark:divide-zinc-700/80">
                        <div class="flex items-start justify-between gap-4 py-3 first:pt-0"><dt class="text-zinc-500 dark:text-zinc-400">고객사</dt><dd class="text-right font-medium text-zinc-900 dark:text-zinc-100">{{ $project->company->name }}</dd></div>
                        <div class="flex items-start justify-between gap-4 py-3"><dt class="text-zinc-500 dark:text-zinc-400">상태</dt><dd class="font-medium text-zinc-900 dark:text-zinc-100">{{ $project->status->label() }}</dd></div>
                        <div class="flex items-start justify-between gap-4 py-3"><dt class="text-zinc-500 dark:text-zinc-400">사이트</dt><dd class="max-w-44 break-all text-right text-zinc-900 dark:text-zinc-100">@if ($project->site_url)<a href="{{ $project->site_url }}" target="_blank" rel="noopener noreferrer" class="text-cyan-700 hover:underline dark:text-cyan-300">{{ $project->site_url }}</a>@else 미등록 @endif</dd></div>
                        <div class="flex items-start justify-between gap-4 pt-3"><dt class="text-zinc-500 dark:text-zinc-400">최근 변경</dt><dd class="font-mono text-zinc-900 dark:text-zinc-100">{{ $project->updated_at?->format('Y.m.d') }}</dd></div>
                    </dl>
                </section>

                <section class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="handover-heading">
                    <h2 id="handover-heading" class="font-semibold text-zinc-950 dark:text-white">인수 자료</h2>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">기존 사이트와 운영 자료 확보 상태입니다.</p>
                    <ul class="mt-4 space-y-3 text-sm">
                        <li class="flex items-center gap-3">
                            @if ($project->is_existing_site)<flux:icon.check-circle class="size-5 shrink-0 text-emerald-600 dark:text-emerald-300" />@else<flux:icon.minus-circle class="size-5 shrink-0 text-zinc-400" />@endif
                            <span class="text-zinc-800 dark:text-zinc-200">{{ $project->is_existing_site ? '기존 사이트 있음' : '신규 구축 프로젝트' }}</span>
                        </li>
                        <li class="flex items-center gap-3">
                            @if ($project->source_code_secured)<flux:icon.check-circle class="size-5 shrink-0 text-emerald-600 dark:text-emerald-300" />@else<flux:icon.exclamation-circle class="size-5 shrink-0 text-amber-600 dark:text-amber-300" />@endif
                            <span class="text-zinc-800 dark:text-zinc-200">소스 코드 {{ $project->source_code_secured ? '확보' : '미확보' }}</span>
                        </li>
                        <li class="flex items-center gap-3">
                            @if ($project->database_dump_secured)<flux:icon.check-circle class="size-5 shrink-0 text-emerald-600 dark:text-emerald-300" />@else<flux:icon.exclamation-circle class="size-5 shrink-0 text-amber-600 dark:text-amber-300" />@endif
                            <span class="text-zinc-800 dark:text-zinc-200">DB 덤프 {{ $project->database_dump_secured ? '확보' : '미확보' }}</span>
                        </li>
                    </ul>
                </section>

                <section class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="technical-notes-heading">
                    <h2 id="technical-notes-heading" class="font-semibold text-zinc-950 dark:text-white">기술 메모</h2>
                    <p class="mt-3 whitespace-pre-line text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ $project->technical_notes ?: '등록된 기술 메모가 없습니다.' }}</p>
                </section>
            </aside>
        </div>
    </div>
</x-layouts::app>
