@if ($workRequest->is_urgent)
    <section id="major-incident-rollbacks" class="overflow-hidden rounded-xl border border-amber-200 bg-white shadow-sm dark:border-amber-400/30 dark:bg-[#141B2D]" aria-labelledby="major-incident-rollbacks-heading" data-test="major-incident-rollbacks">
        <div class="border-b border-amber-100 bg-amber-50/70 px-5 py-4 dark:border-amber-400/20 dark:bg-amber-400/[0.07]">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 id="major-incident-rollbacks-heading" class="font-semibold text-zinc-950 dark:text-white">롤백 실행 기록</h2>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">실제 롤백의 대상, 실행 계획, 검증 계획과 확정 결과를 고객과 함께 확인합니다.</p>
                </div>
                <span class="inline-flex w-fit rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800 dark:bg-amber-400/15 dark:text-amber-200">{{ $workRequest->majorIncidentRollbacks->count() }}건</span>
            </div>
        </div>

        @if ($canStartMajorIncidentRollback)
            <form method="POST" action="{{ route('requests.rollbacks.store', $workRequest) }}" class="border-b border-zinc-200 p-5 dark:border-zinc-700/80" data-test="major-incident-rollback-start-form">
                @csrf
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                        롤백 대상
                        <input name="rollback_target" required maxlength="255" value="{{ old('rollback_target') }}" placeholder="예: API 서버 release-2026.10.04" class="h-10 min-w-0 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                        @error('rollback_target') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                    </label>
                    <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                        실제 시작 시각
                        <input type="datetime-local" name="rollback_started_at" required value="{{ old('rollback_started_at', now()->format('Y-m-d\TH:i')) }}" class="h-10 min-w-0 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                        @error('rollback_started_at') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                    </label>
                    <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200 sm:col-span-2">
                        실행 계획
                        <textarea name="rollback_plan" required maxlength="10000" rows="4" placeholder="실행 순서, 영향 범위와 중단 기준을 기록해 주세요." class="min-w-0 resize-y rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm leading-6 text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">{{ old('rollback_plan') }}</textarea>
                        @error('rollback_plan') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                    </label>
                    <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200 sm:col-span-2">
                        검증 계획
                        <textarea name="rollback_verification_plan" required maxlength="10000" rows="3" placeholder="복구 여부를 판단할 지표와 확인 항목을 기록해 주세요." class="min-w-0 resize-y rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm leading-6 text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">{{ old('rollback_verification_plan') }}</textarea>
                        @error('rollback_verification_plan') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                    </label>
                </div>
                <label class="mt-4 flex items-start gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                    <input type="checkbox" name="rollback_start_confirmed" value="1" required @checked(old('rollback_start_confirmed')) class="mt-0.5 size-4 rounded border-zinc-300 text-cyan-700 focus:ring-cyan-600 dark:border-zinc-600 dark:bg-zinc-900" />
                    <span>대상, 실행 계획과 검증 계획을 확인했으며 실제 롤백 시작을 기록합니다.</span>
                </label>
                @error('rollback_start_confirmed') <span class="mt-1 block text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                <p class="mt-3 text-xs leading-5 text-zinc-500 dark:text-zinc-400">이 화면은 절차를 기록하며 서버 명령을 실행하지 않습니다. 비밀번호, 토큰이나 인증정보는 입력하지 마세요.</p>
                <div class="mt-4 flex justify-end"><flux:button type="submit" variant="primary" icon="arrow-path">롤백 시작 기록</flux:button></div>
            </form>
        @endif

        @if ($workRequest->majorIncidentRollbacks->isEmpty())
            <div class="px-5 py-10 text-center text-sm text-zinc-500 dark:text-zinc-400" data-test="major-incident-rollback-empty">등록된 롤백 실행 기록이 없습니다.</div>
        @else
            <ol class="divide-y divide-zinc-200 dark:divide-zinc-700/80" data-test="major-incident-rollback-list">
                @foreach ($workRequest->majorIncidentRollbacks as $rollback)
                    <li class="px-5 py-5" data-test="major-incident-rollback-item">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="break-words font-semibold text-zinc-950 dark:text-white">{{ $rollback->target }}</h3>
                                    @if ($rollback->isActive())
                                        <span class="rounded bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-400/15 dark:text-amber-200">실행 중</span>
                                    @else
                                        <span class="rounded px-2 py-0.5 text-xs font-semibold {{ $rollback->outcome === \App\Enums\MajorIncidentRollbackOutcome::Succeeded ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-400/15 dark:text-emerald-200' : 'bg-red-100 text-red-800 dark:bg-red-400/15 dark:text-red-200' }}">{{ $rollback->outcome->label() }}</span>
                                    @endif
                                </div>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $rollback->starter->name }} · <time datetime="{{ $rollback->started_at->toAtomString() }}" class="font-mono">{{ $rollback->started_at->format('Y.m.d H:i') }}</time></p>
                            </div>
                        </div>
                        <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                            <div><dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">실행 계획</dt><dd class="mt-1 whitespace-pre-wrap break-words leading-6 text-zinc-800 dark:text-zinc-200">{{ $rollback->plan }}</dd></div>
                            <div><dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">검증 계획</dt><dd class="mt-1 whitespace-pre-wrap break-words leading-6 text-zinc-800 dark:text-zinc-200">{{ $rollback->verification_plan }}</dd></div>
                            @if (! $rollback->isActive())
                                <div><dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">결과 요약</dt><dd class="mt-1 break-words font-medium text-zinc-900 dark:text-white">{{ $rollback->result_summary }}</dd></div>
                                <div><dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">결과 확정</dt><dd class="mt-1 text-zinc-800 dark:text-zinc-200">{{ $rollback->completer?->name }} · <time datetime="{{ $rollback->completed_at?->toAtomString() }}" class="font-mono text-xs">{{ $rollback->completed_at?->format('Y.m.d H:i') }}</time></dd></div>
                                <div class="sm:col-span-2"><dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">결과 상세</dt><dd class="mt-1 whitespace-pre-wrap break-words leading-6 text-zinc-800 dark:text-zinc-200">{{ $rollback->result_details }}</dd></div>
                            @endif
                        </dl>

                        @can('complete', $rollback)
                            <form method="POST" action="{{ route('requests.rollbacks.complete', [$workRequest, $rollback]) }}" class="mt-5 border-t border-zinc-200 pt-5 dark:border-zinc-700/80" data-test="major-incident-rollback-complete-form">
                                @csrf
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">결과
                                        <select name="rollback_outcome" required class="h-10 min-w-0 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">
                                            @foreach ($majorIncidentRollbackOutcomes as $outcome)
                                                <option value="{{ $outcome->value }}" @selected(old('rollback_outcome') === $outcome->value)>{{ $outcome->label() }}</option>
                                            @endforeach
                                        </select>
                                        @error('rollback_outcome') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                                    </label>
                                    <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">실제 완료 시각
                                        <input type="datetime-local" name="rollback_completed_at" required value="{{ old('rollback_completed_at', now()->format('Y-m-d\TH:i')) }}" class="h-10 min-w-0 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                                        @error('rollback_completed_at') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                                    </label>
                                    <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200 sm:col-span-2">결과 요약
                                        <input name="rollback_result_summary" required maxlength="255" value="{{ old('rollback_result_summary') }}" class="h-10 min-w-0 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                                        @error('rollback_result_summary') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                                    </label>
                                    <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200 sm:col-span-2">검증 결과 상세
                                        <textarea name="rollback_result_details" required maxlength="10000" rows="4" class="min-w-0 resize-y rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm leading-6 text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">{{ old('rollback_result_details') }}</textarea>
                                        @error('rollback_result_details') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                                    </label>
                                </div>
                                <label class="mt-4 flex items-start gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                                    <input type="checkbox" name="rollback_result_confirmed" value="1" required @checked(old('rollback_result_confirmed')) class="mt-0.5 size-4 rounded border-zinc-300 text-cyan-700 focus:ring-cyan-600 dark:border-zinc-600 dark:bg-zinc-900" />
                                    <span>결과와 검증 내용을 확인했으며 이 기록은 확정 후 수정할 수 없습니다.</span>
                                </label>
                                @error('rollback_result_confirmed') <span class="mt-1 block text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                                <div class="mt-4 flex justify-end"><flux:button type="submit" variant="primary" icon="check">결과 확정</flux:button></div>
                            </form>
                        @endcan
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
@endif
