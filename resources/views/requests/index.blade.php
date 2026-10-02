<x-layouts::app title="요청">
    @php
        $hasFilters = $search !== ''
            || $selectedStatus !== ''
            || $selectedType !== ''
            || $selectedPriority !== ''
            || ($canFilterCompanies && $selectedCompanyId > 0)
            || $selectedProjectId > 0
            || $requestedFrom !== ''
            || $requestedTo !== '';
    @endphp

    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6">
        <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold tracking-[0.18em] text-cyan-700 uppercase dark:text-cyan-300">Request queue</p>
                <h1 class="mt-2 text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">요청</h1>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">접수된 업무를 조건별로 좁혀 우선순위와 진행 상태를 확인합니다.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex w-fit items-center rounded-full bg-cyan-50 px-3 py-1.5 text-sm font-semibold text-cyan-700 dark:bg-cyan-400/10 dark:text-cyan-300">검색 결과 {{ number_format($workRequests->total()) }}건</span>
                @can('create', \App\Models\WorkRequest::class)
                    <flux:button :href="route('requests.create')" variant="primary">요청 등록</flux:button>
                @endcan
            </div>
        </header>

        <form method="GET" action="{{ route('requests.index') }}" class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200 sm:col-span-2">
                    검색
                    <input name="search" value="{{ $search }}" placeholder="요청 제목, 내용, 고객사, 프로젝트" autocomplete="off" class="h-10 min-w-0 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none placeholder:text-zinc-400 focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                </label>

                <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                    상태
                    <select name="status" class="h-10 min-w-0 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">
                        <option value="">전체 상태</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected($selectedStatus === $status->value)>{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                    유형
                    <select name="type" class="h-10 min-w-0 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">
                        <option value="">전체 유형</option>
                        @foreach ($types as $type)
                            <option value="{{ $type->value }}" @selected($selectedType === $type->value)>{{ $type->label() }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                    우선순위
                    <select name="priority" class="h-10 min-w-0 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">
                        <option value="">전체 우선순위</option>
                        @foreach ($priorities as $priority)
                            <option value="{{ $priority->value }}" @selected($selectedPriority === $priority->value)>{{ $priority->label() }}</option>
                        @endforeach
                    </select>
                </label>

                @if ($canFilterCompanies)
                    <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                        고객사
                        <select name="company_id" class="h-10 min-w-0 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">
                            <option value="">전체 고객사</option>
                            @foreach ($companies as $company)
                                <option value="{{ $company->id }}" @selected($selectedCompanyId === $company->id)>{{ $company->name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif

                <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                    프로젝트
                    <select name="project_id" class="h-10 min-w-0 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">
                        <option value="">전체 프로젝트</option>
                        @foreach ($projects as $project)
                            <option value="{{ $project->id }}" @selected($selectedProjectId === $project->id)>{{ $project->name }}</option>
                        @endforeach
                    </select>
                </label>

                <fieldset class="grid gap-2 sm:col-span-2">
                    <legend class="text-sm font-medium text-zinc-800 dark:text-zinc-200">접수일</legend>
                    <div class="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-2">
                        <label class="sr-only" for="requested-from">접수 시작일</label>
                        <input id="requested-from" type="date" name="requested_from" value="{{ $requestedFrom }}" class="h-10 min-w-0 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                        <span class="text-sm text-zinc-400" aria-hidden="true">–</span>
                        <label class="sr-only" for="requested-to">접수 종료일</label>
                        <input id="requested-to" type="date" name="requested_to" value="{{ $requestedTo }}" class="h-10 min-w-0 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                    </div>
                </fieldset>
            </div>

            <div class="mt-4 flex flex-col-reverse gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700/80 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                    @if ($hasFilters)
                        선택한 조건이 검색 결과와 페이지 이동에 유지됩니다.
                    @else
                        최신 접수 순으로 전체 요청을 표시합니다.
                    @endif
                </p>
                <div class="flex gap-2">
                    @if ($hasFilters)
                        <flux:button :href="route('requests.index')" variant="ghost">초기화</flux:button>
                    @endif
                    <flux:button type="submit" variant="primary" class="flex-1 sm:flex-none">조회</flux:button>
                </div>
            </div>
        </form>

        <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="request-list-heading">
            <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-700/80">
                <h2 id="request-list-heading" class="font-semibold text-zinc-950 dark:text-white">요청 목록</h2>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">상태와 우선순위를 확인하고 처리할 업무를 찾을 수 있습니다.</p>
            </div>

            @if ($workRequests->isEmpty())
                <div class="px-5 py-14 text-center" data-test="request-empty-state">
                    <div class="mx-auto grid size-11 place-items-center rounded-full bg-cyan-50 text-cyan-700 dark:bg-cyan-400/10 dark:text-cyan-300">
                        <flux:icon.inbox class="size-5" />
                    </div>
                    <p class="mt-3 font-medium text-zinc-900 dark:text-white">조건에 맞는 요청이 없습니다.</p>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">검색어나 필터 조건을 바꿔 다시 확인해 주세요.</p>
                </div>
            @else
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full min-w-[60rem] text-left text-sm" data-test="request-table">
                        <thead class="bg-zinc-50 text-xs font-semibold tracking-wide text-zinc-500 uppercase dark:bg-white/[0.03] dark:text-zinc-400">
                            <tr>
                                <th class="px-5 py-3">요청</th>
                                <th class="px-5 py-3">고객사 · 프로젝트</th>
                                <th class="px-5 py-3">상태</th>
                                <th class="px-5 py-3">유형</th>
                                <th class="px-5 py-3">우선순위</th>
                                <th class="px-5 py-3 text-right">접수일</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700/80">
                            @foreach ($workRequests as $workRequest)
                                @php
                                    $statusClass = match ($workRequest->status) {
                                        \App\Enums\WorkRequestStatus::Completed => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300',
                                        \App\Enums\WorkRequestStatus::Cancelled => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300',
                                        \App\Enums\WorkRequestStatus::OnHold, \App\Enums\WorkRequestStatus::AwaitingApproval => 'bg-amber-50 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300',
                                        default => 'bg-cyan-50 text-cyan-700 dark:bg-cyan-400/10 dark:text-cyan-300',
                                    };
                                    $priorityClass = match ($workRequest->priority) {
                                        \App\Enums\WorkRequestPriority::High => 'text-red-700 dark:text-red-300',
                                        \App\Enums\WorkRequestPriority::Low => 'text-zinc-500 dark:text-zinc-400',
                                        default => 'text-zinc-800 dark:text-zinc-200',
                                    };
                                @endphp
                                <tr class="transition-colors hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                                    <td class="max-w-sm px-5 py-4">
                                        <div class="flex items-center gap-2">
                                            @if ($workRequest->is_urgent)
                                                <span class="inline-flex shrink-0 rounded bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-400/10 dark:text-red-300">긴급</span>
                                            @endif
                                            <a href="{{ route('requests.show', $workRequest) }}" class="truncate font-semibold text-zinc-950 hover:text-cyan-700 dark:text-white dark:hover:text-cyan-300">{{ $workRequest->title }}</a>
                                        </div>
                                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">#{{ $workRequest->id }} · 요청자 {{ $workRequest->submitter->name }}</p>
                                    </td>
                                    <td class="max-w-xs px-5 py-4">
                                        <p class="truncate font-medium text-zinc-800 dark:text-zinc-200">{{ $workRequest->company->name }}</p>
                                        <p class="mt-1 truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $workRequest->project->name }}</p>
                                    </td>
                                    <td class="px-5 py-4"><span class="inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClass }}">{{ $workRequest->status->label() }}</span></td>
                                    <td class="px-5 py-4 whitespace-nowrap text-zinc-700 dark:text-zinc-200">{{ $workRequest->type->label() }}</td>
                                    <td class="px-5 py-4 whitespace-nowrap font-semibold {{ $priorityClass }}">{{ $workRequest->priority->label() }}</td>
                                    <td class="px-5 py-4 text-right font-mono text-xs whitespace-nowrap text-zinc-500 dark:text-zinc-400"><time datetime="{{ $workRequest->requested_at->toDateString() }}">{{ $workRequest->requested_at->format('Y.m.d H:i') }}</time></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <ul class="divide-y divide-zinc-200 dark:divide-zinc-700/80 md:hidden" data-test="request-card-list">
                    @foreach ($workRequests as $workRequest)
                        @php
                            $statusClass = match ($workRequest->status) {
                                \App\Enums\WorkRequestStatus::Completed => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300',
                                \App\Enums\WorkRequestStatus::Cancelled => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300',
                                \App\Enums\WorkRequestStatus::OnHold, \App\Enums\WorkRequestStatus::AwaitingApproval => 'bg-amber-50 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300',
                                default => 'bg-cyan-50 text-cyan-700 dark:bg-cyan-400/10 dark:text-cyan-300',
                            };
                            $priorityClass = $workRequest->priority === \App\Enums\WorkRequestPriority::High
                                ? 'text-red-700 dark:text-red-300'
                                : 'text-zinc-600 dark:text-zinc-300';
                        @endphp
                        <li class="px-5 py-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if ($workRequest->is_urgent)
                                            <span class="inline-flex rounded bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-400/10 dark:text-red-300">긴급</span>
                                        @endif
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClass }}">{{ $workRequest->status->label() }}</span>
                                    </div>
                                    <a href="{{ route('requests.show', $workRequest) }}" class="mt-2 block break-words font-semibold text-zinc-950 hover:text-cyan-700 dark:text-white dark:hover:text-cyan-300">{{ $workRequest->title }}</a>
                                </div>
                                <span class="shrink-0 text-xs font-semibold {{ $priorityClass }}">{{ $workRequest->priority->label() }}</span>
                            </div>
                            <p class="mt-3 truncate text-sm text-zinc-700 dark:text-zinc-200">{{ $workRequest->company->name }} · {{ $workRequest->project->name }}</p>
                            <div class="mt-3 flex items-center justify-between gap-3 text-xs text-zinc-500 dark:text-zinc-400">
                                <span>{{ $workRequest->type->label() }} · #{{ $workRequest->id }}</span>
                                <time datetime="{{ $workRequest->requested_at->toDateString() }}" class="shrink-0 font-mono">{{ $workRequest->requested_at->format('Y.m.d') }}</time>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{ $workRequests->links() }}
    </div>
</x-layouts::app>
