@if ($workRequest->is_urgent)
    <section id="major-incident-history" class="overflow-hidden rounded-xl border border-red-200 bg-white shadow-sm dark:border-red-400/30 dark:bg-[#141B2D]" aria-labelledby="major-incident-history-heading" data-test="major-incident-history">
        <div class="border-b border-red-100 bg-red-50/70 px-5 py-4 dark:border-red-400/20 dark:bg-red-400/[0.07]">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 id="major-incident-history-heading" class="font-semibold text-zinc-950 dark:text-white">장애 대응 이력</h2>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">최초 응답, 대응 진행, 고객 협의와 복구 확인을 실제 발생 순서로 표시합니다.</p>
                </div>
                <span class="inline-flex w-fit rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-800 dark:bg-red-400/15 dark:text-red-200">{{ $workRequest->majorIncidentEvents->count() }}건</span>
            </div>
        </div>

        @if ($canRecordMajorIncidentEvent)
            <form method="POST" action="{{ route('requests.incident-events.store', $workRequest) }}" class="border-b border-zinc-200 p-5 dark:border-zinc-700/80" data-test="major-incident-event-form">
                @csrf
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                        기록 유형
                        <select name="event_type" required class="h-10 min-w-0 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">
                            @foreach ($majorIncidentEventTypes as $eventType)
                                @if ($eventType !== \App\Enums\MajorIncidentEventType::FirstResponse || $workRequest->firstResponseEvent === null)
                                    <option value="{{ $eventType->value }}" @selected(old('event_type') === $eventType->value)>{{ $eventType->label() }}</option>
                                @endif
                            @endforeach
                        </select>
                        @error('event_type') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                    </label>
                    <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200">
                        실제 발생 시각
                        <input type="datetime-local" name="occurred_at" required value="{{ old('occurred_at', now()->format('Y-m-d\TH:i')) }}" class="h-10 min-w-0 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                        @error('occurred_at') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                    </label>
                    <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200 sm:col-span-2">
                        요약
                        <input name="summary" required maxlength="255" value="{{ old('summary') }}" placeholder="예: 서비스 상태 확인 및 고객 담당자에게 최초 안내" class="h-10 min-w-0 rounded-lg border border-zinc-300 bg-white px-3 text-sm text-zinc-950 outline-none placeholder:text-zinc-400 focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
                        @error('summary') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                    </label>
                    <label class="grid gap-2 text-sm font-medium text-zinc-800 dark:text-zinc-200 sm:col-span-2">
                        상세 내용
                        <textarea name="details" rows="4" required maxlength="10000" placeholder="확인 결과, 안내 내용, 합의 사항 또는 복구 상태를 남겨 주세요." class="w-full resize-y rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm leading-6 text-zinc-950 outline-none placeholder:text-zinc-400 focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">{{ old('details') }}</textarea>
                        @error('details') <span class="text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                    </label>
                </div>
                <div class="mt-4 flex flex-col gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-700/80 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">등록한 기록은 고객에게 공개되며 수정하거나 삭제할 수 없습니다.</p>
                    <flux:button type="submit" variant="primary" icon="plus">이력 등록</flux:button>
                </div>
            </form>
        @endif

        @if ($workRequest->majorIncidentEvents->isEmpty())
            <div class="px-5 py-10 text-center" data-test="major-incident-history-empty">
                <p class="font-medium text-zinc-900 dark:text-white">아직 등록된 대응 이력이 없습니다.</p>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">최초 응답이 등록되면 기한 준수 여부가 확정됩니다.</p>
            </div>
        @else
            <ol class="divide-y divide-zinc-200 dark:divide-zinc-700/80" data-test="major-incident-event-list">
                @foreach ($workRequest->majorIncidentEvents as $event)
                    <li class="px-5 py-4">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex rounded bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-400/10 dark:text-red-300">{{ $event->event_type->label() }}</span>
                                    @if ($event->event_type === \App\Enums\MajorIncidentEventType::FirstResponse)
                                        @php($eventResponseStatus = $event->occurred_at->isAfter($responseTargetAt) ? \App\Enums\MajorIncidentResponseStatus::Late : \App\Enums\MajorIncidentResponseStatus::Met)
                                        <span class="text-xs font-semibold {{ $eventResponseStatus === \App\Enums\MajorIncidentResponseStatus::Met ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300' }}">{{ $eventResponseStatus->label() }}</span>
                                    @endif
                                </div>
                                <p class="mt-2 break-words font-semibold text-zinc-950 dark:text-white">{{ $event->summary }}</p>
                                <p class="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-zinc-700 dark:text-zinc-200">{{ $event->details }}</p>
                                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">기록자 {{ $event->recorder->name }}</p>
                            </div>
                            <time datetime="{{ $event->occurred_at->toAtomString() }}" class="shrink-0 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $event->occurred_at->format('Y.m.d H:i') }}</time>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
@endif
