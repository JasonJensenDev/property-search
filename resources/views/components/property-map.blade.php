@props(['listing', 'width' => 358, 'height' => 208])

@php
    use App\Support\StaticMap;

    // The width has to match the rendered box, since the tiles are positioned server-side
    // and the marker is drawn at the centre of it. 358 is the 360px sidebar less its border.
    $map = $listing->has_coordinates
        ? StaticMap::forPoint($listing->latitude, $listing->longitude, $width, $height)
        : null;
@endphp

<div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
    @if ($map)
        {{-- Tiles are OpenStreetMap; the click-through goes to Google Maps, which is the
             better map to actually browse around in. --}}
        <a href="{{ $listing->map_url }}" target="_blank" rel="noopener"
           class="group relative block bg-slate-100"
           style="height: {{ $map['height'] }}px"
           title="Open {{ $listing->full_address }} in Google Maps">
            <div class="absolute inset-0 overflow-hidden">
                @foreach ($map['tiles'] as $tile)
                    <img src="{{ $tile['url'] }}" alt="" loading="lazy" width="256" height="256"
                         class="absolute max-w-none select-none"
                         style="left: {{ $tile['left'] }}px; top: {{ $tile['top'] }}px">
                @endforeach
            </div>

            {{-- The property sits at the centre of the box by construction. --}}
            <span class="absolute left-1/2 top-1/2 z-10 -ml-2 -mt-2 size-4 rounded-full border-2 border-white bg-rose-600 shadow-md"></span>
            <span class="absolute left-1/2 top-1/2 z-0 -ml-4 -mt-4 size-8 rounded-full bg-rose-500/20"></span>

            <span class="absolute inset-0 z-10 transition group-hover:bg-slate-900/5"></span>

            <span class="absolute bottom-1.5 right-1.5 z-20 rounded bg-white/95 px-1.5 py-0.5 text-[11px] font-medium text-slate-700 shadow-sm">
                Open in Google Maps &nearr;
            </span>

            @if ($listing->location_precision === 'street')
                {{-- Being clear beats a pin that looks more certain than it is. --}}
                <span class="absolute left-1.5 top-1.5 z-20 rounded bg-amber-100/95 px-1.5 py-0.5 text-[11px] font-medium text-amber-900 shadow-sm">
                    Street only — exact house not mapped
                </span>
            @endif

            {{-- Required by OpenStreetMap's tile usage policy. --}}
            <span class="absolute bottom-1.5 left-1.5 z-20 rounded bg-white/80 px-1 text-[10px] text-slate-500">
                © OpenStreetMap
            </span>
        </a>
    @else
        <a href="{{ $listing->map_url }}" target="_blank" rel="noopener"
           class="flex h-24 flex-col items-center justify-center gap-1 text-sm text-slate-500 hover:bg-slate-50">
            <span class="font-medium text-slate-700">Look up on Google Maps &nearr;</span>
            <span class="text-xs">No map preview — address not located yet</span>
        </a>
    @endif
</div>
