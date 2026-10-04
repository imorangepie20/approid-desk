<section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="estimate-history-heading" data-test="estimate-history">
    <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-700/80">
        <h2 id="estimate-history-heading" class="font-semibold text-zinc-950 dark:text-white">견적·승인 이력</h2>
        @can('writeDraft', [\App\Models\EstimateVersion::class, $workRequest])
            <a data-test="write-estimate" href="{{ route('requests.estimates.create', $workRequest) }}" class="mt-3 inline-flex text-sm font-semibold text-cyan-700 dark:text-cyan-300">견적 작성 →</a>
        @endcan
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">견적, 계약 서명 확인, 고객 승인과 상태 변경을 최신순으로 표시합니다. 내부 분석과 초안은 운영자에게만 표시됩니다.</p>
    </div>
    <div class="border-b border-zinc-200 px-5 py-4 text-sm dark:border-zinc-700/80" data-test="contract-summary">
        @if ($contract = $workRequest->serviceContract)
            <p class="font-medium text-zinc-900 dark:text-white">연결 계약: {{ $contract->type->label() }} · {{ $contract->status->label() }}</p>
            <p class="mt-1 text-zinc-600 dark:text-zinc-300">계약 기간 {{ $contract->starts_on->format('Y.m.d') }} ~ {{ $contract->ends_on?->format('Y.m.d') ?? '종료일 미지정' }}</p>
            <p class="mt-1 text-zinc-600 dark:text-zinc-300">{{ $contract->signature_confirmed_at ? '서명 확인 완료' : '서명 미확인' }} · {{ $contract->permitsWork() ? '현재 계약 조건 충족' : '현재 계약 조건 미충족' }}</p>
        @else
            <p class="text-zinc-600 dark:text-zinc-300">연결된 계약이 없습니다.</p>
        @endif
    </div>
    @if (empty($estimateHistory))
        <p class="px-5 py-10 text-center text-sm text-zinc-500 dark:text-zinc-400" data-test="estimate-history-empty">표시할 견적·승인 이력이 없습니다.</p>
    @else
        <ol class="divide-y divide-zinc-200 dark:divide-zinc-700/80">
            @foreach ($estimateHistory as $event)
                @php($record = $event['record'])
                <li class="px-5 py-4" data-test="history-{{ $event['kind'] }}-{{ $record->id }}">
                    <time datetime="{{ $event['at']->toAtomString() }}" class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $event['at']->format('Y.m.d H:i:s') }}</time>
                    <div class="mt-2 space-y-2 break-words text-sm text-zinc-700 dark:text-zinc-200">
                        @switch($event['kind'])
                            @case('estimate')
                                <h3 class="font-semibold text-zinc-950 dark:text-white">견적 v{{ $record->version }} · {{ $record->submitted_at ? '제출됨' : '초안 · 내부 전용' }}@if ($workRequest->approved_estimate_version_id === $record->id) · 승인된 버전@endif</h3>
                                <p>작성자 {{ $record->creator?->name ?? '알 수 없음' }}</p>
                                @can('decide', $record)
                                    <a data-test="decide-estimate" href="{{ route('requests.estimates.decision', [$workRequest, $record]) }}" class="inline-flex font-medium text-cyan-700 dark:text-cyan-300">견적 확인·승인 또는 수정 요청 →</a>
                                @endcan
                                @can('submit', $record)
                                    <a href="{{ route('requests.estimates.preview', [$workRequest, $record]) }}" class="inline-flex font-medium text-cyan-700 dark:text-cyan-300">미리보기@can('submitDraft', $record) 및 제출@endcan →</a>
                                @endcan
                                <dl class="grid gap-3 rounded-lg bg-zinc-50 p-3 sm:grid-cols-2 dark:bg-zinc-900/50">
                                    <div><dt class="text-xs text-zinc-500 dark:text-zinc-400">예상시간</dt><dd class="mt-1 font-medium">{{ number_format($record->estimated_minutes) }}분</dd></div>
                                    <div><dt class="text-xs text-zinc-500 dark:text-zinc-400">견적 금액</dt><dd class="mt-1 font-medium">{{ number_format($record->amount) }}원</dd></div>
                                    <div><dt class="text-xs text-zinc-500 dark:text-zinc-400">예정일</dt><dd class="mt-1">{{ $record->scheduled_on->format('Y.m.d') }}</dd></div>
                                    <div><dt class="text-xs text-zinc-500 dark:text-zinc-400">사용 대상 월</dt><dd class="mt-1">{{ $record->usage_month->format('Y.m') }}</dd></div>
                                </dl>
                                <details class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                    <summary class="cursor-pointer font-medium text-cyan-700 dark:text-cyan-300">견적 범위와 산정 근거 보기</summary>
                                    <dl class="mt-3 space-y-3">
                                        <div><dt class="font-medium">포함 범위</dt><dd class="mt-1 whitespace-pre-wrap">{{ $record->included_scope }}</dd></div>
                                        <div><dt class="font-medium">제외 범위</dt><dd class="mt-1 whitespace-pre-wrap">{{ $record->excluded_scope }}</dd></div>
                                        <div><dt class="font-medium">산정 근거</dt><dd class="mt-1 whitespace-pre-wrap">{{ $record->pricing_rationale }}</dd></div>
                                    </dl>
                                </details>
                                @break
                            @case('approval')
                                <h3 class="font-semibold text-zinc-950 dark:text-white">고객 승인 · 견적 v{{ $record->version }}</h3>
                                <p>{{ $record->approval->approver?->name ?? '알 수 없음' }} · 고객사 관리자</p>
                                <p class="whitespace-pre-wrap">{{ $record->approval->approval_text }}</p>
                                @break
                            @case('contract')
                                <h3 class="font-semibold text-zinc-950 dark:text-white">계약 서명 확인</h3>
                                <p>{{ $record->signatureConfirmer?->name ?? '알 수 없음' }} · {{ $record->type->label() }}</p>
                                @break
                            @case('assessment')
                                <h3 class="font-semibold text-zinc-950 dark:text-white">가격 분석 · {{ $record->decision->label() }} · 내부 전용</h3>
                                <p>{{ $record->assessor?->name ?? '알 수 없음' }} · {{ number_format($record->estimated_minutes) }}분 · {{ number_format($record->amount) }}원</p>
                                <p class="whitespace-pre-wrap">{{ $record->rationale }}</p>
                                @if ($record->decision_reason)<p class="whitespace-pre-wrap">판단 사유: {{ $record->decision_reason }}</p>@endif
                                @break
                            @case('status')
                                <h3 class="font-semibold text-zinc-950 dark:text-white">{{ $record->from_status->label() }} → {{ $record->to_status->label() }}</h3>
                                <p>{{ $record->actor?->name ?? '시스템' }}@if ($record->is_free_rework) · 무상 수정@endif</p>
                                @if ($record->reason)<p class="whitespace-pre-wrap">변경 사유: {{ $record->reason }}</p>@endif
                                @break
                        @endswitch
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</section>
