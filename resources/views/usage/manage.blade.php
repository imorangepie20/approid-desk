<x-layouts::app title="월 마감·조정">
    <div class="mx-auto w-full max-w-5xl space-y-6">
        <header>
            <a href="{{ route('usage.show', $contractMonth) }}" class="inline-flex items-center gap-1 text-sm text-cyan-700 dark:text-cyan-300"><flux:icon.chevron-left class="size-4" />월 사용내역</a>
            <h1 class="mt-3 text-2xl font-bold">{{ $contractMonth->month->format('Y년 n월') }} 마감·조정</h1>
            <p class="mt-2 break-words text-sm text-zinc-500">{{ $contractMonth->serviceContract->company->name }} · 계약 #{{ $contractMonth->service_contract_id }}</p>
        </header>
        @if (session('success'))<p role="status" class="text-sm text-emerald-700 dark:text-emerald-300">{{ session('success') }}</p>@endif
        @if ($errors->any())<ul role="alert" class="text-sm text-red-600 dark:text-red-300">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
        <section aria-labelledby="month-status">
            <h2 id="month-status" class="text-base font-semibold">현재 현황 · {{ $contractMonth->status === 'closed' ? '마감' : '진행 중' }}</h2>
            <dl class="mt-3 grid grid-cols-2 gap-4 border-y border-zinc-200 py-4 dark:border-zinc-700 sm:grid-cols-4">
                @foreach (['provided' => '제공', 'net_usage' => '고객 차감', 'remaining_reserved' => '예약 중', 'available' => '사용 가능'] as $key => $label)
                    <div class="min-w-0"><dt class="text-xs text-zinc-500">{{ $label }}</dt><dd class="mt-1 break-words font-mono text-xl" data-test="manage-{{ $key }}">{{ number_format($totals[$key]) }}분</dd></div>
                @endforeach
            </dl>
        </section>
        <section aria-labelledby="month-close" class="space-y-3">
            <h2 id="month-close" class="text-base font-semibold">월 마감</h2>
            @if ($closure)
                <p class="text-sm">{{ $closure->closed_at->format('Y.m.d H:i') }} · {{ $actors->get($closure->closed_by) }} · 원장 {{ number_format($closure->entry_count) }}건 · 마지막 원장 #{{ $closure->last_entry_id }}</p>
                <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4" data-test="closure-totals">
                    @foreach (['provided' => '마감 시 제공', 'net_usage' => '마감 시 고객 차감', 'remaining_reserved' => '마감 시 예약', 'available' => '마감 시 사용 가능'] as $key => $label)
                        <div class="min-w-0"><dt class="text-xs text-zinc-500">{{ $label }}</dt><dd class="mt-1 break-words font-mono">{{ number_format($closure->totals[$key]) }}분</dd></div>
                    @endforeach
                </dl>
            @else
                <ul class="space-y-1 text-sm text-zinc-500">
                    <li>{{ $ended ? '종료된 월' : '진행 중인 월 · 마감 불가' }}</li>
                    <li>미확정 작업기록 {{ number_format($draftCount) }}건</li>
                    <li>남은 예약 {{ number_format($totals['remaining_reserved']) }}분</li>
                </ul>
                @php($canClose = $ended && $draftCount === 0 && $totals['remaining_reserved'] === 0 && $totals['net_usage'] >= 0 && $totals['available'] >= 0 && $totals['provided'] === $contractMonth->provided_minutes)
                @if ($canClose)
                    <form method="POST" action="{{ route('usage.close', $contractMonth) }}" class="space-y-3">
                        @csrf
                        <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirmed" value="1" required class="mt-1" />마감 후 작업기록 변경 불가에 동의합니다.</label>
                        <flux:button type="submit" variant="primary" icon="lock-closed">월 마감 확정</flux:button>
                    </form>
                @else
                    <p role="status" class="text-sm text-amber-700 dark:text-amber-300">월 종료, 미확정 작업기록, 예약 및 원장 잔액 확인이 필요합니다.</p>
                @endif
            @endif
        </section>
        <section aria-labelledby="month-adjustments" class="space-y-3 border-t border-zinc-200 pt-5 dark:border-zinc-700">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="month-adjustments" class="text-base font-semibold">조정 이력</h2>
                <flux:button href="{{ route('usage.show', [$contractMonth, 'tab' => 'ledger']) }}" icon="list-bullet">원장 조회</flux:button>
            </div>
            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($adjustments as $adjustment)
                    <article class="space-y-2 py-4 text-sm" data-test="adjustment-{{ $adjustment->id }}">
                        <h3 class="font-semibold">{{ $adjustment->type->label() }} · {{ number_format($adjustment->minutes) }}분</h3>
                        <p class="whitespace-pre-wrap break-words">{{ $adjustment->reason }}</p>
                        <p class="break-words text-xs text-zinc-500">{{ $adjustment->approved_at->format('Y.m.d H:i') }} · 승인자 {{ $actors->get($adjustment->approved_by) }} · 관련 원장 #{{ $adjustment->related_entry_id }} · 조정 #{{ $adjustment->id }}</p>
                    </article>
                @empty
                    <p class="py-6 text-sm text-zinc-500">조정 이력이 없습니다.</p>
                @endforelse
            </div>
            {{ $adjustments->links() }}
        </section>
    </div>
</x-layouts::app>
