<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>@include('partials.head')</head>
    <body class="desk-shell"
        x-data="{ collapsed: localStorage.getItem('desk.sidebar.collapsed') === 'true', mobileOpen: false, mobile: window.innerWidth < 1024 }"
        x-init="$watch('collapsed', value => localStorage.setItem('desk.sidebar.collapsed', value))"
        @resize.window="mobile = window.innerWidth < 1024; if (!mobile) mobileOpen = false"
        @keydown.escape.window="mobileOpen = false"
        :class="{ 'desk-collapsed': collapsed, 'desk-mobile-open': mobileOpen }">
        <a href="#desk-main" class="desk-skip">본문으로 건너뛰기</a>
        <button x-cloak x-show="mobile && mobileOpen" @click="mobileOpen = false" class="desk-backdrop" aria-label="메뉴 닫기" tabindex="-1"></button>
        <aside id="desk-sidebar" class="desk-sidebar" data-test="desk-sidebar" aria-label="업무 메뉴"
            :inert="mobile && !mobileOpen" x-trap.noscroll="mobile && mobileOpen">
            <div class="desk-brand">
                <a href="{{ route('dashboard') }}" wire:navigate aria-label="Approid Desk 대시보드" class="flex items-center gap-3">
                    <span class="desk-brand-mark">A</span><span class="desk-nav-label font-semibold text-lg">APPROID DESK</span>
                </a>
                <button type="button" class="desk-mobile-close" @click="mobileOpen = false" aria-label="메뉴 닫기"><flux:icon.x-mark class="size-5" /></button>
            </div>
            <nav class="desk-navigation" aria-label="주 메뉴">
                <x-desk-nav-item :href="route('dashboard')" :active="request()->routeIs('dashboard')" icon="home" label="대시보드" />
                @can(\App\Enums\Permission::ManageCompanies->value)
                    <x-desk-nav-item :href="route('companies.index')" :active="request()->routeIs('companies.*')" icon="building-office-2" label="고객사 관리" />
                @elsecan(\App\Enums\Permission::ManageCompanyUsers->value)
                    @if (auth()->user()->company_id !== null)
                        <x-desk-nav-item :href="route('companies.show', auth()->user()->company_id)" :active="request()->routeIs('companies.*')" icon="users" label="자사 사용자" />
                    @endif
                @endcan
                @can('viewAny', \App\Models\Project::class)
                    <x-desk-nav-item :href="route('projects.index')" :active="request()->routeIs('projects.*')" icon="folder-open" label="프로젝트" />
                @endcan
                @can('viewAny', \App\Models\WorkRequest::class)
                    <x-desk-nav-item :href="route('requests.index')" :active="request()->routeIs('requests.*')" icon="clipboard-document-list" label="요청" />
                @endcan
                @can(\App\Enums\Permission::ViewUsage->value)
                    <x-desk-nav-item :href="route('usage.index')" :active="request()->routeIs('usage.*')" icon="clock" label="월 사용내역" />
                @endcan
                @can(\App\Enums\Permission::ManageNotifications->value)
                    <x-desk-nav-item :href="route('notification-deliveries.index')" :active="request()->routeIs('notification-deliveries.*')" icon="paper-airplane" label="알림 발송" />
                @endcan
                <div class="desk-nav-divider"></div>
                <x-desk-nav-item :href="route('profile.edit')" :active="request()->routeIs('*.edit', 'appearance')" icon="cog-6-tooth" label="설정" />
            </nav>
        </aside>
        <div class="desk-content" :inert="mobile && mobileOpen">
            @include('layouts.app.header')
            {{ $slot }}
        </div>
        @persist('toast')<flux:toast.group><flux:toast /></flux:toast.group>@endpersist
        @fluxScripts
    </body>
</html>
