<header class="desk-header" data-test="desk-header">
    <div class="flex min-w-0 items-center gap-4">
        <button type="button" class="desk-icon-button" @click="mobile ? mobileOpen = !mobileOpen : collapsed = !collapsed"
            :aria-expanded="String(mobile ? mobileOpen : !collapsed)" aria-controls="desk-sidebar" aria-label="업무 메뉴 전환" data-test="desk-menu-toggle">
            <flux:icon.bars-3 class="size-5" />
        </button>
        @can('viewAny', \App\Models\WorkRequest::class)
            <form action="{{ route('requests.index') }}" method="GET" role="search" class="desk-search">
                <label for="desk-search" class="sr-only">요청 검색</label>
                <flux:icon.magnifying-glass class="size-4 shrink-0" />
                <input id="desk-search" name="search" type="search" placeholder="요청 검색..." maxlength="255" autocomplete="off" />
            </form>
        @endcan
    </div>
    <div class="flex shrink-0 items-center gap-2">
        <a href="{{ route('profile.edit') }}" wire:navigate class="desk-icon-button desk-settings-link" aria-label="설정"><flux:icon.cog-6-tooth class="size-5" /></a>
        <button type="button" class="desk-icon-button" @click="$flux.appearance = document.documentElement.classList.contains('dark') ? 'light' : 'dark'" aria-label="밝은 테마와 어두운 테마 전환" data-test="desk-theme-toggle">
            <flux:icon.sun class="size-5 hidden dark:block" /><flux:icon.moon class="size-5 dark:hidden" />
        </button>
        <span class="desk-header-divider" aria-hidden="true"></span>
        @php($deskNotifications = auth()->user()->unreadNotifications()->latest()->limit(5)->get())
        <flux:dropdown position="bottom" align="end">
            <button type="button" class="desk-icon-button relative" aria-label="읽지 않은 알림 {{ $deskNotifications->count() }}개" data-test="desk-notifications">
                <flux:icon.bell class="size-5" />
                @if ($deskNotifications->isNotEmpty())
                    <span class="absolute right-1 top-1 size-2 rounded-full bg-red-500 ring-2 ring-[var(--color-zinc-950)]" aria-hidden="true"></span>
                @endif
            </button>
            <flux:menu class="desk-popover">
                <h2 class="font-semibold">알림</h2>
                @forelse ($deskNotifications as $notification)
                    <form method="POST" action="{{ route('notifications.read', $notification->id) }}" class="mt-2">
                        @csrf
                        <flux:menu.item as="button" type="submit" class="h-auto min-w-72 items-start py-2 text-left" data-test="month-transition-notification">
                            <span class="block">
                                <span class="block font-medium">{{ $notification->data['label'] ?? $notification->data['request_title'] ?? '업무 알림' }}</span>
                                @if (isset($notification->data['label'], $notification->data['request_title']))
                                    <span class="mt-1 block text-xs text-zinc-500 dark:text-zinc-400">{{ $notification->data['request_title'] }}</span>
                                @endif
                                @if (isset($notification->data['month'], $notification->data['remaining_minutes']))
                                    <span class="mt-1 block text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $notification->data['month'] }} · 남은 예약 {{ number_format((int) $notification->data['remaining_minutes']) }}분
                                    </span>
                                @endif
                                @if (($notification->data['kind'] ?? null) === \App\Enums\NotificationType::WeeklyProgressReport->value)
                                    <span class="mt-1 block text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $notification->data['period_start'] ?? '' }} ~ {{ $notification->data['period_end'] ?? '' }} · 열린 요청 {{ number_format((int) ($notification->data['open_request_count'] ?? 0)) }}건 · 완료 {{ number_format((int) ($notification->data['completed_request_count'] ?? 0)) }}건
                                    </span>
                                @endif
                                <span class="mt-1 block text-xs">{{ $notification->data['message'] ?? '업무 알림을 확인해 주세요.' }}</span>
                            </span>
                        </flux:menu.item>
                    </form>
                @empty
                    <p class="mt-2 text-sm">새 알림이 없습니다.</p>
                    <p class="mt-1 text-xs">현재 진행 상태는 요청 목록에서 확인해 주세요.</p>
                    <flux:menu.item :href="route('requests.index')" wire:navigate>요청 목록 보기</flux:menu.item>
                @endforelse
            </flux:menu>
        </flux:dropdown>
        <flux:dropdown position="bottom" align="end">
            <button type="button" class="desk-profile-button" data-test="header-user-menu" aria-label="사용자 메뉴">
                <span class="desk-avatar">{{ auth()->user()->initials() }}</span>
                <span class="hidden max-w-36 truncate text-sm md:block">{{ auth()->user()->name }}</span>
                <flux:icon.chevron-down class="hidden size-4 md:block" />
            </button>
            <flux:menu class="desk-user-menu">
                <div class="px-2 py-2"><p class="truncate font-semibold">{{ auth()->user()->name }}</p><p class="truncate text-xs">{{ auth()->user()->email }}</p></div>
                <flux:menu.separator />
                <flux:menu.item :href="route('profile.edit')" icon="user" wire:navigate>프로필 및 설정</flux:menu.item>
                <flux:menu.separator />
                <form method="POST" action="{{ route('logout') }}">@csrf
                    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" data-test="logout-button">로그아웃</flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>
    </div>
</header>
