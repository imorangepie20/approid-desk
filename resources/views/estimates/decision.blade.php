<x-layouts::app title="견적 확인 및 승인">
    <div class="mx-auto w-full max-w-4xl space-y-6">
        <header>
            <a href="{{ route('requests.show', $workRequest) }}" class="text-sm text-cyan-700 dark:text-cyan-300">← 요청 상세</a>
            <h1 class="mt-3 text-2xl font-bold">견적 v{{ $estimate->version }} 확인</h1>
            <p class="mt-2 break-words text-sm text-zinc-500">{{ $workRequest->title }} · 제출일 {{ $estimate->submitted_at->format('Y.m.d H:i') }}</p>
        </header>
        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:bg-red-950 dark:text-red-200"><ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <section class="space-y-5 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-[#141B2D]" aria-labelledby="decision-estimate-heading">
            <h2 id="decision-estimate-heading" class="font-semibold">승인 대상 견적</h2>
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
        @if ($canDecide)
            <section class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700" aria-labelledby="approve-heading">
                <h2 id="approve-heading" class="font-semibold">견적 승인</h2>
                <p class="mt-2 text-sm text-zinc-500">승인하면 기존 견적의 남은 예약을 해제한 뒤 사용 대상 월에 새 예상시간을 예약하고 작업 대기로 전환합니다. 계약 서명 확인과 사용 가능한 월 계약시간이 필요하며, 처리 중 하나라도 실패하면 기존 예약을 유지합니다. 승인 시각과 접속 정보가 승인 증빙으로 저장됩니다.</p>
                <form method="POST" action="{{ route('requests.estimates.approve', [$workRequest, $estimate]) }}" class="mt-4 space-y-4">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}" />
                    <input type="hidden" name="approval_text" value="{{ $approvalText }}" />
                    <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirmed" value="1" required class="mt-1" />{{ $approvalText }}</label>
                    <flux:button type="submit" variant="primary">견적 승인</flux:button>
                </form>
            </section>
            <section class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700" aria-labelledby="revision-heading">
                <h2 id="revision-heading" class="font-semibold">수정 요청</h2>
                <p class="mt-2 text-sm text-zinc-500">승인하지 않고 견적 중으로 돌려보냅니다. 수정 요청 사유는 이력에 남으며 시간은 예약되지 않습니다.</p>
                <form method="POST" action="{{ route('requests.estimates.revision', [$workRequest, $estimate]) }}" class="mt-4 space-y-4">
                    @csrf
                    <label class="grid gap-2 text-sm font-medium">수정 요청 사유
                        <textarea name="reason" rows="4" required maxlength="10000" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">{{ old('reason') }}</textarea>
                    </label>
                    <flux:button type="submit">수정 요청 보내기</flux:button>
                </form>
            </section>
        @else
            <p role="status" class="rounded-lg bg-zinc-100 p-4 text-sm dark:bg-zinc-800">현재 승인 또는 수정 요청할 수 없는 견적입니다. 이미 처리되었거나 최신 버전이 아니거나 요청 상태가 변경되었습니다. 요청 상세에서 최신 이력을 확인해 주세요.</p>
        @endif
    </div>
</x-layouts::app>
