@props(['requests', 'count'])

<section class="overflow-hidden rounded-lg border border-red-200 bg-white shadow-sm dark:border-red-400/30 dark:bg-[#141B2D]" aria-labelledby="major-incidents-heading" data-test="major-incidents">
    <div class="flex flex-col gap-3 border-b border-red-100 bg-red-50/70 px-5 py-4 dark:border-red-400/20 dark:bg-red-400/[0.07] sm:flex-row sm:items-center sm:justify-between">
        <div class="flex min-w-0 items-start gap-3">
            <div class="grid size-9 shrink-0 place-items-center rounded-lg bg-red-100 text-red-700 dark:bg-red-400/15 dark:text-red-300">
                <flux:icon.exclamation-triangle class="size-5" />
            </div>
            <div class="min-w-0">
                <h2 id="major-incidents-heading" class="font-semibold text-zinc-950 dark:text-white">주요 업무 장애</h2>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">열린 장애를 일반 요청보다 먼저, 오래 접수된 순서로 확인합니다.</p>
            </div>
        </div>
        <div class="flex shrink-0 items-center gap-3">
            <span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-800 dark:bg-red-400/15 dark:text-red-200" data-test="major-incident-count">
                {{ number_format($count) }}건
            </span>
            <a href="{{ route('requests.index', ['major_incident' => 1]) }}" class="text-sm font-semibold text-cyan-700 hover:text-cyan-800 dark:text-cyan-300 dark:hover:text-cyan-200">전체 보기</a>
        </div>
    </div>

    @if ($requests->isEmpty())
        <div class="flex items-center gap-3 px-5 py-4" data-test="major-incidents-empty">
            <flux:icon.check-circle class="size-5 shrink-0 text-emerald-600 dark:text-emerald-300" />
            <p class="text-sm text-zinc-600 dark:text-zinc-300">현재 확인할 주요 업무 장애가 없습니다.</p>
        </div>
    @else
        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700/80" data-test="major-incident-list">
            @foreach ($requests as $workRequest)
                @php
                    $responseTargetAt = $workRequest->majorIncidentFirstResponseTargetAt();
                    $responseStatus = $workRequest->majorIncidentFirstResponseTargetStatus();
                    $responseTargetElapsed = $responseStatus === \App\Enums\MajorIncidentResponseStatus::Overdue;
                    $responseMet = $responseStatus === \App\Enums\MajorIncidentResponseStatus::Met;
                @endphp
                <li>
                    <a href="{{ route('requests.show', $workRequest) }}" class="flex flex-col gap-3 px-5 py-4 transition-colors hover:bg-red-50/50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-cyan-600 dark:hover:bg-red-400/[0.05] sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex rounded bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-800 dark:bg-red-400/15 dark:text-red-200">주요 장애</span>
                                <span class="inline-flex rounded bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">{{ $workRequest->status->label() }}</span>
                            </div>
                            <p class="mt-2 break-words font-semibold text-zinc-950 dark:text-white">{{ $workRequest->title }}</p>
                            <p class="mt-1 truncate text-sm text-zinc-500 dark:text-zinc-400">{{ $workRequest->company->name }} · {{ $workRequest->project->name }}</p>
                        </div>
                        <div class="shrink-0 text-left sm:text-right" data-test="major-incident-response-target">
                            <p class="text-xs font-semibold {{ $responseMet ? 'text-emerald-700 dark:text-emerald-300' : ($responseTargetElapsed ? 'text-red-700 dark:text-red-300' : 'text-amber-700 dark:text-amber-300') }}">
                                {{ $responseStatus?->label() }}
                            </p>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">내부 최초 응답 목표</p>
                            <time datetime="{{ $responseTargetAt?->toAtomString() }}" class="mt-0.5 block font-mono text-sm text-zinc-700 dark:text-zinc-200">{{ $responseTargetAt?->format('Y.m.d H:i') }}</time>
                            <p class="mt-1 text-xs font-medium text-zinc-500 dark:text-zinc-400">계약 보장 아님</p>
                            @if ($workRequest->firstResponseEvent)
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">응답 <time datetime="{{ $workRequest->firstResponseEvent->occurred_at->toAtomString() }}" class="font-mono">{{ $workRequest->firstResponseEvent->occurred_at->format('Y.m.d H:i') }}</time></p>
                            @endif
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</section>
