<x-layouts::app title="작업시간">
    <div class="mx-auto w-full max-w-5xl space-y-6">
        <header>
            <a href="{{ route('requests.show', $workRequest) }}" class="text-sm text-cyan-700 dark:text-cyan-300">요청 상세</a>
            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-2xl font-bold">작업시간</h1>
                <flux:button :href="route('requests.work-logs.create', $workRequest)" variant="primary" icon="plus">작업시간 입력</flux:button>
            </div>
            <p class="mt-2 break-words text-sm text-zinc-500">#{{ $workRequest->id }} {{ $workRequest->title }}</p>
        </header>
        @if (session('success'))<p role="status" class="text-sm text-emerald-700 dark:text-emerald-300">{{ session('success') }}</p>@endif
        @if ($errors->any())
            <div role="alert" class="text-sm text-red-600 dark:text-red-300"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse ($logs as $log)
                <article class="space-y-3 py-5" data-test="work-log-{{ $log->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <h2 class="font-semibold">{{ $log->worked_on->format('Y.m.d') }} · {{ number_format($log->minutes) }}분 · {{ $log->is_billable ? '고객 차감' : '비차감' }}</h2>
                        <span>{{ $log->status === \App\Enums\WorkLogStatus::Draft ? '초안' : '확정' }} · {{ $log->worker->name }}</span>
                    </div>
                    <p class="whitespace-pre-wrap break-words text-sm">{{ $log->description }}</p>
                    @if (! $log->is_billable)<p class="whitespace-pre-wrap break-words text-sm text-zinc-500">비차감 사유: {{ $log->non_billable_reason }}</p>@endif
                    @if ($log->confirmed_at)
                        <p class="text-xs text-zinc-500">확정: {{ $log->confirmer?->name }} · {{ $log->confirmed_at->format('Y.m.d H:i') }}</p>
                    @else
                        @can('update', $log)<flux:button :href="route('requests.work-logs.edit', [$workRequest, $log])" icon="pencil-square" size="sm">수정</flux:button>@endcan
                        @can('confirm', $log)
                            <form method="POST" action="{{ route('requests.work-logs.confirm', [$workRequest, $log]) }}" class="flex flex-wrap items-center gap-3">
                                @csrf
                                <input type="hidden" name="revision" value="{{ $log->revision }}" />
                                <label class="flex min-w-0 items-start gap-2 text-sm"><input type="checkbox" name="confirmed" value="1" required class="mt-1 shrink-0" />{{ $log->is_billable ? '작업 내용과 고객 차감시간을 확인했습니다.' : '작업 내용과 비차감 사유를 확인했습니다.' }}</label>
                                <flux:button type="submit" icon="check" size="sm">{{ $log->is_billable ? '차감 확정' : '비차감 확정' }}</flux:button>
                            </form>
                        @endcan
                    @endif
                </article>
            @empty
                <p class="py-10 text-center text-sm text-zinc-500">등록된 작업시간이 없습니다.</p>
            @endforelse
        </div>
        {{ $logs->links() }}
    </div>
</x-layouts::app>
