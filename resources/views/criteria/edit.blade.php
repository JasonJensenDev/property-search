<x-layout title="Criteria">
    <h1 class="text-2xl font-semibold tracking-tight">Search criteria</h1>
    <p class="mt-1 max-w-3xl text-sm text-slate-600">
        These are applied to the stored data, so they can be as precise as you like.
        utahrealestate.com only offers square footage in 500 and 1,000 step buckets and has
        no way to exclude HOA properties at all, so a slightly looser search is sent to them
        and the exact numbers below decide what actually reaches your review queue.
    </p>

    <form method="POST" action="{{ route('criteria.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('PUT')

        <section class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold">Where</h2>
            <label class="mt-3 block">
                <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Cities</span>
                <input type="text" name="cities" value="{{ implode(', ', $profile->cities) }}" required
                       class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm"
                       placeholder="Grantsville, Tooele, Stansbury Park">
                <span class="mt-1 block text-xs text-slate-500">
                    Comma separated. Each city is searched separately, since their site only
                    accepts one at a time.
                </span>
            </label>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold">Size and price</h2>
            <p class="mt-1 text-xs text-slate-500">
                Leave a field blank to ignore it.
            </p>

            <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <label class="block">
                    <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Min square feet</span>
                    <input type="number" name="min_sqft" value="{{ $profile->min_sqft }}" min="0" step="50"
                           class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                    @if ($profile->min_sqft && $profile->remote_min_sqft)
                        <span class="mt-1 block text-xs text-slate-500">
                            Their search will be asked for {{ number_format($profile->remote_min_sqft) }}+,
                            then trimmed to {{ number_format($profile->min_sqft) }}+ here.
                        </span>
                    @endif
                </label>

                <label class="block">
                    <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Max square feet</span>
                    <input type="number" name="max_sqft" value="{{ $profile->max_sqft }}" min="0" step="50"
                           class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                </label>

                <label class="block">
                    <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Min acres</span>
                    <input type="number" name="min_acres" value="{{ $profile->min_acres }}" min="0" step="0.01"
                           class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                </label>

                <label class="block">
                    <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Max acres</span>
                    <input type="number" name="max_acres" value="{{ $profile->max_acres }}" min="0" step="0.01"
                           class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                </label>

                <label class="block">
                    <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Min price</span>
                    <input type="number" name="min_price" value="{{ $profile->min_price }}" min="0" step="5000"
                           class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                </label>

                <label class="block">
                    <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Max price</span>
                    <input type="number" name="max_price" value="{{ $profile->max_price }}" min="0" step="5000"
                           class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                </label>

                <label class="block">
                    <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Min bedrooms</span>
                    <input type="number" name="min_beds" value="{{ $profile->min_beds }}" min="0" step="1"
                           class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                </label>

                <label class="block">
                    <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Min bathrooms</span>
                    <input type="number" name="min_baths" value="{{ $profile->min_baths }}" min="0" step="0.5"
                           class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                </label>

                <label class="block">
                    <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Min garage spaces</span>
                    <input type="number" name="min_garage" value="{{ $profile->min_garage }}" min="0" step="1"
                           class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                </label>
            </div>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold">HOA</h2>
            <div class="mt-3 space-y-3">
                <label class="flex items-start gap-2.5">
                    <input type="checkbox" name="exclude_hoa" value="1" @checked($profile->exclude_hoa)
                           class="mt-0.5 rounded border-slate-300">
                    <span class="text-sm">
                        <span class="font-medium">Exclude anything with an HOA</span>
                        <span class="block text-xs text-slate-500">
                            Read from the dues stated on each listing page. Their own search cannot
                            filter on this.
                        </span>
                    </span>
                </label>

                <label class="block max-w-xs">
                    <span class="text-xs font-medium uppercase tracking-wide text-slate-500">
                        Or allow an HOA up to ($/month)
                    </span>
                    <input type="number" name="max_hoa_monthly" value="{{ $profile->max_hoa_monthly }}" min="0" step="5"
                           class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm"
                           placeholder="Ignored when excluding entirely">
                </label>
            </div>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold">Move-in timing</h2>
            <div class="mt-3 space-y-3">
                <label class="flex items-start gap-2.5">
                    <input type="checkbox" name="require_move_in_ready" value="1" @checked($profile->require_move_in_ready)
                           class="mt-0.5 rounded border-slate-300">
                    <span class="text-sm">
                        <span class="font-medium">Only homes I could actually move into in time</span>
                        <span class="block text-xs text-slate-500">
                            Keeps finished homes, plus unfinished ones whose estimated completion
                            lands on or before the date below. Listings badged "To Be Built" with no
                            date are excluded.
                        </span>
                    </span>
                </label>

                <label class="block max-w-xs">
                    <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Need to be in by</span>
                    <input type="date" name="ready_by" value="{{ $profile->ready_by?->toDateString() }}"
                           class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                </label>

                <label class="flex items-start gap-2.5">
                    <input type="checkbox" name="include_under_contract" value="1"
                           @checked(in_array('3', $profile->remote_statuses ?? [], true))
                           class="mt-0.5 rounded border-slate-300">
                    <span class="text-sm">
                        <span class="font-medium">Also scrape listings already under contract</span>
                        <span class="block text-xs text-slate-500">
                            Off by default. Active, coming soon and backup-offer listings are always
                            included.
                        </span>
                    </span>
                </label>
            </div>
        </section>

        <div class="flex flex-wrap items-center gap-3">
            <button class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                Save and re-check every listing
            </button>
            <span class="text-xs text-slate-500">
                Saving re-evaluates stored listings immediately. Run a scrape afterwards to pull
                in anything new that the looser remote search now covers.
            </span>
        </div>
    </form>

    @if ($runs->isNotEmpty())
        <section class="mt-8">
            <h2 class="text-lg font-semibold tracking-tight">Recent scrapes</h2>
            <div class="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Started</th>
                            <th class="px-4 py-2 font-medium">Status</th>
                            <th class="px-4 py-2 text-right font-medium">Seen</th>
                            <th class="px-4 py-2 text-right font-medium">New</th>
                            <th class="px-4 py-2 text-right font-medium">Details</th>
                            <th class="px-4 py-2 text-right font-medium">Price moves</th>
                            <th class="px-4 py-2 text-right font-medium">Took</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($runs as $run)
                            <tr>
                                <td class="px-4 py-2 text-slate-600">{{ $run->started_at?->diffForHumans() }}</td>
                                <td class="px-4 py-2">
                                    <span @class([
                                        'rounded-full px-2 py-0.5 text-xs font-medium',
                                        'bg-emerald-50 text-emerald-700' => $run->status === 'completed',
                                        'bg-blue-50 text-blue-700' => $run->status === 'running',
                                        'bg-rose-50 text-rose-700' => $run->status === 'failed',
                                    ])>{{ $run->status }}</span>
                                    @if ($run->message)
                                        <span class="ml-1 text-xs text-rose-600">{{ Str::limit($run->message, 60) }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $run->cards_found }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $run->listings_created }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $run->details_fetched }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $run->price_changes }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-slate-500">
                                    {{ $run->duration !== null ? $run->duration.'s' : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</x-layout>
