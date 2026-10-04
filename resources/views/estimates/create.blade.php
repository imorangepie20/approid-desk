<x-layouts::app title="견적 작성">
    @php($inputClass = 'w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white')
    <div class="mx-auto w-full max-w-5xl space-y-6">
        <header>
            <a href="{{ route('requests.show', $workRequest) }}" class="text-sm text-cyan-700 dark:text-cyan-300">← 요청 상세</a>
            <h1 class="mt-3 text-2xl font-bold">견적 작성</h1>
            <p class="mt-2 break-words text-sm text-zinc-500">#{{ $workRequest->id }} {{ $workRequest->title }} · {{ $workRequest->type->label() }}{{ $workRequest->is_urgent ? ' · 주요 업무 장애' : '' }}</p>
        </header>
        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:bg-red-950 dark:text-red-200"><ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-[#141B2D]" aria-labelledby="pricing-heading">
            <h2 id="pricing-heading" class="font-semibold">오늘 적용되는 가격 기준 · {{ today()->format('Y.m.d') }}</h2>
            <p class="mt-2 text-sm text-zinc-500">요청 유형과 선택한 난이도로 기준을 결정합니다. 금액은 서버에서 긴급 할증을 적용한 뒤 원 단위로 올림합니다. 기준이 없거나 중복되면 저장할 수 없습니다.</p>
            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                @forelse ($rules as $rule)
                    <div class="rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                        <p class="font-semibold">{{ $rule->difficulty->label() }} · 시간당 {{ number_format($rule->hourly_rate) }}원</p>
                        <p class="mt-1">긴급 할증 {{ number_format($rule->urgent_surcharge_bps / 100, 2) }}%</p>
                        <p class="mt-2 whitespace-pre-wrap break-words text-zinc-500">{{ $rule->urgent_criteria }}</p>
                    </div>
                @empty
                    <p class="text-sm text-amber-700 dark:text-amber-300">적용 가능한 가격 기준이 없습니다. 가격 기준 등록 후 작성해 주세요.</p>
                @endforelse
            </div>
        </section>
        <form method="POST" action="{{ route('requests.estimates.store', $workRequest) }}" class="space-y-5 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-[#141B2D]">
            @csrf
            <input type="hidden" name="base_version" value="{{ old('base_version', $latest?->version ?? 0) }}" />
            <p class="text-sm text-zinc-500">저장하면 새 버전의 내부 초안을 만들고 미리보기로 이동합니다. 고객에게는 제출 후 공개되며 기존 버전과 승인 이력은 덮어쓰지 않습니다. 승인된 요청의 재견적은 새 견적 승인 시 기존 남은 예약을 교체합니다.</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="grid gap-2 text-sm font-medium">난이도
                    <select name="difficulty" required class="{{ $inputClass }}">@foreach ($difficulties as $difficulty)<option value="{{ $difficulty->value }}" @selected(old('difficulty', $latest?->rate_snapshot['difficulty'] ?? 'normal') === $difficulty->value)>{{ $difficulty->label() }}</option>@endforeach</select>
                </label>
                <label class="grid gap-2 text-sm font-medium">예상시간 (분)
                    <input type="number" name="estimated_minutes" min="1" max="10000000" step="1" required value="{{ old('estimated_minutes', $latest?->estimated_minutes) }}" class="{{ $inputClass }}" />
                </label>
                <label class="grid gap-2 text-sm font-medium">예정일
                    <input type="date" name="scheduled_on" min="{{ today()->toDateString() }}" required value="{{ old('scheduled_on', $latest?->scheduled_on->toDateString()) }}" class="{{ $inputClass }}" />
                </label>
                <label class="grid gap-2 text-sm font-medium">사용 대상 월
                    <input type="month" name="usage_month" required value="{{ old('usage_month', $latest?->usage_month->format('Y-m') ?? today()->format('Y-m')) }}" class="{{ $inputClass }}" />
                </label>
            </div>
            @foreach (['included_scope' => '포함 범위', 'excluded_scope' => '제외 범위', 'rationale' => '고객 공개 산정 근거'] as $field => $label)
                <label class="grid gap-2 text-sm font-medium">{{ $label }}
                    <textarea name="{{ $field }}" rows="4" maxlength="10000" required class="{{ $inputClass }}">{{ old($field, $latest?->{$field === 'rationale' ? 'pricing_rationale' : $field}) }}</textarea>
                </label>
            @endforeach
            <p class="text-sm text-zinc-500">산정 근거는 제출 견적에 포함되어 고객에게 공개됩니다. 승인 시 해당 월의 계약시간이 예약되므로 계약과 제공시간도 확인해 주세요.</p>
            <flux:button type="submit" variant="primary" :disabled="$rules->isEmpty()">초안 저장 후 미리보기</flux:button>
        </form>
    </div>
</x-layouts::app>
