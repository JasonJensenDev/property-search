<x-layout title="Overview" wide>
    @if ($profile)
        @php
            $summary = collect([
                $profile->min_sqft ? number_format($profile->min_sqft).'+ sq ft' : null,
                $profile->min_acres ? rtrim(rtrim(number_format($profile->min_acres, 2), '0'), '.').'+ acres' : null,
                $profile->max_price ? 'under $'.number_format($profile->max_price) : null,
                $profile->exclude_hoa ? 'no HOA' : null,
                $profile->require_move_in_ready && $profile->ready_by
                    ? 'ready by '.$profile->ready_by->format('M j, Y')
                    : null,
            ])->filter();
        @endphp

        <div class="mb-5 flex flex-wrap items-start gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">{{ implode(', ', $profile->cities) }}</h1>
                <p class="mt-1 flex flex-wrap items-center gap-1.5 text-sm text-slate-600">
                    <span>Looking for</span>
                    @foreach ($summary as $item)
                        <span class="rounded bg-white px-1.5 py-0.5 font-medium text-slate-800 ring-1 ring-slate-200 ring-inset">
                            {{ $item }}
                        </span>
                    @endforeach
                </p>
            </div>
            <a href="{{ route('criteria.edit') }}"
               class="ml-auto rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">
                Edit criteria
            </a>
        </div>
    @endif

    {{-- Scrape progress, polled while a run is in flight --}}
    <div x-data="scrapeMonitor({ statusUrl: @js(route('scrape.status')) })" class="mb-5">
        <template x-if="run && run.status === 'running'">
            <div class="rounded-xl border border-blue-200 bg-blue-50 p-4">
                <div class="flex items-center gap-2 text-sm font-medium text-blue-900">
                    <span class="inline-block size-2 animate-pulse rounded-full bg-blue-600"></span>
                    Scraping utahrealestate.com...
                    <span class="ml-auto font-normal text-blue-700 tabular-nums">
                        <span x-text="run.cards_found"></span> found,
                        <span x-text="run.details_fetched"></span> details read
                    </span>
                </div>
                <div class="mt-2 max-h-28 overflow-y-auto rounded bg-white/70 p-2 font-mono text-xs text-blue-900">
                    <template x-for="entry in run.log" :key="entry.at + entry.line">
                        <div x-text="entry.line"></div>
                    </template>
                </div>
            </div>
        </template>

        <template x-if="run && run.status === 'failed'">
            <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
                <div class="font-semibold">The last scrape failed</div>
                <div class="mt-1" x-text="run.message"></div>
            </div>
        </template>
    </div>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <x-stat label="To review" :value="$stats['queue']" tone="default"
                :href="route('review.index')" hint="Matches, undecided" />
        <x-stat label="Favorites" :value="$stats['favorites']" tone="good"
                :href="route('listings.index', ['decision' => 'favorite'])" />
        <x-stat label="Maybes" :value="$stats['maybe']" tone="warn"
                :href="route('listings.index', ['decision' => 'maybe'])" />
        <x-stat label="Crossed off" :value="$stats['rejected']" tone="bad"
                :href="route('listings.index', ['decision' => 'rejected'])" />
        <x-stat label="Meet criteria" :value="$stats['matching']" tone="default"
                :href="route('listings.index', ['match' => 'yes'])"
                :hint="$stats['scraped'].' scraped'" />
        <x-stat label="Off market" :value="$stats['delisted']" tone="muted"
                :href="route('listings.index', ['delisted' => '1'])" />
    </div>

    @if ($lastRun)
        <p class="mt-3 text-xs text-slate-500">
            Last scrape {{ $lastRun->finished_at?->diffForHumans() ?? 'started '.$lastRun->started_at?->diffForHumans() }}
            — {{ $lastRun->cards_found }} listings seen,
            {{ $lastRun->listings_created }} new,
            {{ $lastRun->price_changes }} price change{{ $lastRun->price_changes === 1 ? '' : 's' }}.
        </p>
    @endif

    {{-- Favorites --}}
    <section class="mt-8">
        <div class="flex items-center gap-3">
            <h2 class="text-lg font-semibold tracking-tight">Your shortlist</h2>
            @if ($stats['queue'] > 0)
                <a href="{{ route('review.index') }}"
                   class="ml-auto rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700">
                    Review {{ $stats['queue'] }} waiting &rarr;
                </a>
            @endif
        </div>

        @if ($favorites->isEmpty())
            <p class="mt-3 rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-500">
                Nothing on the shortlist yet.
                <a href="{{ route('review.index') }}" class="font-medium text-slate-900 underline">Start reviewing</a>
                to build it up.
            </p>
        @else
            <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($favorites as $listing)
                    <x-listing-card :listing="$listing" :href="route('review.show', $listing)" />
                @endforeach
            </div>
        @endif
    </section>

    {{-- Maybes --}}
    <section class="mt-8">
        <h2 class="text-lg font-semibold tracking-tight">Maybes</h2>

        @if ($maybes->isEmpty())
            <p class="mt-3 rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-500">
                Nothing flagged as a maybe yet.
            </p>
        @else
            <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($maybes as $listing)
                    <x-listing-card :listing="$listing" :href="route('review.show', $listing)" />
                @endforeach
            </div>
        @endif
    </section>

    @if ($priceDrops->isNotEmpty())
        <section class="mt-8">
            <h2 class="text-lg font-semibold tracking-tight">Recent price drops</h2>
            <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($priceDrops as $listing)
                    <x-listing-card :listing="$listing" :href="route('review.show', $listing)" />
                @endforeach
            </div>
        </section>
    @endif

    @if ($nearMisses->isNotEmpty())
        <section class="mt-8">
            <h2 class="text-lg font-semibold tracking-tight">So close</h2>
            <p class="mt-1 text-sm text-slate-600">
                These fail on exactly one criterion. Worth a look if you would bend a little.
            </p>
            <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($nearMisses as $listing)
                    <x-listing-card :listing="$listing" :href="route('review.show', $listing)" />
                @endforeach
            </div>
        </section>
    @endif
</x-layout>
