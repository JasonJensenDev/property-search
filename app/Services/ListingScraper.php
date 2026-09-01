<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\ScrapeRun;
use App\Models\SearchProfile;
use App\Services\Ure\ListingDetailParser;
use App\Services\Ure\SearchResultParser;
use App\Services\Ure\UreClient;
use Closure;
use Throwable;

/**
 * Runs a full sweep: page through the search results for each city in a profile, then
 * fetch the detail pages needed to judge the listings precisely.
 */
class ListingScraper
{
    /** Their results endpoint returns at most this many cards per page. */
    private const PAGE_SIZE = 50;

    private const MAX_PAGES = 40;

    private ?Closure $progress = null;

    /** Ignore the detail cache, e.g. after the parser has been improved. */
    private bool $forceDetails = false;

    public function __construct(
        private UreClient $client,
        private SearchResultParser $cardParser,
        private ListingDetailParser $detailParser,
        private ListingIngestor $ingestor,
        private Geocoder $geocoder,
    ) {}

    public function onProgress(Closure $callback): self
    {
        $this->progress = $callback;

        return $this;
    }

    public function forceDetails(bool $force = true): self
    {
        $this->forceDetails = $force;

        return $this;
    }

    private function report(ScrapeRun $run, string $line): void
    {
        $run->appendLog($line);

        if ($this->progress) {
            ($this->progress)($line);
        }
    }

    public function run(SearchProfile $profile): ScrapeRun
    {
        $run = ScrapeRun::create([
            'search_profile_id' => $profile->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $seen = $this->collectCards($run, $profile);

            $run->cards_found = count($seen);
            $run->delisted = $this->ingestor->markDelisted($profile, $seen);
            $run->save();

            if ($run->delisted > 0) {
                $this->report($run, "{$run->delisted} listing(s) no longer appear in search results.");
            }

            $this->fetchDetails($run, $profile);
            $this->locateNewListings($run, $profile);

            $run->update(['status' => 'completed', 'finished_at' => now()]);
            $this->report($run, 'Done.');
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'message' => $e->getMessage(),
            ]);
            $this->report($run, 'Failed: '.$e->getMessage());

            throw $e;
        }

        return $run->fresh();
    }

    /**
     * Page through every city in the profile and record the summary cards.
     *
     * @return array<int, string> MLS numbers seen in this run.
     */
    private function collectCards(ScrapeRun $run, SearchProfile $profile): array
    {
        $seen = [];

        foreach ($profile->cities as $city) {
            $this->report($run, "Searching {$city}...");

            $geo = $this->client->resolveCity($city);
            if (! $geo) {
                $this->report($run, "Could not locate \"{$city}\" on utahrealestate.com; skipping.");

                continue;
            }

            $this->client->applyCriteria($geo['city'], $this->remoteCriteria($profile), $geo);

            for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                $result = $this->client->resultsPage($page);
                $cards = $this->cardParser->parse($result['html']);

                if ($cards === []) {
                    break;
                }

                foreach ($cards as $card) {
                    // Their city filter is generous at the boundaries, so confirm the
                    // city before storing anything.
                    if (! $this->cardIsInCity($card, $geo['city'])) {
                        continue;
                    }

                    $outcome = $this->ingestor->ingestCard($card, $profile);
                    $seen[] = $card['mls_number'];

                    $run->listings_created += $outcome['created'] ? 1 : 0;
                    $run->listings_updated += $outcome['created'] ? 0 : 1;
                    $run->price_changes += $outcome['price_changed'] ? 1 : 0;
                }

                $run->save();
                $this->report($run, "{$city} page {$page}: ".count($cards).' listing(s).');

                if (count($cards) < self::PAGE_SIZE) {
                    break;
                }
            }
        }

        return array_values(array_unique($seen));
    }

    private function cardIsInCity(array $card, string $city): bool
    {
        $line = strtolower((string) ($card['address_line'] ?? ''));

        return $line === '' || str_contains($line, strtolower($city));
    }

    /**
     * Translate the profile into utahrealestate.com's coarse filter values.
     *
     * The remote minimums are deliberately looser than the real targets: their square
     * footage filter jumps 3000 -> 4000, so asking for 3500 directly is impossible and
     * asking for 4000 would hide valid homes. We request the nearest bucket at or below
     * the target and let CriteriaEvaluator do the exact work.
     *
     * @return array<string, mixed>
     */
    private function remoteCriteria(SearchProfile $profile): array
    {
        $criteria = [
            'listprice1' => (string) ($profile->min_price ?? ''),
            'listprice2' => (string) ($profile->remote_max_price ?? $profile->max_price ?? ''),
            'tot_sqf1' => (string) ($profile->remote_min_sqft ?? $this->sqftBucket($profile->min_sqft) ?? ''),
            'dim_acres1' => (string) ($profile->remote_min_acres ?? $this->acreBucket($profile->min_acres) ?? ''),
            'tot_bed1' => (string) ($profile->min_beds ?? ''),
            'yearblt1' => '',
            'cap_garage1' => '',
            'proptype' => '',
            'style' => '',
            'o_style' => '4',

            // Their status checkboxes are collapsed into one comma-joined hidden field
            // before submission; sending it directly avoids relying on repeated keys.
            'status' => implode(',', $profile->remote_statuses ?: ['1', '2', '7', '13']),
        ];

        return $criteria;
    }

    /** Largest offered bucket that is still at or below the target. */
    private function sqftBucket(?int $minSqft): ?int
    {
        if (! $minSqft) {
            return null;
        }

        $eligible = array_filter(config('ure.sqft_buckets'), fn ($bucket) => $bucket <= $minSqft);

        return $eligible ? max($eligible) : null;
    }

    private function acreBucket(?float $minAcres): ?string
    {
        if (! $minAcres) {
            return null;
        }

        $best = null;
        foreach (config('ure.acre_buckets') as $bucket) {
            if ((float) $bucket <= $minAcres) {
                $best = $bucket;
            }
        }

        return $best;
    }

    /**
     * Put coordinates on any listing still missing them, so the review screen can show
     * where the house actually is. A failed lookup is not worth failing a run over.
     */
    private function locateNewListings(ScrapeRun $run, SearchProfile $profile): void
    {
        $pending = Listing::query()
            ->where('search_profile_id', $profile->id)
            ->whereNull('delisted_at')
            ->whereNull('latitude')
            ->whereNotNull('street_address')
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        $this->report($run, "Locating {$pending->count()} listing(s) on the map...");

        try {
            $located = $this->geocoder->locateMany($pending);
            $this->report($run, "Located {$located} of {$pending->count()}.");
        } catch (Throwable $e) {
            $this->report($run, 'Could not look up coordinates: '.$e->getMessage());
        }
    }

    /**
     * Fetch detail pages for listings that need one, newest information first.
     */
    private function fetchDetails(ScrapeRun $run, SearchProfile $profile): void
    {
        $ttl = now()->subHours((int) config('ure.detail_ttl_hours'));

        $pending = Listing::query()
            ->where('search_profile_id', $profile->id)
            ->whereNull('delisted_at')
            ->unless($this->forceDetails, fn ($q) => $q
                ->where(fn ($inner) => $inner
                    ->whereNull('detail_scraped_at')
                    ->orWhere('detail_scraped_at', '<', $ttl)))
            ->orderByRaw('detail_scraped_at is not null')
            ->orderBy('price')
            ->limit((int) config('ure.max_details_per_run'))
            ->get();

        if ($pending->isEmpty()) {
            $this->report($run, 'All listing details are already up to date.');

            return;
        }

        $this->report($run, "Fetching details for {$pending->count()} listing(s)...");

        foreach ($pending as $index => $listing) {
            try {
                $html = $this->client->detailPage($listing->mls_number);
                $detail = $this->detailParser->parse($html, $listing->mls_number);
                $this->ingestor->ingestDetail($listing, $detail, $profile);

                $run->details_fetched++;
            } catch (Throwable $e) {
                $this->report($run, "Could not read listing {$listing->mls_number}: ".$e->getMessage());

                continue;
            }

            if (($index + 1) % 10 === 0) {
                $run->save();
                $this->report($run, 'Details: '.($index + 1).' of '.$pending->count().'.');
            }
        }

        $run->save();
    }
}
