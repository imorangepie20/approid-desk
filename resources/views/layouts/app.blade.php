<x-layouts::app.sidebar :title="$title ?? null">
    <main class="[grid-area:main] p-6 lg:p-8" data-flux-main>
        {{ $slot }}
    </main>
</x-layouts::app.sidebar>
