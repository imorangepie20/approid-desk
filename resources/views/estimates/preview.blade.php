<x-layouts::app title="견적 미리보기">
    <div class="mx-auto w-full max-w-4xl space-y-6">
        <header>
            <a href="{{ route('requests.show', $workRequest) }}" class="text-sm text-cyan-700 dark:text-cyan-300">← 요청 상세</a>
            <h1 class="mt-3 text-2xl font-bold">견적 v{{ $estimate->version }} 미리보기</h1>
            <p class="mt-2 break-words text-sm text-zinc-500">{{ $workRequest->title }} · {{ $estimate->submitted_at ? '제출 완료 · 수정 불가' : '내부 초안 · 고객 비공개' }}</p>
        </header>
        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:bg-red-950 dark:text-red-200"><ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <section class="space-y-5 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-[#141B2D]" aria-labelledby="preview-heading">
            <h2 id="preview-heading" class="font-semibold">고객에게 공개되는 견적</h2>
            <dl class="grid gap-4 sm:grid-cols-2">
                <div><dt class="text-sm text-zinc-500">예상시간</dt><dd class="mt-1 font-semibold">{{ number_format($estimate->estimated_minutes) }}분</dd></div>
                <div><dt class="text-sm text-zinc-500">견적 금액</dt><dd class="mt-1 font-semibold">{{ number_format($estimate->amount) }}원</dd></div>
                <div><dt class="text-sm text-zinc-500">예정일</dt><dd class="mt-1">{{ $estimate->scheduled_on->format('Y.m.d') }}</dd></div>
                <div><dt class="text-sm text-zinc-500">사용 대상 월</dt><dd class="mt-1">{{ $estimate->usage_month->format('Y.m') }}</dd></div>
            </dl>
            @foreach (['included_scope' => '포함 범위', 'excluded_scope' => '제외 범위', 'pricing_rationale' => '산정 근거'] as $field => $label)
                <div><h3 class="text-sm font-semibold">{{ $label }}</h3><p class="mt-2 whitespace-pre-wrap break-words text-sm leading-7">{{ $estimate->{$field} }}</p></div>
            @endforeach
        </section>
        <section class="rounded-xl border border-zinc-200 p-5 text-sm dark:border-zinc-700" aria-labelledby="snapshot-heading">
            <h2 id="snapshot-heading" class="font-semibold">운영자 확인: 저장된 가격 기준</h2>
            <p class="mt-2">시간당 {{ number_format($estimate->rate_snapshot['hourly_rate']) }}원 · 적용 할증 {{ number_format($estimate->rate_snapshot['applied_surcharge_bps'] / 100, 2) }}% · 최종 원 단위 올림</p>
            <p class="mt-2 text-zinc-500">저장 이후 가격 기준이 바뀌어도 이 버전의 금액은 바뀌지 않습니다. 수정이 필요하면 새 버전을 작성해 주세요.</p>
        </section>
        @if ($canSubmit)
            <form method="POST" action="{{ route('requests.estimates.submit', [$workRequest, $estimate]) }}" class="space-y-4">
                @csrf
                <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirmed" value="1" required class="mt-1" />포함·제외 범위, 예상시간, 금액, 예정일과 사용 월을 확인했습니다. 제출 후 수정할 수 없으며 고객 승인 대기로 전환됩니다.</label>
                <div class="flex flex-wrap items-center gap-4"><flux:button type="submit" variant="primary">견적 제출</flux:button><a href="{{ route('requests.estimates.create', $workRequest) }}" class="text-sm text-cyan-700 dark:text-cyan-300">새 버전으로 다시 작성</a></div>
            </form>
        @else
            <p class="rounded-lg bg-zinc-100 p-4 text-sm dark:bg-zinc-800">이미 제출되었거나 최신 초안이 아니거나 요청 상태가 변경되어 제출할 수 없습니다. 요청 상세에서 최신 이력을 확인해 주세요.</p>
        @endif
    </div>
</x-layouts::app>
