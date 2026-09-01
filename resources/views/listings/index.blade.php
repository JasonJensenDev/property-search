<x-layout title="All listings" wide>
    <div class="flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold tracking-tight">All listings</h1>
        <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-sm font-medium text-slate-600 tabular-nums">
            {{ number_format($listings->total()) }}
        </span>
    </div>

    <form method="GET" class="mt-4 rounded-xl border border-slate-200 bg-white p-4">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
            <label class="block">
                <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Decision</span>
                <select name="decision" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                    <option value="all">Any</option>
                    @foreach (\App\Enums\Decision::cases() as $case)
                        <option value="{{ $case->value }}" @selected(($filters['decision'] ?? '') === $case->value)>
                            {{ $case->label() }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Criteria</span>
                <select name="match" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                    <option value="">Any</option>
                    <option value="yes" @selected(($filters['match'] ?? '') === 'yes')>Meets all</option>
                    <option value="no" @selected(($filters['match'] ?? '') === 'no')>Falls short</option>
                </select>
            </label>

            <label class="block">
                <span class="text-xs font-medium uppercase tracking-wide text-slate-500">HOA</span>
                <select name="hoa" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                    <option value="">Any</option>
                    <option value="none" @selected(($filters['hoa'] ?? '') === 'none')>No HOA</option>
                    <option value="has" @selected(($filters['hoa'] ?? '') === 'has')>Has HOA</option>
                </select>
            </label>

            <label class="block">
                <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Min sq ft</span>
                <input type="number" name="min_sqft" value="{{ $filters['min_sqft'] ?? '' }}" min="0" step="50"
                       class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm" placeholder="3500">
            </label>

            <label class="block">
                <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Min acres</span>
                <input type="number" name="min_acres" value="{{ $filters['min_acres'] ?? '' }}" min="0" step="0.01"
                       class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm" placeholder="0.25">
            </label>

            <label class="block">
                <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Max price</span>
                <input type="number" name="max_price" value="{{ $filters['max_price'] ?? '' }}" min="0" step="5000"
                       class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm" placeholder="800000">
            </label>

            <label class="block">
                <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Search</span>
                <input type="search" name="search" value="{{ $filters['search'] ?? '' }}"
                       class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm" placeholder="Address, MLS#, notes">
            </label>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-3">
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="delisted" value="1" @checked(($filters['delisted'] ?? '') === '1')
                       class="rounded border-slate-300">
                Show off-market only
            </label>

            <label class="ml-auto flex items-center gap-2 text-sm text-slate-600">
                Sort
                <select name="sort" class="rounded-md border-slate-300 text-sm shadow-sm">
                    @foreach ($sorts as $key => $label)
                        <option value="{{ $key }}" @selected($sortKey === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <button class="rounded-md bg-slate-900 px-4 py-1.5 text-sm font-medium text-white hover:bg-slate-700">
                Apply
            </button>
            <a href="{{ route('listings.index') }}" class="text-sm text-slate-500 underline hover:text-slate-800">Reset</a>
        </div>
    </form>

    @if ($listings->isEmpty())
        <p class="mt-6 rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500">
            No listings match these filters.
        </p>
    @else
        <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @foreach ($listings as $listing)
                <x-listing-card :listing="$listing" :href="route('review.show', $listing)" />
            @endforeach
        </div>

        <div class="mt-6">{{ $listings->links() }}</div>
    @endif
</x-layout>
