@props(['label', 'value', 'tone' => null, 'hint' => null])

<div>
    <dt class="text-[11px] font-medium uppercase tracking-wide text-slate-500">{{ $label }}</dt>
    <dd @class([
        'mt-0.5 font-semibold tabular-nums',
        'text-emerald-600' => $tone === 'good',
        'text-rose-600' => $tone === 'bad',
        'text-amber-600' => $tone === 'warn',
        'text-slate-400 font-normal' => $value === null || $value === '',
        'text-slate-900' => $tone === null && $value !== null && $value !== '',
    ])>
        {{ $value === null || $value === '' ? 'Not stated' : $value }}
    </dd>
    @if ($hint)
        <dd class="mt-0.5 text-[11px] text-slate-400">{{ $hint }}</dd>
    @endif
</div>
