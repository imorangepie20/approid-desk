@props(['href', 'active' => false, 'icon', 'label'])
<a href="{{ $href }}" wire:navigate class="desk-nav-item {{ $active ? 'desk-nav-active' : '' }}" @if ($active) aria-current="page" @endif aria-label="{{ $label }}" title="{{ $label }}">
    <flux:icon :name="$icon" class="size-5 shrink-0" /><span class="desk-nav-label text-sm">{{ $label }}</span>
</a>
