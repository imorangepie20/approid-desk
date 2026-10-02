<x-layouts::app title="프로젝트">
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6">
        <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold tracking-[0.18em] text-cyan-700 uppercase dark:text-cyan-300">Project directory</p>
                <h1 class="mt-2 text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">프로젝트</h1>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">고객사별 프로젝트 정보와 요청 현황을 확인합니다.</p>
            </div>
            <span class="inline-flex w-fit items-center rounded-full bg-cyan-50 px-3 py-1.5 text-sm font-semibold text-cyan-700 dark:bg-cyan-400/10 dark:text-cyan-300">
                전체 {{ number_format($projects->total()) }}개
            </span>
        </header>

        <form method="GET" action="{{ route('projects.index') }}" @class([
            'grid gap-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D] sm:items-end',
            'sm:grid-cols-[minmax(0,1fr)_11rem_14rem_auto]' => $canFilterCompanies,
            'sm:grid-cols-[minmax(0,1fr)_11rem_auto]' => ! $canFilterCompanies,
        ])>
            <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                검색
                <input name="search" value="{{ $search }}" placeholder="프로젝트명, 설명, 고객사" autocomplete="off" class="h-10 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none placeholder:text-zinc-400 focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
            </label>
            <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                상태
                <select name="status" class="h-10 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">
                    <option value="">전체</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected($selectedStatus === $status->value)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </label>
            @if ($canFilterCompanies)
                <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                    고객사
                    <select name="company_id" class="h-10 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">
                        <option value="">전체</option>
                        @foreach ($companies as $company)
                            <option value="{{ $company->id }}" @selected($selectedCompanyId === $company->id)>{{ $company->name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <div class="flex gap-2">
                <flux:button type="submit" variant="primary" class="flex-1">조회</flux:button>
                @if ($search !== '' || $selectedStatus !== '' || ($canFilterCompanies && $selectedCompanyId > 0))
                    <flux:button :href="route('projects.index')" variant="ghost">초기화</flux:button>
                @endif
            </div>
        </form>

        <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="project-list-heading">
            <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-700/80">
                <h2 id="project-list-heading" class="font-semibold text-zinc-950 dark:text-white">프로젝트 목록</h2>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">프로젝트를 선택하면 운영 정보와 최근 요청을 확인할 수 있습니다.</p>
            </div>

            @if ($projects->isEmpty())
                <div class="px-5 py-14 text-center" data-test="project-empty-state">
                    <div class="mx-auto grid size-11 place-items-center rounded-full bg-cyan-50 text-cyan-700 dark:bg-cyan-400/10 dark:text-cyan-300">
                        <flux:icon.folder-plus class="size-5" />
                    </div>
                    <p class="mt-3 font-medium text-zinc-900 dark:text-white">조건에 맞는 프로젝트가 없습니다.</p>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">검색어나 필터 조건을 바꿔 다시 확인해 주세요.</p>
                </div>
            @else
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full text-left text-sm" data-test="project-table">
                        <thead class="bg-zinc-50 text-xs font-semibold tracking-wide text-zinc-500 uppercase dark:bg-white/[0.03] dark:text-zinc-400">
                            <tr>
                                <th class="px-5 py-3">프로젝트</th>
                                <th class="px-5 py-3">고객사</th>
                                <th class="px-5 py-3">상태</th>
                                <th class="px-5 py-3 text-right">요청</th>
                                <th class="px-5 py-3 text-right">최근 변경</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700/80">
                            @foreach ($projects as $project)
                                @php
                                    $statusClass = match ($project->status) {
                                        \App\Enums\ProjectStatus::Active => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300',
                                        \App\Enums\ProjectStatus::OnHold => 'bg-amber-50 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300',
                                        \App\Enums\ProjectStatus::Archived => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300',
                                    };
                                @endphp
                                <tr class="transition-colors hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                                    <td class="max-w-md px-5 py-4">
                                        <a href="{{ route('projects.show', $project) }}" class="font-semibold text-zinc-950 hover:text-cyan-700 dark:text-white dark:hover:text-cyan-300">{{ $project->name }}</a>
                                        <p class="mt-1 line-clamp-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $project->description ?: '설명이 등록되지 않았습니다.' }}</p>
                                    </td>
                                    <td class="px-5 py-4 text-zinc-700 dark:text-zinc-200">{{ $project->company->name }}</td>
                                    <td class="px-5 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClass }}">{{ $project->status->label() }}</span></td>
                                    <td class="px-5 py-4 text-right font-mono text-zinc-700 dark:text-zinc-200">{{ number_format($project->work_requests_count) }}</td>
                                    <td class="px-5 py-4 text-right font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $project->updated_at?->format('Y.m.d') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <ul class="divide-y divide-zinc-200 dark:divide-zinc-700/80 md:hidden" data-test="project-card-list">
                    @foreach ($projects as $project)
                        @php
                            $statusClass = match ($project->status) {
                                \App\Enums\ProjectStatus::Active => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300',
                                \App\Enums\ProjectStatus::OnHold => 'bg-amber-50 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300',
                                \App\Enums\ProjectStatus::Archived => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300',
                            };
                        @endphp
                        <li>
                            <a href="{{ route('projects.show', $project) }}" class="block px-5 py-4 transition-colors hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="truncate font-semibold text-zinc-950 dark:text-white">{{ $project->name }}</p>
                                        <p class="mt-1 truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $project->company->name }}</p>
                                    </div>
                                    <span class="inline-flex shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClass }}">{{ $project->status->label() }}</span>
                                </div>
                                <p class="mt-3 line-clamp-2 text-sm text-zinc-600 dark:text-zinc-400">{{ $project->description ?: '설명이 등록되지 않았습니다.' }}</p>
                                <div class="mt-4 flex items-center justify-between text-xs text-zinc-500 dark:text-zinc-400">
                                    <span>요청 <strong class="font-mono text-zinc-800 dark:text-zinc-200">{{ $project->work_requests_count }}</strong>건</span>
                                    <span class="font-mono">{{ $project->updated_at?->format('Y.m.d') }}</span>
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{ $projects->links() }}
    </div>
</x-layouts::app>
