@php
    use App\Enums\Decision;

    $photos = $listing->photos;

    $photoPayload = $photos
        ->map(fn ($photo) => [
            'url' => $photo->url,
            'full' => $photo->full_url,
            'caption' => $photo->caption,
        ])
        ->values();
@endphp

<x-layout :title="$listing->full_address" wide>
    <div x-data="reviewScreen({
            photoCount: {{ $photos->count() }},
            nextUrl: @js($nextListing ? route('review.show', $nextListing) : null),
            previousUrl: @js($previousListing ? route('review.show', $previousListing) : null),
         })"
         @keydown.window="onKey($event)">

        {{-- Queue position and quick navigation --}}
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <div class="text-sm text-slate-500">
                @if ($position)
                    Reviewing <strong class="font-semibold text-slate-900">{{ $position }}</strong> of
                    <strong class="font-semibold text-slate-900">{{ $queueTotal }}</strong> in the queue
                @else
                    Already decided — <x-decision-badge :listing="$listing" />
                @endif
            </div>

            <div class="ml-auto flex items-center gap-2">
                @if ($previousListing)
                    <a href="{{ route('review.show', $previousListing) }}"
                       class="rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-50">&larr; Previous</a>
                @endif
                @if ($nextListing)
                    <a href="{{ route('review.show', $nextListing) }}"
                       class="rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-50">Skip &rarr;</a>
                @endif
                <button type="button" @click="showHelp = !showHelp"
                        class="rounded-md border border-slate-200 bg-white px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-50">
                    Shortcuts
                </button>
            </div>
        </div>

        <div x-show="showHelp" x-transition x-cloak
             class="mb-4 grid grid-cols-2 gap-2 rounded-lg border border-slate-200 bg-white p-4 text-sm sm:grid-cols-4">
            @foreach ([
                'F' => 'Keep as favorite',
                'X' => 'Cross off (asks why)',
                'M' => 'Mark as maybe',
                'S' => 'Skip for now',
                '&larr; &rarr;' => 'Previous / next photo',
                'Enter' => 'Open full-screen photos',
                'Esc' => 'Close overlay',
                'O' => 'Open on utahrealestate.com',
            ] as $key => $meaning)
                <div class="flex items-center gap-2">
                    <kbd class="rounded border border-slate-300 bg-slate-50 px-1.5 py-0.5 font-mono text-xs">{!! $key !!}</kbd>
                    <span class="text-slate-600">{{ $meaning }}</span>
                </div>
            @endforeach
        </div>

        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
            {{-- ------------------------------------------------- photos + detail --}}
            <div class="min-w-0">
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                    <div class="relative aspect-16/10 bg-slate-900">
                        @if ($photos->isNotEmpty())
                            <template x-for="(photo, i) in photos" :key="photo.url">
                                <img :src="photo.url" :alt="photo.caption"
                                     x-show="i === index" x-cloak
                                     class="absolute inset-0 size-full cursor-zoom-in object-contain"
                                     @click="lightbox = true">
                            </template>

                            <button type="button" @click="prev()"
                                    class="absolute left-2 top-1/2 -translate-y-1/2 rounded-full bg-black/45 p-2.5 text-white transition hover:bg-black/70"
                                    aria-label="Previous photo">&#10094;</button>
                            <button type="button" @click="next()"
                                    class="absolute right-2 top-1/2 -translate-y-1/2 rounded-full bg-black/45 p-2.5 text-white transition hover:bg-black/70"
                                    aria-label="Next photo">&#10095;</button>

                            <div class="absolute bottom-2 right-2 rounded bg-black/60 px-2 py-1 text-xs font-medium text-white tabular-nums">
                                <span x-text="index + 1"></span> / {{ $photos->count() }}
                            </div>
                        @else
                            <div class="grid size-full place-items-center text-sm text-slate-400">No photos on this listing</div>
                        @endif
                    </div>

                    @if ($photos->isNotEmpty())
                        <div class="border-t border-slate-100 px-3 py-2">
                            <p class="min-h-8 text-xs text-slate-500" x-text="photos[index]?.caption || ''"></p>
                        </div>

                        <div class="flex gap-1.5 overflow-x-auto border-t border-slate-100 p-2">
                            @foreach ($photos as $i => $photo)
                                <button type="button" @click="index = {{ $i }}"
                                        class="shrink-0 overflow-hidden rounded border-2 transition"
                                        :class="index === {{ $i }} ? 'border-slate-900' : 'border-transparent opacity-60 hover:opacity-100'">
                                    <img src="{{ $photo->thumb_url ?? $photo->url }}" alt="" loading="lazy"
                                         class="h-14 w-20 object-cover">
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Specs --}}
                <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5">
                    <div class="flex flex-wrap items-start gap-3">
                        <div class="min-w-0">
                            <h1 class="truncate text-xl font-semibold tracking-tight">{{ $listing->full_address }}</h1>
                            <p class="text-sm text-slate-500">{{ $listing->city_line }}</p>
                            <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                                <a href="{{ $listing->url }}" target="_blank" rel="noopener"
                                   class="font-medium text-sky-700 underline decoration-sky-300 underline-offset-2 hover:text-sky-900">
                                    View listing on UtahRealEstate.com &nearr;
                                </a>
                                <a href="{{ $listing->map_url }}" target="_blank" rel="noopener"
                                   class="text-slate-500 underline decoration-slate-300 underline-offset-2 hover:text-slate-800">
                                    Google Maps &nearr;
                                </a>
                            </div>
                        </div>
                        <div class="ml-auto text-right">
                            <div class="text-2xl font-semibold tabular-nums">${{ number_format($listing->price) }}</div>
                            @if ($listing->price_per_sqft)
                                <div class="text-xs text-slate-500 tabular-nums">${{ number_format($listing->price_per_sqft, 0) }} / sq ft</div>
                            @endif
                        </div>
                    </div>

                    <dl class="mt-5 grid grid-cols-2 gap-x-4 gap-y-4 border-t border-slate-100 pt-4 sm:grid-cols-4">
                        <x-spec label="Square feet" :value="number_format($listing->total_sqft)" tone="good" />
                        <x-spec label="Lot size"
                                :value="$listing->acres ? rtrim(rtrim(number_format((float) $listing->acres, 2), '0'), '.').' acres' : null"
                                tone="good"
                                :hint="$listing->lot_sqft ? number_format($listing->lot_sqft).' sq ft' : null" />
                        <x-spec label="Bedrooms" :value="$listing->beds" />
                        <x-spec label="Bathrooms" :value="$listing->baths ? (float) $listing->baths : null" />

                        <x-spec label="HOA"
                                :value="$listing->has_hoa ? '$'.number_format($listing->hoa_monthly).' / mo' : 'No HOA'"
                                :tone="$listing->has_hoa ? 'bad' : 'good'" />
                        <x-spec label="Year built" :value="$listing->year_built" />
                        <x-spec label="Garage" :value="$listing->garage_capacity ? $listing->garage_capacity.' car' : null" />
                        <x-spec label="Style" :value="$listing->style" />

                        <x-spec label="Status" :value="$listing->status"
                                :tone="$listing->is_under_contract ? 'warn' : null" />
                        <x-spec label="Days listed" :value="$listing->days_on_ure" />
                        <x-spec label="Basement finished"
                                :value="$listing->basement_finished_pct !== null ? $listing->basement_finished_pct.'%' : null"
                                :hint="$listing->basement_sqft ? number_format($listing->basement_sqft).' sq ft below grade' : null" />
                        <x-spec label="Yearly tax"
                                :value="$listing->property_tax_annual ? '$'.number_format($listing->property_tax_annual) : null" />
                    </dl>

                    @if ($listing->construction_status || $listing->is_new_construction)
                        <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm">
                            <div class="font-medium text-amber-900">
                                Not finished yet{{ $listing->construction_status ? ' — '.$listing->construction_status : '' }}
                            </div>
                            <div class="mt-0.5 text-amber-800">
                                @if ($listing->completion_estimate)
                                    Estimated completion <strong>{{ $listing->completion_estimate->format('F j, Y') }}</strong>.
                                @else
                                    No completion date given.
                                @endif
                                @if ($listing->completion_note)
                                    <span class="mt-1 block text-xs text-amber-700">{{ $listing->completion_note }}</span>
                                @endif
                            </div>
                        </div>
                    @endif

                    @if ($listing->sqft_levels)
                        <div class="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-4">
                            @foreach ($listing->sqft_levels as $level => $sqft)
                                <span class="rounded-md bg-slate-100 px-2 py-1 text-xs text-slate-700 tabular-nums">
                                    {{ $level }}: <strong class="font-semibold">{{ number_format($sqft) }}</strong> sq ft
                                </span>
                            @endforeach
                        </div>
                    @endif

                    @if ($listing->description)
                        <div class="mt-4 border-t border-slate-100 pt-4">
                            <h2 class="text-xs font-medium uppercase tracking-wide text-slate-500">Listing description</h2>
                            <p class="mt-1.5 text-sm leading-relaxed text-slate-700">{{ $listing->description }}</p>
                        </div>
                    @endif

                    @foreach ([
                        'Interior' => $listing->interior_features,
                        'Exterior and lot' => $listing->exterior_features,
                        'Utilities and other' => $listing->other_features,
                    ] as $heading => $features)
                        @if ($features)
                            <div class="mt-4 border-t border-slate-100 pt-4">
                                <h2 class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $heading }}</h2>
                                <ul class="mt-1.5 grid gap-x-4 gap-y-1 text-sm text-slate-700 sm:grid-cols-2">
                                    @foreach ($features as $feature)
                                        <li class="flex gap-1.5"><span class="text-slate-300">&bull;</span><span>{{ $feature }}</span></li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @endforeach

                    @if ($listing->hoa_details)
                        <div class="mt-4 border-t border-slate-100 pt-4">
                            <h2 class="text-xs font-medium uppercase tracking-wide text-slate-500">HOA details</h2>
                            <p class="mt-1.5 text-sm text-slate-700">{{ $listing->hoa_details }}</p>
                        </div>
                    @endif

                    @if ($listing->schools)
                        <div class="mt-4 border-t border-slate-100 pt-4">
                            <h2 class="text-xs font-medium uppercase tracking-wide text-slate-500">Schools</h2>
                            <div class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-700">
                                @foreach ($listing->schools as $level => $name)
                                    <span><span class="text-slate-500">{{ $level }}:</span> {{ $name }}</span>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-slate-100 pt-4 text-xs text-slate-500">
                        <span>MLS# {{ $listing->mls_number }}</span>
                        @if ($listing->agent_name)<span>Agent: {{ $listing->agent_name }}</span>@endif
                        @if ($listing->broker_name)<span>Broker: {{ $listing->broker_name }}</span>@endif
                    </div>
                </div>
            </div>

            {{-- ------------------------------------------------------- decision --}}
            <div class="lg:sticky lg:top-20 lg:self-start">
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    @if (! $listing->meets_criteria && $listing->criteria_failures)
                        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-amber-900">Outside your criteria</div>
                            <ul class="mt-1.5 space-y-1 text-sm text-amber-800">
                                @foreach ($listing->criteria_failures as $failure)
                                    <li class="flex gap-1.5"><span>&bull;</span><span>{{ $failure['label'] }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                    @else
                        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-800">
                            Meets every one of your criteria
                        </div>
                    @endif

                    <h2 class="text-sm font-semibold">Is this one worth keeping?</h2>

                    <form method="POST" action="{{ route('review.decide', $listing) }}" class="mt-3 space-y-2"
                          x-ref="decisionForm">
                        @csrf
                        <input type="hidden" name="decision" value="">

                        <button type="button" @click="submitDecision('favorite')"
                                class="flex w-full items-center justify-center gap-2 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700">
                            <span>★</span> Keep as favorite
                            <kbd class="ml-1 rounded bg-emerald-700/60 px-1.5 py-0.5 font-mono text-[11px]">F</kbd>
                        </button>

                        <button type="button" @click="submitDecision('maybe')"
                                class="flex w-full items-center justify-center gap-2 rounded-lg border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm font-semibold text-amber-800 transition hover:bg-amber-100">
                            <span>?</span> Maybe
                            <kbd class="ml-1 rounded bg-amber-200 px-1.5 py-0.5 font-mono text-[11px]">M</kbd>
                        </button>

                        <button type="button" @click="askReason = true"
                                class="flex w-full items-center justify-center gap-2 rounded-lg bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-rose-700">
                            <span>✕</span> Cross off the list
                            <kbd class="ml-1 rounded bg-rose-700/60 px-1.5 py-0.5 font-mono text-[11px]">X</kbd>
                        </button>

                        {{-- Reason capture: crossing a listing off always records why. --}}
                        <div x-show="askReason" x-transition x-cloak
                             class="rounded-lg border border-rose-200 bg-rose-50/60 p-3">
                            <label class="text-xs font-semibold uppercase tracking-wide text-rose-900">
                                Why is it out?
                            </label>

                            <div class="mt-2 grid gap-1">
                                @foreach ($reasons as $code => $label)
                                    <label class="flex cursor-pointer items-center gap-2 rounded px-1.5 py-1 text-sm hover:bg-white">
                                        <input type="radio" name="reason_code" value="{{ $code }}"
                                               x-model="reasonCode"
                                               class="size-3.5 border-slate-300 text-rose-600 focus:ring-rose-500">
                                        <span class="text-slate-700">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>

                            <textarea name="reason" rows="3" x-model="reasonText"
                                      placeholder="Anything you want to remember about this one..."
                                      class="mt-2 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-rose-500 focus:ring-rose-500"></textarea>

                            <div class="mt-2 flex gap-2">
                                <button type="button"
                                        @click="submitDecision('rejected')"
                                        :disabled="!reasonCode && !reasonText.trim()"
                                        class="flex-1 rounded-md bg-rose-600 px-3 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-40">
                                    Cross it off
                                </button>
                                <button type="button" @click="askReason = false"
                                        class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-600 hover:bg-slate-50">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </form>

                    @if ($listing->decision !== Decision::Undecided)
                        <div class="mt-3 space-y-2 border-t border-slate-100 pt-3">
                            <div class="flex items-center gap-2 text-sm">
                                <span class="text-slate-500">Currently:</span>
                                <x-decision-badge :listing="$listing" />
                            </div>
                            @if ($listing->decision_reason_code || $listing->decision_reason)
                                <p class="text-sm text-slate-600">
                                    {{ \App\Enums\RejectionReason::tryFrom((string) $listing->decision_reason_code)?->label() }}
                                    @if ($listing->decision_reason)
                                        <span class="block text-slate-500">{{ $listing->decision_reason }}</span>
                                    @endif
                                </p>
                            @endif
                            <div class="flex gap-2">
                                <form method="POST" action="{{ route('review.decide', $listing) }}" class="flex-1">
                                    @csrf
                                    <input type="hidden" name="decision" value="undecided">
                                    <input type="hidden" name="stay" value="1">
                                    <button class="w-full rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50">
                                        Put back in queue
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('review.undo', $listing) }}">
                                    @csrf
                                    <button class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50">
                                        Undo
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endif

                    <a href="{{ $listing->url }}" target="_blank" rel="noopener" x-ref="sourceLink"
                       class="mt-3 flex items-center justify-center gap-1.5 rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-sm font-medium text-sky-800 hover:bg-sky-100">
                        Open on UtahRealEstate.com &nearr;
                        <kbd class="rounded bg-sky-200/70 px-1.5 py-0.5 font-mono text-[11px]">O</kbd>
                    </a>
                </div>

                {{-- ----------------------------------------------------------- map --}}
                <div class="mt-4">
                    <x-property-map :listing="$listing" />
                </div>

                {{-- Private notes --}}
                <form method="POST" action="{{ route('review.notes', $listing) }}"
                      class="mt-4 rounded-xl border border-slate-200 bg-white p-4">
                    @csrf
                    <label for="notes" class="text-sm font-semibold">Your notes</label>
                    <textarea id="notes" name="notes" rows="4"
                              class="mt-2 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500"
                              placeholder="Things to check, questions for the agent...">{{ $listing->notes }}</textarea>
                    <button class="mt-2 w-full rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700">
                        Save notes
                    </button>
                </form>

                @if ($listing->priceChanges->isNotEmpty())
                    <div class="mt-4 rounded-xl border border-slate-200 bg-white p-4">
                        <h2 class="text-sm font-semibold">Price history</h2>
                        <ul class="mt-2 space-y-1.5 text-sm">
                            @foreach ($listing->priceChanges as $change)
                                <li class="flex items-center justify-between gap-2 tabular-nums">
                                    <span class="text-slate-500">{{ $change->observed_at->format('M j, Y') }}</span>
                                    <span class="{{ $change->delta < 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                                        ${{ number_format($change->old_price) }} &rarr; ${{ number_format($change->new_price) }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($listing->decisionEvents->isNotEmpty())
                    <div class="mt-4 rounded-xl border border-slate-200 bg-white p-4">
                        <h2 class="text-sm font-semibold">Decision history</h2>
                        <ul class="mt-2 space-y-2 text-sm">
                            @foreach ($listing->decisionEvents as $event)
                                <li>
                                    <div class="flex items-center gap-1.5 text-slate-700">
                                        <span class="text-xs text-slate-400">{{ $event->created_at->format('M j, g:ia') }}</span>
                                        <span>{{ $event->to_decision->label() }}</span>
                                    </div>
                                    @if ($event->reason_label || $event->reason)
                                        <div class="text-xs text-slate-500">
                                            {{ collect([$event->reason_label, $event->reason])->filter()->implode(' — ') }}
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </div>

        {{-- Full-screen photo viewer --}}
        <div x-show="lightbox" x-cloak x-transition.opacity
             class="fixed inset-0 z-50 flex flex-col bg-black/95"
             @click.self="lightbox = false">
            <div class="flex items-center justify-between p-3 text-sm text-white/80">
                <span><span x-text="index + 1"></span> / {{ $photos->count() }}</span>
                <span class="truncate px-4 text-xs" x-text="photos[index]?.caption || ''"></span>
                <button type="button" @click="lightbox = false" class="rounded px-2 py-1 hover:bg-white/10">Close (Esc)</button>
            </div>
            <div class="relative flex-1">
                <template x-for="(photo, i) in photos" :key="'lb-'+photo.url">
                    <img :src="photo.full || photo.url" x-show="i === index" x-cloak
                         class="absolute inset-0 size-full object-contain">
                </template>
                <button type="button" @click="prev()"
                        class="absolute left-3 top-1/2 -translate-y-1/2 rounded-full bg-white/15 p-3 text-white hover:bg-white/25">&#10094;</button>
                <button type="button" @click="next()"
                        class="absolute right-3 top-1/2 -translate-y-1/2 rounded-full bg-white/15 p-3 text-white hover:bg-white/25">&#10095;</button>
            </div>
        </div>

        <script type="application/json" id="photo-data">{!! $photoPayload->toJson() !!}</script>
    </div>
</x-layout>
