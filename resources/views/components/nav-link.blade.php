@props(['active' => false])

<a {{ $attributes->merge([
    'class' => 'rounded-md px-3 py-1.5 text-sm font-medium transition '
        .($active ? 'bg-slate-100 text-slate-900' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'),
]) }}>
    {{ $slot }}
</a>
