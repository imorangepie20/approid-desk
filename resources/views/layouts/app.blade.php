<x-layouts::app.sidebar :title="$title ?? null">
    <main id="desk-main" class="desk-main" tabindex="-1">
        {{ $slot }}
    </main>
</x-layouts::app.sidebar>
