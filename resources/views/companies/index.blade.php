<x-layouts::app title="고객사 관리">
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6">
        <header class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold tracking-[0.18em] text-cyan-700 uppercase dark:text-cyan-300">Client directory</p>
                <h1 class="mt-2 text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">고객사 관리</h1>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">고객사 상태와 담당자, 사용자 초대 현황을 관리합니다.</p>
            </div>
            <span class="inline-flex w-fit items-center rounded-full bg-cyan-50 px-3 py-1.5 text-sm font-semibold text-cyan-700 dark:bg-cyan-400/10 dark:text-cyan-300">
                전체 {{ number_format($companies->total()) }}곳
            </span>
        </header>

        @if (session('success'))
            <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-400/20 dark:bg-emerald-400/10 dark:text-emerald-200">
                {{ session('success') }}
            </div>
        @endif

        <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="space-y-4">
                <form method="GET" action="{{ route('companies.index') }}" class="grid gap-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D] sm:grid-cols-[minmax(0,1fr)_11rem_auto] sm:items-end">
                    <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                        검색
                        <input name="search" value="{{ $search }}" placeholder="고객사명, 담당자, 이메일" class="h-10 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none placeholder:text-zinc-400 focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
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
                    <div class="flex gap-2">
                        <flux:button type="submit" variant="primary" class="flex-1">조회</flux:button>
                        @if ($search !== '' || $selectedStatus !== '')
                            <flux:button :href="route('companies.index')" variant="ghost">초기화</flux:button>
                        @endif
                    </div>
                </form>

                <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="company-list-heading">
                    <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-700/80">
                        <h2 id="company-list-heading" class="font-semibold text-zinc-950 dark:text-white">고객사 목록</h2>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">고객사를 선택하면 사용자와 초대 현황을 확인할 수 있습니다.</p>
                    </div>

                    @if ($companies->isEmpty())
                        <div class="px-5 py-14 text-center" data-test="company-empty-state">
                            <div class="mx-auto grid size-11 place-items-center rounded-full bg-cyan-50 text-cyan-700 dark:bg-cyan-400/10 dark:text-cyan-300">
                                <flux:icon.building-office-2 class="size-5" />
                            </div>
                            <p class="mt-3 font-medium text-zinc-900 dark:text-white">조건에 맞는 고객사가 없습니다.</p>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">검색 조건을 바꾸거나 새 고객사를 등록해 주세요.</p>
                        </div>
                    @else
                        <div class="hidden overflow-x-auto md:block">
                            <table class="w-full text-left text-sm" data-test="company-table">
                                <thead class="bg-zinc-50 text-xs font-semibold tracking-wide text-zinc-500 uppercase dark:bg-white/[0.03] dark:text-zinc-400">
                                    <tr>
                                        <th class="px-5 py-3">고객사</th>
                                        <th class="px-5 py-3">상태</th>
                                        <th class="px-5 py-3 text-right">사용자</th>
                                        <th class="px-5 py-3 text-right">프로젝트</th>
                                        <th class="px-5 py-3 text-right">대기 초대</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700/80">
                                    @foreach ($companies as $company)
                                        <tr class="transition-colors hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                                            <td class="px-5 py-4">
                                                <a href="{{ route('companies.show', $company) }}" class="font-semibold text-zinc-950 hover:text-cyan-700 dark:text-white dark:hover:text-cyan-300">
                                                    {{ $company->name }}
                                                </a>
                                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $company->primary_contact_name ?: '담당자 미등록' }}</p>
                                            </td>
                                            <td class="px-5 py-4">
                                                <span @class([
                                                    'inline-flex rounded-full px-2.5 py-1 text-xs font-semibold',
                                                    'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300' => $company->status === \App\Enums\CompanyStatus::Active,
                                                    'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300' => $company->status === \App\Enums\CompanyStatus::Inactive,
                                                ])>{{ $company->status->label() }}</span>
                                            </td>
                                            <td class="px-5 py-4 text-right font-mono text-zinc-700 dark:text-zinc-200">{{ number_format($company->users_count) }}</td>
                                            <td class="px-5 py-4 text-right font-mono text-zinc-700 dark:text-zinc-200">{{ number_format($company->projects_count) }}</td>
                                            <td class="px-5 py-4 text-right font-mono text-zinc-700 dark:text-zinc-200">{{ number_format($company->pending_invitations_count) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700/80 md:hidden" data-test="company-card-list">
                            @foreach ($companies as $company)
                                <li>
                                    <a href="{{ route('companies.show', $company) }}" class="block px-5 py-4 transition-colors hover:bg-zinc-50 dark:hover:bg-white/[0.03]">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <p class="truncate font-semibold text-zinc-950 dark:text-white">{{ $company->name }}</p>
                                                <p class="mt-1 truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $company->primary_contact_name ?: '담당자 미등록' }}</p>
                                            </div>
                                            <span @class([
                                                'inline-flex shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold',
                                                'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300' => $company->status === \App\Enums\CompanyStatus::Active,
                                                'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300' => $company->status === \App\Enums\CompanyStatus::Inactive,
                                            ])>{{ $company->status->label() }}</span>
                                        </div>
                                        <dl class="mt-4 grid grid-cols-3 gap-3 text-center">
                                            <div class="rounded-lg bg-zinc-50 px-2 py-2 dark:bg-white/[0.04]"><dt class="text-xs text-zinc-500 dark:text-zinc-400">사용자</dt><dd class="mt-1 font-mono text-sm text-zinc-900 dark:text-white">{{ $company->users_count }}</dd></div>
                                            <div class="rounded-lg bg-zinc-50 px-2 py-2 dark:bg-white/[0.04]"><dt class="text-xs text-zinc-500 dark:text-zinc-400">프로젝트</dt><dd class="mt-1 font-mono text-sm text-zinc-900 dark:text-white">{{ $company->projects_count }}</dd></div>
                                            <div class="rounded-lg bg-zinc-50 px-2 py-2 dark:bg-white/[0.04]"><dt class="text-xs text-zinc-500 dark:text-zinc-400">대기 초대</dt><dd class="mt-1 font-mono text-sm text-zinc-900 dark:text-white">{{ $company->pending_invitations_count }}</dd></div>
                                        </dl>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                {{ $companies->links() }}
            </div>

            <aside class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="new-company-heading">
                <div class="flex items-center gap-3">
                    <div class="grid size-10 place-items-center rounded-lg bg-cyan-50 text-cyan-700 dark:bg-cyan-400/10 dark:text-cyan-300">
                        <flux:icon.building-office-2 class="size-5" />
                    </div>
                    <div>
                        <h2 id="new-company-heading" class="font-semibold text-zinc-950 dark:text-white">새 고객사</h2>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">기본정보 등록</p>
                    </div>
                </div>

                <form method="POST" action="{{ route('companies.store') }}" class="mt-5 grid gap-4">
                    @csrf
                    <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                        고객사명 <span class="text-red-600">*</span>
                        <input name="name" value="{{ old('name') }}" required maxlength="255" autocomplete="organization" class="h-10 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                        @error('name') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                    </label>
                    <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                        주 담당자 <span class="text-xs font-normal text-zinc-500 dark:text-zinc-400">선택</span>
                        <input name="primary_contact_name" value="{{ old('primary_contact_name') }}" maxlength="255" autocomplete="name" class="h-10 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                        @error('primary_contact_name') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                    </label>
                    <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                        담당자 이메일 <span class="text-xs font-normal text-zinc-500 dark:text-zinc-400">선택</span>
                        <input name="primary_contact_email" type="email" value="{{ old('primary_contact_email') }}" maxlength="255" autocomplete="email" class="h-10 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                        @error('primary_contact_email') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                    </label>
                    <input type="hidden" name="status" value="{{ \App\Enums\CompanyStatus::Active->value }}" />
                    <flux:button type="submit" variant="primary" class="w-full">고객사 등록</flux:button>
                </form>
            </aside>
        </div>
    </div>
</x-layouts::app>
