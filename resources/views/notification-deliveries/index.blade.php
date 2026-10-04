<x-layouts::app title="알림 발송">
    @php($inputClass = 'w-full min-w-0 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white')
    <div class="mx-auto w-full max-w-7xl space-y-6">
        <header class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold">알림 발송</h1>
                <p class="mt-2 text-sm text-zinc-500">채널별 전달 상태</p>
            </div>
            <dl class="grid w-full grid-cols-3 gap-3 text-xs sm:w-auto sm:flex sm:gap-5 sm:text-sm">
                @foreach (\App\Enums\NotificationDeliveryStatus::cases() as $status)
                    <div class="min-w-0 sm:flex sm:items-baseline sm:gap-1">
                        <dt class="block text-zinc-500">{{ $status->label() }}</dt>
                        <dd class="mt-1 block font-mono sm:mt-0">{{ number_format((int) ($counts[$status->value] ?? 0)) }}</dd>
                    </div>
                @endforeach
            </dl>
        </header>

        @if (session('success'))
            <p role="status" class="text-sm text-emerald-700 dark:text-emerald-300">{{ session('success') }}</p>
        @endif
        @if ($errors->any())
            <ul role="alert" class="text-sm text-red-600 dark:text-red-300">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        @endif

        <form method="GET" action="{{ route('notification-deliveries.index') }}" class="flex flex-col gap-3 border-y border-zinc-200 py-4 dark:border-zinc-700 sm:flex-row sm:items-end">
            <label class="grid min-w-0 gap-2 text-sm sm:w-48">상태
                <select name="status" class="{{ $inputClass }}">
                    @foreach (\App\Enums\NotificationDeliveryStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected($selectedStatus === $status)>{{ $status->label() }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid min-w-0 gap-2 text-sm sm:w-48">채널
                <select name="channel" class="{{ $inputClass }}">
                    <option value="">전체 채널</option>
                    @foreach (\App\Enums\NotificationChannel::cases() as $channel)
                        <option value="{{ $channel->value }}" @selected($selectedChannel === $channel)>{{ $channel === \App\Enums\NotificationChannel::Database ? '데이터베이스' : '메일' }}</option>
                    @endforeach
                </select>
            </label>
            <flux:button type="submit" icon="funnel">조회</flux:button>
        </form>

        @if ($deliveries->isEmpty())
            <p class="py-12 text-center text-sm text-zinc-500" data-test="notification-delivery-empty">선택한 조건의 알림 발송 기록이 없습니다.</p>
        @else
            <div class="hidden lg:block">
                <table class="w-full table-fixed text-left text-sm" data-test="notification-delivery-table">
                    <caption class="sr-only">알림 발송 기록</caption>
                    <thead class="border-b border-zinc-200 text-xs text-zinc-500 dark:border-zinc-700">
                        <tr>
                            <th scope="col" class="w-[28%] py-3 pr-4">알림 · 대상</th>
                            <th scope="col" class="w-[22%] px-2 py-3">수신자</th>
                            <th scope="col" class="w-[12%] px-2 py-3">채널</th>
                            <th scope="col" class="w-[16%] px-2 py-3">상태</th>
                            <th scope="col" class="w-[14%] px-2 py-3">마지막 처리</th>
                            <th scope="col" class="w-[8%] py-3 pl-2 text-right"><span class="sr-only">작업</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($deliveries as $delivery)
                            @php($targetTitle = $delivery->workRequest?->title ?? ($delivery->delivery_data['request_title'] ?? '업무 알림'))
                            @php($targetUrl = $delivery->workRequest ? route('requests.show', $delivery->workRequest) : route('dashboard'))
                            <tr data-test="notification-delivery-{{ $delivery->id }}">
                                <th scope="row" class="break-words py-4 pr-4 font-normal">
                                    <p class="font-semibold">{{ $delivery->notification_type->label() }}</p>
                                    <a href="{{ $targetUrl }}" class="mt-1 block text-xs text-cyan-700 dark:text-cyan-300">{{ $targetTitle }}</a>
                                </th>
                                <td class="break-words px-2 py-4">
                                    <p>{{ $delivery->notifiable?->name ?? '삭제된 사용자' }}</p>
                                    <p class="mt-1 text-xs text-zinc-500">{{ $delivery->notifiable?->email ?? '#'.$delivery->notifiable_id }}</p>
                                </td>
                                <td class="px-2 py-4">{{ $delivery->channel === \App\Enums\NotificationChannel::Database ? '데이터베이스' : '메일' }}</td>
                                <td class="break-words px-2 py-4">
                                    <p class="font-medium {{ $delivery->status === \App\Enums\NotificationDeliveryStatus::Failed ? 'text-red-700 dark:text-red-300' : '' }}">{{ $delivery->status->label() }}</p>
                                    @if ($delivery->failure_code)<p class="mt-1 text-xs text-zinc-500">{{ $delivery->failure_code->label() }}</p>@endif
                                    <p class="mt-1 text-xs text-zinc-500">시도 {{ number_format($delivery->attempt_count) }}회 · 재시도 {{ number_format($delivery->retries_count) }}회</p>
                                </td>
                                <td class="break-words px-2 py-4 text-xs">
                                    <p>{{ ($delivery->failed_at ?? $delivery->sent_at ?? $delivery->last_attempted_at ?? $delivery->created_at)?->format('Y.m.d H:i') }}</p>
                                    @if ($delivery->latestRetry)<p class="mt-1 text-zinc-500">{{ $delivery->latestRetry->requester->name }}</p>@endif
                                </td>
                                <td class="py-4 pl-2 text-right">
                                    @if ($delivery->status === \App\Enums\NotificationDeliveryStatus::Failed)
                                        <form method="POST" action="{{ route('notification-deliveries.retry', $delivery) }}">@csrf
                                            <flux:button type="submit" size="sm" icon="arrow-path">재시도</flux:button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="divide-y divide-zinc-200 dark:divide-zinc-700 lg:hidden" data-test="notification-delivery-mobile">
                @foreach ($deliveries as $delivery)
                    @php($targetTitle = $delivery->workRequest?->title ?? ($delivery->delivery_data['request_title'] ?? '업무 알림'))
                    @php($targetUrl = $delivery->workRequest ? route('requests.show', $delivery->workRequest) : route('dashboard'))
                    <article class="space-y-3 py-4 text-sm" data-test="notification-delivery-{{ $delivery->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="break-words font-semibold">{{ $delivery->notification_type->label() }}</h2>
                                <a href="{{ $targetUrl }}" class="mt-1 block break-words text-xs text-cyan-700 dark:text-cyan-300">{{ $targetTitle }}</a>
                            </div>
                            <span class="shrink-0 text-xs {{ $delivery->status === \App\Enums\NotificationDeliveryStatus::Failed ? 'text-red-700 dark:text-red-300' : '' }}">{{ $delivery->status->label() }}</span>
                        </div>
                        <dl class="grid grid-cols-2 gap-3">
                            <div class="min-w-0"><dt class="text-xs text-zinc-500">수신자</dt><dd class="mt-1 break-words">{{ $delivery->notifiable?->name ?? '삭제된 사용자' }}</dd></div>
                            <div class="min-w-0"><dt class="text-xs text-zinc-500">채널</dt><dd class="mt-1">{{ $delivery->channel === \App\Enums\NotificationChannel::Database ? '데이터베이스' : '메일' }}</dd></div>
                            <div class="min-w-0"><dt class="text-xs text-zinc-500">처리 결과</dt><dd class="mt-1 break-words">{{ $delivery->failure_code?->label() ?? $delivery->status->label() }}</dd></div>
                            <div class="min-w-0"><dt class="text-xs text-zinc-500">시도</dt><dd class="mt-1">{{ number_format($delivery->attempt_count) }}회 · 재시도 {{ number_format($delivery->retries_count) }}회</dd></div>
                        </dl>
                        @if ($delivery->status === \App\Enums\NotificationDeliveryStatus::Failed)
                            <form method="POST" action="{{ route('notification-deliveries.retry', $delivery) }}">@csrf
                                <flux:button type="submit" size="sm" icon="arrow-path">재시도</flux:button>
                            </form>
                        @endif
                    </article>
                @endforeach
            </div>
            {{ $deliveries->links() }}
        @endif
    </div>
</x-layouts::app>
