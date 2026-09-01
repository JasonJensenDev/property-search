@props(['label', 'value', 'href' => null, 'tone' => 'default', 'hint' => null])

@php
    $tones = [
        'default' => 'text-slate-900',
        'good' => 'text-emerald-600',
        'warn' => 'text-amber-600',
        'bad' => 'text-rose-600',
        'muted' => 'text-slate-400',
    ];
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif
    class="block rounded-xl border border-slate-200 bg-white p-4 {{ $href ? 'transition hover:border-slate-300 hover:shadow-sm' : '' }}">
    <div class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</div>
    <div class="mt-1 text-2xl font-semibold tabular-nums {{ $tones[$tone] }}">{{ $value }}</div>
    @if ($hint)
        <div class="mt-0.5 text-xs text-slate-400">{{ $hint }}</div>
    @endif
</{{ $tag }}>
