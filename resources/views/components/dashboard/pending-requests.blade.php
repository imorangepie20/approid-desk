@props(['title', 'status', 'requests', 'count'])

<section class="min-w-0 overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="{{ $status->value }}-heading" data-test="pending-{{ $status->value }}">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-zinc-200 p-5 dark:border-zinc-700/80">
        <div>
            <h2 id="{{ $status->value }}-heading" class="font-semibold text-zinc-950 dark:text-white">{{ $title }}</h2>
            <p class="mt-1 text-xs leading-5 text-zinc-500 dark:text-zinc-400">요청일이 오래된 순으로 최대 5건 · 전체 {{ number_format($count) }}건</p>
        </div>
        <a href="{{ route('requests.index', ['status' => $status->value]) }}" class="shrink-0 text-sm font-semibold text-cyan-700 dark:text-cyan-300" aria-label="{{ $title }} 전체 보기">전체 보기 →</a>
    </div>
    @if ($requests->isEmpty())
        <p class="px-5 py-10 text-center text-sm text-zinc-500 dark:text-zinc-400" data-test="empty-{{ $status->value }}">{{ $title }} 중인 요청이 없습니다.</p>
    @else
        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700/80">
            @foreach ($requests as $workRequest)
                <li class="min-w-0 p-5" data-test="pending-request-{{ $workRequest->id }}">
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="rounded bg-amber-50 px-2 py-1 font-medium text-amber-800 dark:bg-amber-400/10 dark:text-amber-200">{{ $title }}</span>
                        @if ($workRequest->is_urgent)<span class="font-semibold text-red-700 dark:text-red-300">주요 장애</span>@endif
                        <span class="text-zinc-500 dark:text-zinc-400">#{{ $workRequest->id }}</span>
                    </div>
                    <a href="{{ route('requests.show', $workRequest) }}" class="mt-2 block break-words font-semibold text-cyan-700 hover:underline dark:text-cyan-300">{{ $workRequest->title }}</a>
                    <p class="mt-1 break-words text-sm text-zinc-500 dark:text-zinc-400">{{ $workRequest->company->name }} · {{ $workRequest->project->name }}</p>
                    <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">요청일 <time datetime="{{ $workRequest->requested_at->toAtomString() }}" class="font-mono">{{ $workRequest->requested_at->format('Y.m.d H:i') }}</time></p>
                </li>
            @endforeach
        </ul>
    @endif
</section>
