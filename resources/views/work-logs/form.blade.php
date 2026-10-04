<x-layouts::app title="작업시간 입력">
    @php($inputClass = 'w-full min-w-0 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white')
    <div class="mx-auto w-full max-w-3xl space-y-6">
        <header>
            <a href="{{ route('requests.work-logs.index', $workRequest) }}" class="text-sm text-cyan-700 dark:text-cyan-300">작업시간 목록</a>
            <h1 class="mt-3 text-2xl font-bold">{{ $log ? '작업시간 수정' : '작업시간 입력' }}</h1>
            <p class="mt-2 break-words text-sm text-zinc-500">#{{ $workRequest->id }} {{ $workRequest->title }}</p>
        </header>
        @if ($errors->any())
            <div role="alert" class="text-sm text-red-600 dark:text-red-300"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <form method="POST" action="{{ $log ? route('requests.work-logs.update', [$workRequest, $log]) : route('requests.work-logs.store', $workRequest) }}" class="space-y-5">
            @csrf
            @if ($log)
                @method('PATCH')
                <input type="hidden" name="revision" value="{{ old('revision', $log->revision) }}" />
            @endif
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="grid min-w-0 gap-2 text-sm font-medium">작업일
                    <input type="date" name="worked_on" max="{{ today()->toDateString() }}" required value="{{ old('worked_on', $log?->worked_on->toDateString() ?? today()->toDateString()) }}" class="{{ $inputClass }}" />
                </label>
                <label class="grid min-w-0 gap-2 text-sm font-medium">작업시간 (분)
                    <input type="number" name="minutes" min="1" max="1440" step="1" required value="{{ old('minutes', $log?->minutes) }}" class="{{ $inputClass }}" />
                </label>
            </div>
            <label class="grid gap-2 text-sm font-medium">작업 내용
                <textarea name="description" rows="5" maxlength="10000" required class="{{ $inputClass }}">{{ old('description', $log?->description) }}</textarea>
            </label>
            <label class="grid gap-2 text-sm font-medium">시간 구분
                <select name="is_billable" class="{{ $inputClass }}" required>
                    <option value="1" @selected((string) old('is_billable', (int) ($log?->is_billable ?? true)) === '1')>고객 차감</option>
                    <option value="0" @selected((string) old('is_billable', (int) ($log?->is_billable ?? true)) === '0')>비차감</option>
                </select>
            </label>
            <label class="grid gap-2 text-sm font-medium">비차감 사유 (비차감 선택 시 필수)
                <textarea name="non_billable_reason" rows="3" maxlength="10000" class="{{ $inputClass }}">{{ old('non_billable_reason', $log?->non_billable_reason) }}</textarea>
            </label>
            <div class="flex flex-wrap gap-3">
                <flux:button type="submit" variant="primary" icon="document-check">초안 저장</flux:button>
                <flux:button :href="route('requests.work-logs.index', $workRequest)">취소</flux:button>
            </div>
        </form>
    </div>
</x-layouts::app>
