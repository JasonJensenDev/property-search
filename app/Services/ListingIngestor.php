<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\SearchProfile;
use App\Services\Ure\UreClient;
use Illuminate\Support\Facades\DB;

/**
 * Writes scraped data into the database without ever disturbing the user's decisions.
 *
 * A listing's decision, reason and notes are owned by the person reviewing, so they are
 * never included in an update. Re-scraping refreshes facts only.
 */
class ListingIngestor
{
    public function __construct(
        private UreClient $client,
        private CriteriaEvaluator $evaluator,
    ) {}

    /**
     * Record a listing discovered in search results, before its detail page is fetched.
     *
     * @param  array<string, mixed>  $card
     * @return array{listing: Listing, created: bool, price_changed: bool}
     */
    public function ingestCard(array $card, SearchProfile $profile): array
    {
        $listing = Listing::firstWhere('mls_number', $card['mls_number']);
        $created = false;
        $priceChanged = false;

        if (! $listing) {
            $listing = new Listing([
                'mls_number' => $card['mls_number'],
                'url' => $this->client->listingUrl($card['mls_number']),
                'first_seen_at' => now(),
            ]);
            $created = true;
        }

        if ($listing->exists && isset($card['price']) && $listing->price && $card['price'] !== $listing->price) {
            $priceChanged = true;
            $listing->priceChanges()->create([
                'old_price' => $listing->price,
                'new_price' => $card['price'],
                'observed_at' => now(),
            ]);
        }

        $listing->fill(array_filter([
            'price' => $card['price'] ?? null,
            'status' => $card['status'] ?? null,
            'days_on_ure' => $card['days_on_ure'] ?? null,
            'beds' => $card['beds'] ?? null,
            'baths' => $card['baths'] ?? null,
            'total_sqft' => $card['total_sqft'] ?? null,
            'primary_photo_url' => $card['photo_url'] ?? null,
        ], fn ($value) => $value !== null));

        $listing->search_profile_id = $profile->id;
        $listing->last_seen_at = now();
        $listing->delisted_at = null;
        $listing->save();

        return ['listing' => $listing, 'created' => $created, 'price_changed' => $priceChanged];
    }

    /**
     * Apply a parsed detail page and re-check the listing against the criteria.
     *
     * @param  array<string, mixed>  $detail
     */
    public function ingestDetail(Listing $listing, array $detail, SearchProfile $profile): Listing
    {
        $photos = $detail['photos'] ?? [];
        unset($detail['photos'], $detail['mls_number']);

        // A detail page beats a summary card, but a null there should not wipe a value
        // the card already gave us.
        $listing->fill(array_filter(
            $detail,
            fn ($value) => $value !== null && $value !== [] && $value !== ''
        ));

        // These are meaningful when false, zero or absent, so set them explicitly. In
        // particular the construction badge disappears once a home is finished, and that
        // absence is the signal that it is now move-in ready.
        $listing->is_new_construction = (bool) ($detail['is_new_construction'] ?? false);
        $listing->hoa_monthly = (int) ($detail['hoa_monthly'] ?? 0);
        $listing->construction_status = $detail['construction_status'] ?? null;

        if (! ($detail['is_new_construction'] ?? false)) {
            $listing->completion_estimate = null;
            $listing->completion_note = null;
        }

        if ($photos) {
            $listing->primary_photo_url = $photos[0]['url'];
            $listing->photos_count = count($photos);
        }

        $listing->detail_scraped_at = now();

        $result = $this->evaluator->evaluate($listing, $profile);
        $listing->meets_criteria = $result['meets'];
        $listing->criteria_failures = $result['failures'];

        DB::transaction(function () use ($listing, $photos) {
            $listing->save();

            if (! $photos) {
                return;
            }

            $keep = collect($photos)->pluck('url')->all();
            $listing->photos()->whereNotIn('url', $keep)->delete();

            foreach ($photos as $photo) {
                $listing->photos()->updateOrCreate(
                    ['url' => $photo['url']],
                    [
                        'position' => $photo['position'],
                        'thumb_url' => $photo['thumb_url'],
                        'full_url' => $photo['full_url'],
                        'caption' => $photo['caption'],
                    ],
                );
            }
        });

        return $listing;
    }

    /**
     * Flag listings that this profile used to return but no longer does, so they drop
     * out of the queue while staying visible in history.
     *
     * @param  array<int, string>  $seenMlsNumbers
     */
    public function markDelisted(SearchProfile $profile, array $seenMlsNumbers): int
    {
        return Listing::query()
            ->where('search_profile_id', $profile->id)
            ->whereNull('delisted_at')
            ->when($seenMlsNumbers !== [], fn ($q) => $q->whereNotIn('mls_number', $seenMlsNumbers))
            ->update(['delisted_at' => now()]);
    }
}
