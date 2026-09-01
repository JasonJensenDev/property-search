<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\SearchProfile;
use App\Services\ListingScraper;
use App\Services\Ure\SearchResultParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Locks in the request sequence their public search requires.
 *
 * Criteria live in the PHP session and are pushed there by /search/chained.update with
 * all=1; the results endpoint then reads the session. Posting criteria straight at the
 * results endpoint returns an unfiltered statewide firehose instead, which looks like a
 * successful scrape but quietly fills the database with the wrong houses. These tests
 * exist so that failure mode cannot come back unnoticed.
 */
class ScrapeProtocolTest extends TestCase
{
    use RefreshDatabase;

    private SearchProfile $profile;

    /** The first listing on the saved results page, resolved so refreshing it is safe. */
    private function fixtureCard(): array
    {
        return $this->app->make(SearchResultParser::class)
            ->parse($this->fixture('search-results-page.html'))[0];
    }

    private function fixtureMls(): string
    {
        return $this->fixtureCard()['mls_number'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ure.delay_ms', 0);
        config()->set('ure.retries', 1);

        $this->profile = SearchProfile::create([
            'name' => 'Test',
            'cities' => ['Grantsville'],
            'remote_statuses' => ['1', '2', '7', '13'],
            'remote_min_sqft' => 3000,
            'remote_min_acres' => '.25',
            'remote_max_price' => 800000,
            'min_sqft' => 3500,
            'min_acres' => 0.25,
            'max_price' => 800000,
            'exclude_hoa' => true,
        ]);
    }

    private function fakeSite(array $overrides = []): void
    {
        Http::fake(array_merge([
            'v1backend.utahrealestate.com/search/location-filter*' => Http::response([
                'data' => ['geocache' => ['hits' => ['hits' => [[
                    '_source' => [
                        'city' => 'Grantsville',
                        'latitude' => '40.5999425',
                        'longitude' => '-112.4643988',
                        'bbox' => ['south' => 40.56, 'west' => -112.52, 'east' => -112.42, 'north' => 40.63],
                    ],
                ]]]]],
            ]),
            '*/search/map.search*' => Http::response('<html><body>search</body></html>'),
            '*/search/chained.update' => Http::response([
                // The city arriving back as a numeric id is the site's confirmation that
                // the location filter was accepted.
                'params' => ['city' => 703, 'o_city' => 4, 'tot_sqf1' => '3000', 'status' => '1,2,7,13'],
            ]),
            '*/search/map.inline.results/pg/1/*' => Http::response([
                'page_count' => 1,
                'listing_data' => [['listno' => $this->fixtureMls()]],
                'html' => $this->fixture('search-results-page.html'),
            ]),
            '*/search/map.inline.results/pg/*' => Http::response([
                'page_count' => 0, 'listing_data' => [], 'html' => '',
            ]),
            // The saved detail page belongs to another listing, so its MLS number is
            // rewritten to the one under test. The parser scopes photos by MLS number,
            // and this keeps that behaviour exercised rather than sidestepped.
            'www.utahrealestate.com/*' => Http::response(str_replace(
                '2174077',
                $this->fixtureMls(),
                $this->fixture('listing-built-with-hoa.html')
            )),
            // A scrape finishes by putting listings on the map.
            'nominatim.openstreetmap.org/*' => Http::response([
                ['lat' => '40.5984088', 'lon' => '-112.4606984', 'display_name' => 'Grantsville'],
            ]),
        ], $overrides));

        // Anything not stubbed above would otherwise go out to the real internet, which is
        // slow, rude, and makes the suite depend on someone else's uptime.
        Http::preventStrayRequests();
    }

    private function scrape(): void
    {
        $this->app->make(ListingScraper::class)->run($this->profile->fresh());
    }

    public function test_criteria_are_pushed_to_the_session_before_results_are_read(): void
    {
        $this->fakeSite();
        $this->scrape();

        $order = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0]->url())
            ->filter(fn (string $url) => str_contains($url, 'chained.update') || str_contains($url, 'map.inline.results'))
            ->values();

        $this->assertStringContainsString('chained.update', $order->first());
        $this->assertStringContainsString('map.inline.results', $order->get(1));
    }

    public function test_the_criteria_request_carries_the_flags_their_endpoint_requires(): void
    {
        $this->fakeSite();
        $this->scrape();

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'chained.update')) {
                return false;
            }

            $data = $request->data();

            return $data['all'] === '1'          // accept the whole form, not one field
                && $data['param'] === 'city'      // the field being "changed"
                && $data['value'] === 'Grantsville'
                && $data['tx'] === 'true'         // translate the city name to their id
                && $data['op'] === '4'            // operator their city filter expects
                && $data['htype'] === 'city'
                && $data['hval'] === 'Grantsville'
                && str_contains($data['param_reset'], 'city');
        });
    }

    public function test_it_asks_for_the_looser_bucket_their_filter_actually_offers(): void
    {
        $this->fakeSite();
        $this->scrape();

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'chained.update')) {
                return false;
            }

            $data = $request->data();

            // 3,500 is not selectable on their site, so 3,000 is requested and the exact
            // floor is applied locally.
            return $data['tot_sqf1'] === '3000'
                && $data['dim_acres1'] === '.25'
                && $data['listprice2'] === '800000';
        });
    }

    public function test_statuses_are_sent_as_one_comma_joined_field(): void
    {
        $this->fakeSite();
        $this->scrape();

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'chained.update')
                && $request->data()['status'] === '1,2,7,13';
        });
    }

    public function test_it_refuses_to_continue_when_the_city_filter_is_rejected(): void
    {
        // Without a city the results endpoint happily returns listings from the whole
        // state, so this has to be treated as a hard failure rather than an empty result.
        $this->fakeSite([
            '*/search/chained.update' => Http::response(['params' => ['tot_sqf1' => '3000']]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not accept "Grantsville" as a city filter');

        $this->scrape();
    }

    public function test_a_scrape_stores_listings_with_their_exact_figures(): void
    {
        $this->fakeSite();
        $this->scrape();

        $listing = Listing::firstWhere('mls_number', $this->fixtureMls());

        $this->assertNotNull($listing);
        $this->assertSame(3675, $listing->total_sqft);
        $this->assertSame(0.49, $listing->acres);
        $this->assertSame(40, $listing->hoa_monthly);
        $this->assertNotNull($listing->detail_scraped_at);
        $this->assertGreaterThan(0, $listing->photos()->count());
    }

    public function test_the_exact_criteria_are_applied_during_ingest(): void
    {
        $this->fakeSite();
        $this->scrape();

        $listing = Listing::firstWhere('mls_number', $this->fixtureMls());

        // 3,675 sq ft clears the floor, but the $40/month HOA rules it out.
        $this->assertFalse($listing->meets_criteria);
        $this->assertContains('hoa', array_column($listing->criteria_failures, 'code'));
    }

    public function test_listings_that_stop_appearing_are_marked_off_market(): void
    {
        $stale = Listing::create([
            'mls_number' => '1111111',
            'url' => 'https://example.test/1111111',
            'search_profile_id' => $this->profile->id,
        ]);

        $this->fakeSite();
        $this->scrape();

        $this->assertNotNull($stale->fresh()->delisted_at);
    }

    public function test_a_price_change_is_recorded_between_runs(): void
    {
        $this->fakeSite();
        $this->scrape();

        $listing = Listing::firstWhere('mls_number', $this->fixtureMls());
        $listing->update(['price' => 640000, 'detail_scraped_at' => null]);

        $this->scrape();

        $change = $listing->fresh()->priceChanges()->first();

        $this->assertNotNull($change);
        $this->assertSame(640000, $change->old_price);
        $this->assertSame($this->fixtureCard()['price'], $change->new_price);
    }

    public function test_a_rescrape_never_overwrites_a_decision(): void
    {
        $this->fakeSite();
        $this->scrape();

        $listing = Listing::firstWhere('mls_number', $this->fixtureMls());
        $listing->update([
            'decision' => 'rejected',
            'decision_reason' => 'Too close to the road.',
            'notes' => 'Called the agent.',
            'detail_scraped_at' => null,
        ]);

        $this->scrape();

        $listing->refresh();
        $this->assertSame('rejected', $listing->decision->value);
        $this->assertSame('Too close to the road.', $listing->decision_reason);
        $this->assertSame('Called the agent.', $listing->notes);
    }
}
