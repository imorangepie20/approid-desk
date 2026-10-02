<x-layouts::app title="요청 등록">
    <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6">
        <header>
            <a href="{{ route('requests.index') }}" class="inline-flex items-center gap-1 text-sm font-medium text-zinc-500 hover:text-cyan-700 dark:text-zinc-400 dark:hover:text-cyan-300">
                <flux:icon.chevron-left class="size-4" /> 요청 목록
            </a>
            <div class="mt-3">
                <p class="text-xs font-semibold tracking-[0.18em] text-cyan-700 uppercase dark:text-cyan-300">New request</p>
                <h1 class="mt-2 text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">요청 등록</h1>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">작업 배경과 완료 기준을 구체적으로 남기면 더 빠르게 검토할 수 있습니다.</p>
            </div>
        </header>

        @if ($errors->any())
            <div role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-400/20 dark:bg-red-400/10 dark:text-red-200">
                입력 내용을 확인해 주세요. 표시된 {{ $errors->count() }}개 항목을 수정하면 등록할 수 있습니다.
            </div>
        @endif

        <form method="POST" action="{{ route('requests.store') }}" class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
            @csrf

            <div class="space-y-6">
                <section class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="request-context-heading">
                    <div>
                        <h2 id="request-context-heading" class="font-semibold text-zinc-950 dark:text-white">요청 대상</h2>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">요청을 처리할 고객사와 프로젝트를 선택합니다.</p>
                    </div>

                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        @if ($canSelectCompany)
                            <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                                <span>고객사 <span class="text-red-600">*</span></span>
                                <select name="company_id" required class="h-10 min-w-0 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">
                                    <option value="">고객사 선택</option>
                                    @foreach ($companies as $company)
                                        <option value="{{ $company->id }}" @selected((int) old('company_id', $selectedCompanyId) === $company->id)>{{ $company->name }}</option>
                                    @endforeach
                                </select>
                                @error('company_id') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                            </label>
                        @else
                            <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2.5 dark:border-zinc-700 dark:bg-white/[0.03]">
                                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">고객사</p>
                                <p class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">{{ $customerCompany?->name }}</p>
                            </div>
                        @endif

                        <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                            <span>프로젝트 <span class="text-red-600">*</span></span>
                            <select name="project_id" required class="h-10 min-w-0 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">
                                <option value="">프로젝트 선택</option>
                                @foreach ($projects as $project)
                                    <option value="{{ $project->id }}" @selected((int) old('project_id') === $project->id)>
                                        @if ($canSelectCompany){{ $project->company->name }} · @endif{{ $project->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('project_id') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                            @if ($projects->isEmpty())
                                <span class="text-xs text-amber-700 dark:text-amber-300">등록 가능한 프로젝트가 없습니다.</span>
                            @endif
                        </label>
                    </div>
                </section>

                <section class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="request-content-heading">
                    <div>
                        <h2 id="request-content-heading" class="font-semibold text-zinc-950 dark:text-white">요청 내용</h2>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">원하는 결과와 확인 방법을 함께 적어 주세요.</p>
                    </div>

                    <div class="mt-5 grid gap-4">
                        <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                            <span>제목 <span class="text-red-600">*</span></span>
                            <input name="title" value="{{ old('title') }}" required maxlength="255" autocomplete="off" placeholder="예: 주문 내역 엑셀 다운로드 추가" class="h-10 min-w-0 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none placeholder:text-zinc-400 focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                            @error('title') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                        </label>

                        <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                            <span>요구사항 <span class="text-red-600">*</span></span>
                            <textarea name="requirements" rows="10" required maxlength="50000" placeholder="현재 상황, 원하는 동작, 완료 확인 기준을 순서대로 작성해 주세요." class="min-h-56 resize-y rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm leading-6 text-zinc-950 outline-none placeholder:text-zinc-400 focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">{{ old('requirements') }}</textarea>
                            @error('requirements') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                        </label>
                    </div>
                </section>

                @if ($canSelectCompany)
                    <section class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="intake-heading">
                        <div>
                            <h2 id="intake-heading" class="font-semibold text-zinc-950 dark:text-white">대리 접수 정보</h2>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">전화·이메일·메신저로 받은 요청은 원문 출처와 요약을 함께 보존합니다.</p>
                        </div>

                        <div class="mt-5 grid gap-4 sm:grid-cols-2">
                            <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                                <span>접수 경로 <span class="text-red-600">*</span></span>
                                <select name="intake_channel" required class="h-10 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">
                                    @foreach ($intakeChannels as $channel)
                                        <option value="{{ $channel->value }}" @selected(old('intake_channel', \App\Enums\IntakeChannel::Web->value) === $channel->value)>{{ $channel->label() }}</option>
                                    @endforeach
                                </select>
                                @error('intake_channel') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                            </label>

                            <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                                <span>실제 요청 일시 <span class="text-xs font-normal text-zinc-500 dark:text-zinc-400">선택</span></span>
                                <input name="requested_at" type="datetime-local" value="{{ old('requested_at') }}" class="h-10 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                                @error('requested_at') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                            </label>

                            <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200 sm:col-span-2">
                                <span>원문 출처 <span class="text-xs font-normal text-zinc-500 dark:text-zinc-400">웹 외 접수 시 필수</span></span>
                                <input name="source_reference" value="{{ old('source_reference') }}" maxlength="2048" autocomplete="off" placeholder="예: 2026.10.02 고객 담당자 전화 통화" class="h-10 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none placeholder:text-zinc-400 focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                                @error('source_reference') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                            </label>

                            <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200 sm:col-span-2">
                                <span>접수 요약 <span class="text-xs font-normal text-zinc-500 dark:text-zinc-400">웹 외 접수 시 필수</span></span>
                                <textarea name="intake_summary" rows="3" maxlength="5000" class="resize-y rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">{{ old('intake_summary') }}</textarea>
                                @error('intake_summary') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                            </label>

                            <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200 sm:col-span-2">
                                <span>지연 등록 사유 <span class="text-xs font-normal text-zinc-500 dark:text-zinc-400">요청일과 등록일이 다를 때 필수</span></span>
                                <textarea name="late_entry_reason" rows="2" maxlength="5000" class="resize-y rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">{{ old('late_entry_reason') }}</textarea>
                                @error('late_entry_reason') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                            </label>
                        </div>
                    </section>
                @endif
            </div>

            <aside class="space-y-6 lg:sticky lg:top-6">
                <section class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="classification-heading">
                    <h2 id="classification-heading" class="font-semibold text-zinc-950 dark:text-white">분류와 일정</h2>
                    <div class="mt-5 grid gap-4">
                        <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                            <span>유형 <span class="text-red-600">*</span></span>
                            <select name="type" required class="h-10 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">
                                @foreach ($types as $type)
                                    <option value="{{ $type->value }}" @selected(old('type', \App\Enums\WorkRequestType::Feature->value) === $type->value)>{{ $type->label() }}</option>
                                @endforeach
                            </select>
                            @error('type') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                        </label>

                        <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                            <span>우선순위 <span class="text-red-600">*</span></span>
                            <select name="priority" required class="h-10 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">
                                @foreach ($priorities as $priority)
                                    <option value="{{ $priority->value }}" @selected(old('priority', \App\Enums\WorkRequestPriority::Normal->value) === $priority->value)>{{ $priority->label() }}</option>
                                @endforeach
                            </select>
                            @error('priority') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                        </label>

                        <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                            <span>희망 완료일 <span class="text-xs font-normal text-zinc-500 dark:text-zinc-400">선택</span></span>
                            <input name="desired_due_date" type="date" value="{{ old('desired_due_date') }}" class="h-10 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                            @error('desired_due_date') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                        </label>

                        <label class="flex items-start gap-3 rounded-lg border border-zinc-200 px-3 py-3 text-sm dark:border-zinc-700">
                            <input type="hidden" name="is_urgent" value="0" />
                            <input name="is_urgent" type="checkbox" value="1" @checked(old('is_urgent') === '1') class="mt-0.5 size-4 rounded border-zinc-300 text-cyan-700 focus:ring-cyan-600 dark:border-zinc-600 dark:bg-zinc-900" />
                            <span><span class="font-medium text-zinc-900 dark:text-white">긴급 요청</span><span class="mt-0.5 block text-xs leading-5 text-zinc-500 dark:text-zinc-400">업무 중단 등 즉시 확인이 필요한 경우에만 선택해 주세요.</span></span>
                        </label>
                    </div>
                </section>

                <div class="grid gap-2">
                    <flux:button type="submit" variant="primary" class="w-full" :disabled="$projects->isEmpty()">요청 등록</flux:button>
                    <flux:button :href="route('requests.index')" variant="ghost" class="w-full">취소</flux:button>
                </div>
            </aside>
        </form>
    </div>
</x-layouts::app>
