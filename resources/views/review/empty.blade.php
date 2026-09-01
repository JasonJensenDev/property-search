<x-layout title="Review">
    <div class="mx-auto max-w-2xl py-10 text-center">
        <div class="mx-auto grid size-14 place-items-center rounded-full bg-emerald-50 text-2xl text-emerald-600">✓</div>

        <h1 class="mt-4 text-2xl font-semibold tracking-tight">Nothing left to review</h1>

        <p class="mt-2 text-slate-600">
            @if ($counts['matching'] === 0)
                No listings match your criteria yet. Run a scrape to pull the latest from
                utahrealestate.com, or loosen your criteria a little.
            @else
                You have been through every listing that meets your criteria. New ones will
                appear here after the next scrape.
            @endif
        </p>

        <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <x-stat label="Favorites" :value="$counts['favorites']" tone="good"
                    :href="route('listings.index', ['decision' => 'favorite'])" />
            <x-stat label="Maybes" :value="$counts['maybe']" tone="warn"
                    :href="route('listings.index', ['decision' => 'maybe'])" />
            <x-stat label="Crossed off" :value="$counts['rejected']" tone="bad"
                    :href="route('listings.index', ['decision' => 'rejected'])" />
            <x-stat label="Scraped" :value="$counts['total']" tone="muted"
                    :href="route('listings.index', ['match' => 'all'])" />
        </div>

        <div class="mt-6 flex flex-wrap justify-center gap-3">
            <form method="POST" action="{{ route('scrape.store') }}">
                @csrf
                <button class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                    Scrape utahrealestate.com now
                </button>
            </form>
            <a href="{{ route('criteria.edit') }}"
               class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                Adjust criteria
            </a>
            <a href="{{ route('listings.index', ['match' => 'no']) }}"
               class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                See what was filtered out
            </a>
        </div>
    </div>
</x-layout>
