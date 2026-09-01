@props(['listing'])

@php $decision = $listing->decision; @endphp

<span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $decision->classes() }}">
    <span aria-hidden="true">{{ $decision->icon() }}</span>
    {{ $decision->label() }}
</span>
