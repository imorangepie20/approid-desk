<x-layouts::app :title="$company->name">
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6">
        <header>
            @can(\App\Enums\Permission::ManageCompanies->value)
                <a href="{{ route('companies.index') }}" class="inline-flex items-center gap-1 text-sm font-medium text-zinc-500 hover:text-cyan-700 dark:text-zinc-400 dark:hover:text-cyan-300">
                    <flux:icon.chevron-left class="size-4" /> 고객사 목록
                </a>
            @endcan
            <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-semibold tracking-[0.18em] text-cyan-700 uppercase dark:text-cyan-300">Client workspace</p>
                    <div class="mt-2 flex flex-wrap items-center gap-3">
                        <h1 class="text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">{{ $company->name }}</h1>
                        <span @class([
                            'inline-flex rounded-full px-2.5 py-1 text-xs font-semibold',
                            'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300' => $company->status === \App\Enums\CompanyStatus::Active,
                            'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300' => $company->status === \App\Enums\CompanyStatus::Inactive,
                        ])>{{ $company->status->label() }}</span>
                    </div>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">사용자 계정과 초대 상태를 한곳에서 관리합니다.</p>
                </div>
                <dl class="flex gap-2 text-center">
                    <div class="min-w-20 rounded-lg border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-[#141B2D]"><dt class="text-xs text-zinc-500 dark:text-zinc-400">사용자</dt><dd class="mt-1 font-mono font-semibold text-zinc-950 dark:text-white">{{ $company->users_count }}</dd></div>
                    <div class="min-w-20 rounded-lg border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-[#141B2D]"><dt class="text-xs text-zinc-500 dark:text-zinc-400">프로젝트</dt><dd class="mt-1 font-mono font-semibold text-zinc-950 dark:text-white">{{ $company->projects_count }}</dd></div>
                </dl>
            </div>
        </header>

        @if (session('success'))
            <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-400/20 dark:bg-emerald-400/10 dark:text-emerald-200">
                {{ session('success') }}
            </div>
        @endif

        @if (session('invitation_url'))
            <section class="rounded-xl border border-cyan-200 bg-cyan-50 p-5 dark:border-cyan-400/20 dark:bg-cyan-400/10" aria-labelledby="invitation-link-heading" data-test="invitation-link-panel">
                <div class="flex items-start gap-3">
                    <div class="grid size-9 shrink-0 place-items-center rounded-lg bg-white text-cyan-700 dark:bg-cyan-300/10 dark:text-cyan-200">
                        <flux:icon.link class="size-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 id="invitation-link-heading" class="font-semibold text-cyan-950 dark:text-cyan-100">초대 수락 링크가 준비되었습니다.</h2>
                        <p class="mt-1 text-sm text-cyan-800 dark:text-cyan-200/80">이 링크는 지금만 표시됩니다. 초대 대상자에게 안전한 채널로 전달해 주세요.</p>
                        <input readonly value="{{ session('invitation_url') }}" aria-label="초대 수락 링크" class="mt-3 h-10 w-full rounded-lg border border-cyan-300 bg-white px-3 font-mono text-xs text-zinc-800 dark:border-cyan-400/30 dark:bg-zinc-900 dark:text-zinc-100" onclick="this.select()" />
                    </div>
                </div>
            </section>
        @endif

        <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="space-y-6">
                <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="users-heading">
                    <div class="flex items-start justify-between gap-4 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700/80">
                        <div>
                            <h2 id="users-heading" class="font-semibold text-zinc-950 dark:text-white">사용자</h2>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">초대를 수락해 등록된 사용자입니다.</p>
                        </div>
                        <span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">{{ $users->count() }}명</span>
                    </div>

                    @if ($users->isEmpty())
                        <div class="px-5 py-12 text-center" data-test="user-empty-state">
                            <div class="mx-auto grid size-11 place-items-center rounded-full bg-cyan-50 text-cyan-700 dark:bg-cyan-400/10 dark:text-cyan-300"><flux:icon.users class="size-5" /></div>
                            <p class="mt-3 font-medium text-zinc-900 dark:text-white">등록된 사용자가 없습니다.</p>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">오른쪽 초대 양식에서 첫 사용자를 초대해 주세요.</p>
                        </div>
                    @else
                        <div class="hidden overflow-x-auto md:block">
                            <table class="w-full text-left text-sm" data-test="user-table">
                                <thead class="bg-zinc-50 text-xs font-semibold tracking-wide text-zinc-500 uppercase dark:bg-white/[0.03] dark:text-zinc-400">
                                    <tr><th class="px-5 py-3">사용자</th><th class="px-5 py-3">역할</th><th class="px-5 py-3">상태</th><th class="px-5 py-3">등록일</th></tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700/80">
                                    @foreach ($users as $companyUser)
                                        <tr>
                                            <td class="px-5 py-4"><p class="font-medium text-zinc-950 dark:text-white">{{ $companyUser->name }}</p><p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $companyUser->email }}</p></td>
                                            <td class="px-5 py-4 text-zinc-700 dark:text-zinc-200">{{ $companyUser->role->label() }}</td>
                                            <td class="px-5 py-4"><span @class(['inline-flex rounded-full px-2.5 py-1 text-xs font-semibold', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300' => $companyUser->is_active, 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300' => ! $companyUser->is_active])>{{ $companyUser->is_active ? '활성' : '비활성' }}</span></td>
                                            <td class="px-5 py-4 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $companyUser->created_at?->format('Y.m.d') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700/80 md:hidden" data-test="user-card-list">
                            @foreach ($users as $companyUser)
                                <li class="px-5 py-4">
                                    <div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="truncate font-medium text-zinc-950 dark:text-white">{{ $companyUser->name }}</p><p class="mt-1 truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $companyUser->email }}</p></div><span class="shrink-0 rounded bg-zinc-100 px-2 py-1 text-xs font-medium text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">{{ $companyUser->role->label() }}</span></div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="invitations-heading">
                    <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-700/80">
                        <h2 id="invitations-heading" class="font-semibold text-zinc-950 dark:text-white">최근 초대</h2>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">최근 생성된 초대 최대 25건입니다.</p>
                    </div>

                    @if ($invitations->isEmpty())
                        <div class="px-5 py-10 text-center text-sm text-zinc-500 dark:text-zinc-400" data-test="invitation-empty-state">아직 생성된 초대가 없습니다.</div>
                    @else
                        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700/80" data-test="invitation-list">
                            @foreach ($invitations as $invitation)
                                @php
                                    $invitationState = match (true) {
                                        $invitation->accepted_at !== null => ['수락 완료', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300'],
                                        $invitation->revoked_at !== null => ['취소됨', 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300'],
                                        $invitation->expires_at->isPast() => ['만료됨', 'bg-amber-50 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300'],
                                        default => ['수락 대기', 'bg-cyan-50 text-cyan-700 dark:bg-cyan-400/10 dark:text-cyan-300'],
                                    };
                                @endphp
                                <li class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="min-w-0"><p class="truncate font-medium text-zinc-950 dark:text-white">{{ $invitation->email }}</p><p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $invitation->role->label() }} · {{ $invitation->inviter->name }} 초대 · {{ $invitation->expires_at->format('Y.m.d H:i') }} 만료</p></div>
                                    <div class="flex shrink-0 items-center gap-2">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $invitationState[1] }}">{{ $invitationState[0] }}</span>
                                        @if ($invitation->accepted_at === null && $invitation->revoked_at === null && $invitation->expires_at->isFuture())
                                            <form method="POST" action="{{ route('companies.invitations.destroy', [$company, $invitation]) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="rounded-md px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-400/10">초대 취소</button>
                                            </form>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>

            <aside class="space-y-6">
                <section class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="invite-user-heading">
                    <div class="flex items-center gap-3"><div class="grid size-10 place-items-center rounded-lg bg-cyan-50 text-cyan-700 dark:bg-cyan-400/10 dark:text-cyan-300"><flux:icon.user-plus class="size-5" /></div><div><h2 id="invite-user-heading" class="font-semibold text-zinc-950 dark:text-white">사용자 초대</h2><p class="text-sm text-zinc-500 dark:text-zinc-400">수락 링크 생성</p></div></div>

                    @if ($company->status === \App\Enums\CompanyStatus::Inactive)
                        <p class="mt-5 rounded-lg bg-amber-50 px-3 py-3 text-sm text-amber-800 dark:bg-amber-400/10 dark:text-amber-200">비활성 고객사에는 사용자를 초대할 수 없습니다.</p>
                    @else
                        <form method="POST" action="{{ route('companies.invitations.store', $company) }}" class="mt-5 grid gap-4">
                            @csrf
                            <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">이메일 <span class="text-red-600">*</span><input name="email" type="email" value="{{ old('email', $company->primary_contact_email) }}" required maxlength="255" autocomplete="off" class="h-10 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />@error('email') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror</label>
                            <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">역할 <span class="text-red-600">*</span><select name="role" required class="h-10 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">@foreach ($customerRoles as $role)<option value="{{ $role->value }}" @selected(old('role', \App\Enums\UserRole::CustomerUser->value) === $role->value)>{{ $role->label() }}</option>@endforeach</select>@error('role') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror</label>
                            <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">만료 일시 <span class="text-red-600">*</span><input name="expires_at" type="datetime-local" value="{{ old('expires_at', $defaultExpiration) }}" required class="h-10 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />@error('expires_at') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror</label>
                            <flux:button type="submit" variant="primary" class="w-full">초대 링크 만들기</flux:button>
                        </form>
                    @endif
                </section>

                @if ($canUpdateCompany)
                    <section class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="company-info-heading">
                        <h2 id="company-info-heading" class="font-semibold text-zinc-950 dark:text-white">고객사 정보</h2>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">기본정보와 이용 상태를 변경합니다.</p>
                        <form method="POST" action="{{ route('companies.update', $company) }}" class="mt-5 grid gap-4">
                            @csrf
                            @method('PATCH')
                            <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">고객사명 <span class="text-red-600">*</span><input name="name" value="{{ old('name', $company->name) }}" required maxlength="255" autocomplete="organization" class="h-10 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />@error('name') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror</label>
                            <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">상태 <span class="text-red-600">*</span><select name="status" required class="h-10 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">@foreach ($statuses as $status)<option value="{{ $status->value }}" @selected(old('status', $company->status->value) === $status->value)>{{ $status->label() }}</option>@endforeach</select>@error('status') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror</label>
                            <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">주 담당자 <span class="text-xs font-normal text-zinc-500 dark:text-zinc-400">선택</span><input name="primary_contact_name" value="{{ old('primary_contact_name', $company->primary_contact_name) }}" maxlength="255" autocomplete="name" class="h-10 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />@error('primary_contact_name') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror</label>
                            <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">담당자 이메일 <span class="text-xs font-normal text-zinc-500 dark:text-zinc-400">선택</span><input name="primary_contact_email" type="email" value="{{ old('primary_contact_email', $company->primary_contact_email) }}" maxlength="255" autocomplete="email" class="h-10 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />@error('primary_contact_email') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror</label>
                            <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">연락처 <span class="text-xs font-normal text-zinc-500 dark:text-zinc-400">선택</span><input name="primary_contact_phone" value="{{ old('primary_contact_phone', $company->primary_contact_phone) }}" maxlength="30" autocomplete="tel" class="h-10 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />@error('primary_contact_phone') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror</label>
                            <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">메모 <span class="text-xs font-normal text-zinc-500 dark:text-zinc-400">선택</span><textarea name="notes" rows="4" maxlength="5000" class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">{{ old('notes', $company->notes) }}</textarea>@error('notes') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror</label>
                            <flux:button type="submit" variant="primary" class="w-full">고객사 정보 저장</flux:button>
                        </form>
                    </section>
                @endif
            </aside>
        </div>
    </div>
</x-layouts::app>
