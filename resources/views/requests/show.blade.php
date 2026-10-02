<x-layouts::app :title="$workRequest->title">
    @php
        $statusClass = match ($workRequest->status) {
            \App\Enums\WorkRequestStatus::Completed => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300',
            \App\Enums\WorkRequestStatus::Cancelled => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300',
            \App\Enums\WorkRequestStatus::OnHold, \App\Enums\WorkRequestStatus::AwaitingApproval => 'bg-amber-50 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300',
            default => 'bg-cyan-50 text-cyan-700 dark:bg-cyan-400/10 dark:text-cyan-300',
        };
    @endphp

    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6">
        <header>
            <a href="{{ route('requests.index') }}" class="inline-flex items-center gap-1 text-sm font-medium text-zinc-500 hover:text-cyan-700 dark:text-zinc-400 dark:hover:text-cyan-300">
                <flux:icon.chevron-left class="size-4" /> 요청 목록
            </a>
            <div class="mt-3 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div class="min-w-0">
                    <p class="text-xs font-semibold tracking-[0.18em] text-cyan-700 uppercase dark:text-cyan-300">Request #{{ $workRequest->id }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <h1 class="break-words text-2xl font-bold tracking-tight text-zinc-950 dark:text-white">{{ $workRequest->title }}</h1>
                        @if ($workRequest->is_urgent)
                            <span class="inline-flex rounded bg-red-50 px-2 py-1 text-xs font-semibold text-red-700 dark:bg-red-400/10 dark:text-red-300">긴급</span>
                        @endif
                    </div>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ $workRequest->company->name }} · {{ $workRequest->project->name }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span class="inline-flex rounded-full px-3 py-1.5 text-sm font-semibold {{ $statusClass }}">{{ $workRequest->status->label() }}</span>
                    <span class="inline-flex rounded-full bg-zinc-100 px-3 py-1.5 text-sm font-semibold text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">{{ $workRequest->type->label() }} · {{ $workRequest->priority->label() }}</span>
                </div>
            </div>
        </header>

        @if (session('success'))
            <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-400/20 dark:bg-emerald-400/10 dark:text-emerald-200">
                {{ session('success') }}
            </div>
        @endif

        <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="space-y-6">
                <section class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="requirements-heading">
                    <div class="flex items-center gap-3">
                        <div class="grid size-10 shrink-0 place-items-center rounded-lg bg-cyan-50 text-cyan-700 dark:bg-cyan-400/10 dark:text-cyan-300"><flux:icon.document-text class="size-5" /></div>
                        <div><h2 id="requirements-heading" class="font-semibold text-zinc-950 dark:text-white">요구사항</h2><p class="text-sm text-zinc-500 dark:text-zinc-400">요청자가 전달한 작업 내용</p></div>
                    </div>
                    <div class="mt-5 whitespace-pre-wrap break-words text-sm leading-7 text-zinc-800 dark:text-zinc-200">{{ $workRequest->requirements }}</div>
                </section>

                <section id="comments" class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="comments-heading" data-test="request-comments">
                    <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-700/80">
                        <div class="flex items-center justify-between gap-3"><div><h2 id="comments-heading" class="font-semibold text-zinc-950 dark:text-white">댓글</h2><p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">요청과 관련된 확인 내용과 답변을 남깁니다.</p></div><span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">{{ $workRequest->comments->count() }}개</span></div>
                    </div>

                    @if ($canComment)
                        <form method="POST" action="{{ route('requests.comments.store', $workRequest) }}" class="border-b border-zinc-200 p-5 dark:border-zinc-700/80">
                            @csrf
                            <label for="comment-body" class="text-sm font-medium text-zinc-800 dark:text-zinc-200">새 댓글</label>
                            <textarea id="comment-body" name="body" rows="4" required maxlength="10000" placeholder="확인이 필요한 내용이나 진행에 도움이 되는 정보를 남겨 주세요." class="mt-2 w-full resize-y rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm leading-6 text-zinc-950 outline-none placeholder:text-zinc-400 focus:border-cyan-600 focus:ring-2 focus:ring-cyan-600/20 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white">{{ old('body') }}</textarea>
                            @error('body') <span class="mt-1 block text-xs text-red-600 dark:text-red-300">{{ $message }}</span> @enderror
                            <div class="mt-3 flex justify-end"><flux:button type="submit" variant="primary">댓글 등록</flux:button></div>
                        </form>
                    @endif

                    @if ($workRequest->comments->isEmpty())
                        <div class="px-5 py-12 text-center" data-test="comment-empty-state"><div class="mx-auto grid size-11 place-items-center rounded-full bg-zinc-100 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-300"><flux:icon.chat-bubble-left-right class="size-5" /></div><p class="mt-3 font-medium text-zinc-900 dark:text-white">아직 댓글이 없습니다.</p><p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">첫 댓글로 확인할 내용을 남겨 보세요.</p></div>
                    @else
                        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700/80">
                            @foreach ($workRequest->comments as $comment)
                                <li class="px-5 py-4">
                                    <div class="flex items-start justify-between gap-4"><div class="min-w-0"><p class="font-semibold text-zinc-900 dark:text-white">{{ $comment->author->name }}</p><p class="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-zinc-700 dark:text-zinc-200">{{ $comment->body }}</p></div><time datetime="{{ $comment->created_at?->toAtomString() }}" class="shrink-0 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $comment->created_at?->format('Y.m.d H:i') }}</time></div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="activities-heading" data-test="request-activities">
                    <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-700/80"><h2 id="activities-heading" class="font-semibold text-zinc-950 dark:text-white">변경 이력</h2><p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">요청에 기록된 주요 변경을 최신순으로 표시합니다.</p></div>
                    <ol class="divide-y divide-zinc-200 dark:divide-zinc-700/80">
                        @foreach ($workRequest->activities as $activity)
                            <li class="flex gap-3 px-5 py-4">
                                <span class="mt-1.5 size-2 shrink-0 rounded-full bg-cyan-600 ring-4 ring-cyan-50 dark:bg-cyan-300 dark:ring-cyan-400/10"></span>
                                <div class="min-w-0 flex-1"><div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between"><p class="font-medium text-zinc-900 dark:text-white">{{ $activity->summary }}</p><time datetime="{{ $activity->occurred_at->toAtomString() }}" class="shrink-0 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $activity->occurred_at->format('Y.m.d H:i') }}</time></div><p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $activity->type->label() }} · {{ $activity->actor?->name ?? '시스템' }}</p></div>
                            </li>
                        @endforeach
                    </ol>
                </section>
            </div>

            <aside class="space-y-6 xl:sticky xl:top-6">
                <section class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="request-info-heading">
                    <h2 id="request-info-heading" class="font-semibold text-zinc-950 dark:text-white">요청 정보</h2>
                    <dl class="mt-4 divide-y divide-zinc-200 text-sm dark:divide-zinc-700/80">
                        <div class="py-3 first:pt-0"><dt class="text-xs text-zinc-500 dark:text-zinc-400">프로젝트</dt><dd class="mt-1 font-medium text-zinc-900 dark:text-white"><a href="{{ route('projects.show', $workRequest->project) }}" class="hover:text-cyan-700 dark:hover:text-cyan-300">{{ $workRequest->project->name }}</a></dd></div>
                        <div class="py-3"><dt class="text-xs text-zinc-500 dark:text-zinc-400">요청자</dt><dd class="mt-1 text-zinc-800 dark:text-zinc-200">{{ $workRequest->submitter->name }}</dd></div>
                        <div class="py-3"><dt class="text-xs text-zinc-500 dark:text-zinc-400">담당자</dt><dd class="mt-1 text-zinc-800 dark:text-zinc-200">{{ $workRequest->assignee?->name ?? '미지정' }}</dd></div>
                        <div class="py-3"><dt class="text-xs text-zinc-500 dark:text-zinc-400">희망 완료일</dt><dd class="mt-1 font-mono text-zinc-800 dark:text-zinc-200">{{ $workRequest->desired_due_date?->format('Y.m.d') ?? '미지정' }}</dd></div>
                        <div class="py-3"><dt class="text-xs text-zinc-500 dark:text-zinc-400">접수 경로</dt><dd class="mt-1 text-zinc-800 dark:text-zinc-200">{{ $workRequest->intake_channel->label() }}</dd></div>
                        <div class="py-3"><dt class="text-xs text-zinc-500 dark:text-zinc-400">실제 요청 일시</dt><dd class="mt-1 font-mono text-xs text-zinc-800 dark:text-zinc-200">{{ $workRequest->requested_at->format('Y.m.d H:i') }}</dd></div>
                        <div class="py-3 pb-0"><dt class="text-xs text-zinc-500 dark:text-zinc-400">시스템 등록 일시</dt><dd class="mt-1 font-mono text-xs text-zinc-800 dark:text-zinc-200">{{ $workRequest->registered_at->format('Y.m.d H:i') }}</dd></div>
                    </dl>
                </section>

                @if ($workRequest->intake_channel !== \App\Enums\IntakeChannel::Web)
                    <section class="rounded-xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-400/20 dark:bg-amber-400/10" aria-labelledby="intake-detail-heading">
                        <h2 id="intake-detail-heading" class="font-semibold text-amber-950 dark:text-amber-100">대리 접수 기록</h2>
                        <dl class="mt-4 grid gap-4 text-sm"><div><dt class="text-xs text-amber-700 dark:text-amber-300">원문 출처</dt><dd class="mt-1 break-words text-amber-950 dark:text-amber-100">{{ $workRequest->source_reference }}</dd></div><div><dt class="text-xs text-amber-700 dark:text-amber-300">접수 요약</dt><dd class="mt-1 whitespace-pre-wrap break-words text-amber-950 dark:text-amber-100">{{ $workRequest->intake_summary }}</dd></div>@if ($workRequest->late_entry_reason)<div><dt class="text-xs text-amber-700 dark:text-amber-300">지연 등록 사유</dt><dd class="mt-1 whitespace-pre-wrap break-words text-amber-950 dark:text-amber-100">{{ $workRequest->late_entry_reason }}</dd></div>@endif</dl>
                    </section>
                @endif
            </aside>
        </div>
    </div>
</x-layouts::app>
