@props([
    'title',
    'value',
    'description',
    'icon',
    'tone' => 'cyan',
])

@php
    $toneClasses = match ($tone) {
        'amber' => [
            'border' => 'border-amber-300/60 dark:border-amber-400/20',
            'glow' => 'bg-amber-400/15',
            'icon' => 'bg-amber-50 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300',
            'accent' => 'bg-amber-500',
        ],
        'red' => [
            'border' => 'border-red-300/60 dark:border-red-400/20',
            'glow' => 'bg-red-400/15',
            'icon' => 'bg-red-50 text-red-700 dark:bg-red-400/10 dark:text-red-300',
            'accent' => 'bg-red-500',
        ],
        'emerald' => [
            'border' => 'border-emerald-300/60 dark:border-emerald-400/20',
            'glow' => 'bg-emerald-400/15',
            'icon' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300',
            'accent' => 'bg-emerald-500',
        ],
        default => [
            'border' => 'border-cyan-300/60 dark:border-cyan-400/20',
            'glow' => 'bg-cyan-400/15',
            'icon' => 'bg-cyan-50 text-cyan-700 dark:bg-cyan-400/10 dark:text-cyan-300',
            'accent' => 'bg-cyan-500',
        ],
    };
@endphp

<article {{ $attributes->class([
    'relative overflow-hidden rounded-xl border bg-white p-5 shadow-sm dark:bg-[#141B2D]',
    $toneClasses['border'],
]) }}>
    <div aria-hidden="true" class="absolute -right-10 -top-10 size-28 rounded-full blur-2xl {{ $toneClasses['glow'] }}"></div>
    <div aria-hidden="true" class="absolute inset-x-0 bottom-0 h-0.5 {{ $toneClasses['accent'] }}"></div>

    <div class="relative flex items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold tracking-[0.14em] text-zinc-500 uppercase dark:text-zinc-400">{{ $title }}</p>
            <p class="mt-3 font-mono text-3xl font-bold tracking-tight text-zinc-950 dark:text-white" data-test="metric-value">
                {{ number_format($value) }}
            </p>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ $description }}</p>
        </div>

        <div class="grid size-11 shrink-0 place-items-center rounded-lg {{ $toneClasses['icon'] }}">
            <flux:icon :name="$icon" class="size-5" />
        </div>
    </div>
</article>
