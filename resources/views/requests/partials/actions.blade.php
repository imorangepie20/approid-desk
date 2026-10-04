<section class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700/80 dark:bg-[#141B2D]" aria-labelledby="request-actions-heading" data-test="request-actions">
    <h2 id="request-actions-heading" class="font-semibold">요청 작업</h2>
    @if ($errors->any())
        <div role="alert" class="mt-3 text-sm text-red-700 dark:text-red-300"><ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    @forelse ($availableTransitions as $to)
        @php($requiresReason = (new \App\Services\WorkRequestTransitionRules)->requiresReason($workRequest->status, $to))
        @php($isFreeRework = $workRequest->status === \App\Enums\WorkRequestStatus::Completed && $to === \App\Enums\WorkRequestStatus::InProgress)
        <details class="mt-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" data-test="transition-{{ $to->value }}">
            <summary class="cursor-pointer text-sm font-semibold">{{ $isFreeRework ? '무상 재작업 시작' : $to->label().($to === \App\Enums\WorkRequestStatus::InProgress && $workRequest->status === \App\Enums\WorkRequestStatus::AwaitingReview ? ' · 수정 요청' : '(으)로 변경') }}</summary>
            <form method="POST" action="{{ route('requests.transition', $workRequest) }}" class="mt-3 space-y-3">
                @csrf
                <input type="hidden" name="status" value="{{ $to->value }}" />
                <input type="hidden" name="expected_status" value="{{ $workRequest->status->value }}" />
                <input type="hidden" name="expected_change" value="{{ $lastStatusChange }}" />
                @if ($isFreeRework)<input type="hidden" name="is_free_rework" value="1" />@endif
                @if ($isFreeRework)<p class="text-xs leading-5 text-amber-700 dark:text-amber-300">최초 합의 기능의 오류를 고객 시간 차감 없이 수정합니다. 이후 작업기록은 고객 차감 안 함과 귀책 사유를 사용해야 합니다.</p>@endif
                <label class="block text-sm" for="reason-{{ $to->value }}">{{ $isFreeRework ? '귀책 및 재작업 사유' : '변경 사유' }} {{ $requiresReason ? '(필수)' : '(선택)' }}</label>
                <textarea id="reason-{{ $to->value }}" name="reason" rows="3" maxlength="10000" @required($requiresReason) class="w-full rounded-lg border border-zinc-300 bg-white p-2 text-sm dark:border-zinc-600 dark:bg-zinc-900">{{ old('status') === $to->value ? old('reason') : '' }}</textarea>
                <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="confirmed" value="1" required class="mt-1" />{{ $isFreeRework ? '고객 시간 차감 없는 무상 재작업 시작' : $workRequest->status->label().' → '.$to->label().' 변경' }}을 확인했습니다.</label>
                <flux:button type="submit" variant="primary">변경 확정</flux:button>
            </form>
        </details>
    @empty
        <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">현재 역할과 상태에서 직접 변경할 수 있는 작업이 없습니다.</p>
    @endforelse
    <p class="mt-4 text-xs leading-5 text-zinc-500 dark:text-zinc-400">견적 제출·승인은 견적 화면에서 처리합니다. 승인된 요청의 재견적은 새 승인과 함께 기존 남은 예약을 교체합니다. 승인 후 취소와 검수 완료는 남은 예약 반환과 함께 처리합니다. 완료 후 최초 합의 기능의 오류는 무상 재작업으로 재개하고 비차감 작업기록과 귀책 사유를 남깁니다.</p>
</section>
