@props(['listing', 'href' => null])

@php
    $href ??= route('review.show', $listing);
    $photo = $listing->primary_photo_url ?? $listing->photos->first()?->url;

    // The HOA badge below already spells out the dues, so showing the HOA failure too
    // would just say the same thing twice.
    $failures = collect($listing->criteria_failures ?? [])
        ->reject(fn ($failure) => $failure['code'] === 'hoa' && $listing->has_hoa)
        ->values();
@endphp

<a href="{{ $href }}"
   class="group flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white transition hover:border-slate-300 hover:shadow-md">
    <div class="relative aspect-4/3 overflow-hidden bg-slate-100">
        @if ($photo)
            <img src="{{ $photo }}" alt="{{ $listing->full_address }}" loading="lazy"
                 class="size-full object-cover transition duration-300 group-hover:scale-[1.03]">
        @else
            <div class="grid size-full place-items-center text-sm text-slate-400">No photo</div>
        @endif

        <div class="absolute left-2 top-2 flex flex-wrap gap-1">
            @if ($listing->decision !== \App\Enums\Decision::Undecided)
                <x-decision-badge :listing="$listing" />
            @endif
            @if ($listing->construction_status)
                <span class="rounded-full bg-amber-100/95 px-2 py-0.5 text-xs font-medium text-amber-800">
                    {{ $listing->construction_status }}
                </span>
            @endif
            @if ($listing->delisted_at)
                <span class="rounded-full bg-slate-800/90 px-2 py-0.5 text-xs font-medium text-white">Off market</span>
            @endif
        </div>

        <div class="absolute bottom-2 right-2 rounded-md bg-slate-900/80 px-2 py-1 text-sm font-semibold text-white tabular-nums">
            ${{ number_format($listing->price) }}
        </div>
    </div>

    <div class="flex flex-1 flex-col p-3">
        <div class="truncate text-sm font-semibold">{{ $listing->full_address }}</div>
        <div class="truncate text-xs text-slate-500">{{ $listing->city_line }}</div>

        <div class="mt-2 flex flex-wrap items-center gap-x-2.5 gap-y-1 text-xs text-slate-600 tabular-nums">
            <span><strong class="font-semibold text-slate-900">{{ number_format($listing->total_sqft) }}</strong> sq ft</span>
            <span class="text-slate-300">|</span>
            <span><strong class="font-semibold text-slate-900">{{ rtrim(rtrim(number_format((float) $listing->acres, 2), '0'), '.') }}</strong> ac</span>
            <span class="text-slate-300">|</span>
            <span>{{ $listing->beds }} bd</span>
            <span class="text-slate-300">|</span>
            <span>{{ (float) $listing->baths }} ba</span>
        </div>

        <div class="mt-2 flex flex-wrap gap-1">
            @if ($listing->has_hoa)
                <span class="rounded bg-rose-50 px-1.5 py-0.5 text-[11px] font-medium text-rose-700">
                    HOA ${{ number_format($listing->hoa_monthly) }}/mo
                </span>
            @else
                <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-[11px] font-medium text-emerald-700">No HOA</span>
            @endif

            @if ($listing->is_under_contract)
                <span class="rounded bg-amber-50 px-1.5 py-0.5 text-[11px] font-medium text-amber-700">Under contract</span>
            @endif

            {{-- Naming the actual shortfall is the point of this badge: "486 sq ft short"
                 tells you whether to bend, where a bare count does not. --}}
            @foreach ($failures->take(2) as $failure)
                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-600"
                      title="{{ $failure['label'] }}">
                    {{ $failure['short'] ?? $failure['label'] }}
                </span>
            @endforeach

            @if ($failures->count() > 2)
                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-600">
                    +{{ $failures->count() - 2 }} more
                </span>
            @endif
        </div>

        @if ($listing->decision_reason || $listing->decision_reason_code)
            <div class="mt-2 border-t border-slate-100 pt-2 text-[11px] text-slate-500">
                <span class="font-medium text-slate-700">Why:</span>
                {{ $listing->decision_reason ?: \App\Enums\RejectionReason::tryFrom($listing->decision_reason_code)?->label() }}
            </div>
        @endif
    </div>
</a>
