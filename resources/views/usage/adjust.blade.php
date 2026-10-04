<x-layouts::app title="시간 조정">
    <div class="mx-auto w-full max-w-3xl space-y-6">
        <header>
            <a href="{{ route('usage.show', [$contractMonth, 'tab' => 'ledger']) }}" class="inline-flex items-center gap-1 text-sm text-cyan-700 dark:text-cyan-300"><flux:icon.chevron-left class="size-4" />시간 원장</a>
            <h1 class="mt-3 text-2xl font-bold">시간 조정</h1>
            <p class="mt-2 break-words text-sm text-zinc-500">{{ $contractMonth->month->format('Y년 n월') }} · {{ $contractMonth->serviceContract->company->name }} · 계약 #{{ $contractMonth->service_contract_id }}</p>
        </header>
        <section class="space-y-2 border-y border-zinc-200 py-4 text-sm dark:border-zinc-700" aria-label="관련 원장">
            <h2 class="font-semibold">관련 원장 #{{ $entry->id }} · {{ $entry->type->label() }} · {{ number_format($entry->minutes) }}분</h2>
            <p class="whitespace-pre-wrap break-words">{{ $entry->reason }}</p>
            <p>{{ $entry->occurred_at->format('Y.m.d H:i') }} · {{ $contractMonth->status === 'closed' ? '마감 월' : '진행 중인 월' }}</p>
            <p>현재 사용 가능 <strong class="font-mono">{{ number_format($totals['available']) }}분</strong></p>
        </section>
        @if ($errors->any())<ul role="alert" class="text-sm text-red-600 dark:text-red-300">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
        <form method="POST" action="{{ route('usage.adjust.store', [$contractMonth, $entry]) }}" class="space-y-5">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $requestKey) }}" />
            <flux:select name="type" label="조정 종류" required>
                <option value="">선택</option>
                @foreach ([\App\Enums\TimeLedgerType::AdjustIncrease, \App\Enums\TimeLedgerType::AdjustDecrease] as $type)
                    <option value="{{ $type->value }}" @selected(old('type') === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </flux:select>
            <flux:input name="minutes" type="number" min="1" max="10000000" step="1" label="조정 시간 (분)" value="{{ old('minutes') }}" required />
            <flux:textarea name="reason" label="조정 사유" rows="5" maxlength="10000" required>{{ old('reason') }}</flux:textarea>
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirmed" value="1" required class="mt-1" />관련 원장, 조정 시간 및 사유를 확인하고 승인합니다.</label>
            <div class="flex flex-wrap gap-3">
                <flux:button type="submit" variant="primary" icon="check">조정 승인</flux:button>
                <flux:button href="{{ route('usage.manage', $contractMonth) }}">취소</flux:button>
            </div>
        </form>
    </div>
</x-layouts::app>
