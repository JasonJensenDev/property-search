<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Services\Geocoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * utahrealestate.com does not publish coordinates, so addresses are resolved against
 * Nominatim purely to draw a map. The service is free and rate limited, which is what
 * most of this covers: a refusal must never be mistaken for an unknown address.
 */
class GeocoderTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'nominatim.openstreetmap.org/*';

    private function listing(array $attributes = []): Listing
    {
        return Listing::create(array_merge([
            'mls_number' => '1000001',
            'url' => 'https://example.test/1000001',
            'street_address' => '120 Deseret Cir',
            'city' => 'Grantsville',
            'state' => 'UT',
            'postal_code' => '84029',
        ], $attributes));
    }

    private function match(string $lat, string $lon): array
    {
        return [['lat' => $lat, 'lon' => $lon, 'display_name' => 'somewhere']];
    }

    public function test_it_stores_the_coordinates_it_finds(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->match('40.5984088', '-112.4606984'))]);

        $listing = $this->listing();

        $this->assertTrue($this->app->make(Geocoder::class)->locate($listing));

        $listing->refresh();
        $this->assertSame(40.5984088, $listing->latitude);
        $this->assertSame(-112.4606984, $listing->longitude);
        $this->assertSame('exact', $listing->location_precision);
    }

    public function test_it_asks_for_the_full_address_including_the_city(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->match('40.6', '-112.4'))]);

        $this->app->make(Geocoder::class)->locate($this->listing());

        Http::assertSent(function (Request $request) {
            $query = $request->data()['q'];

            // A bare house number matches thousands of places, so the city has to be there.
            return str_contains($query, '120 Deseret Cir')
                && str_contains($query, 'Grantsville')
                && str_contains($query, 'UT');
        });
    }

    public function test_it_falls_back_to_the_street_when_the_house_is_not_mapped(): void
    {
        // New subdivisions routinely have no house-level data yet.
        Http::fakeSequence()
            ->push([], 200)
            ->push($this->match('40.5858962', '-112.4821390'), 200);

        $listing = $this->listing(['street_address' => '562 W Coyote Ridge Rd']);

        $this->assertTrue($this->app->make(Geocoder::class)->locate($listing));
        $this->assertSame('street', $listing->fresh()->location_precision);
    }

    public function test_the_street_fallback_drops_only_the_house_number(): void
    {
        Http::fakeSequence()->push([], 200)->push($this->match('40.5', '-112.4'), 200);

        $this->app->make(Geocoder::class)->locate($this->listing(['street_address' => '562 W Coyote Ridge Rd']));

        Http::assertSent(fn (Request $request) => $request->data()['q'] === 'W Coyote Ridge Rd, Grantsville, UT, 84029');
    }

    public function test_it_repairs_a_street_name_the_feed_cut_short(): void
    {
        // Their data truncates to a fixed width, so "Pear Street" arrives as "Pear Stre",
        // which no geocoder recognises.
        Http::fakeSequence()
            ->push([], 200)
            ->push([], 200)
            ->push($this->match('40.5885027', '-112.4614890'), 200);

        $listing = $this->listing(['street_address' => '340 E Pear Stre']);

        $this->assertTrue($this->app->make(Geocoder::class)->locate($listing));

        Http::assertSent(fn (Request $request) => $request->data()['q'] === 'E Pear Stre, Grantsville, UT, 84029');
        Http::assertSent(fn (Request $request) => $request->data()['q'] === 'E Pear Street, Grantsville, UT, 84029');
    }

    public function test_it_leaves_a_normal_abbreviation_alone(): void
    {
        // "Dr" and "Ct" are understood as written, so there is nothing to repair and no
        // reason to spend a third request.
        Http::fake([self::ENDPOINT => Http::response([])]);

        $this->app->make(Geocoder::class)->locate($this->listing(['street_address' => '635 S Chan Dr']));

        Http::assertSentCount(2);
    }

    public function test_an_unknown_address_is_left_without_coordinates(): void
    {
        Http::fake([self::ENDPOINT => Http::response([])]);

        $listing = $this->listing();

        $this->assertFalse($this->app->make(Geocoder::class)->locate($listing));
        $this->assertNull($listing->fresh()->latitude);
    }

    public function test_being_refused_is_not_recorded_as_an_unknown_address(): void
    {
        // The distinction matters: a refusal should be retried later, and a listing must
        // not be written off because a free service was busy.
        Http::fake([self::ENDPOINT => Http::response('Too many requests', 429)]);

        $geocoder = $this->app->make(Geocoder::class);
        $listing = $this->listing();

        $this->assertFalse($geocoder->locate($listing));
        $this->assertTrue($geocoder->wasRateLimited());
        $this->assertNull($listing->fresh()->latitude);
    }

    public function test_it_retries_once_after_being_refused(): void
    {
        Http::fakeSequence()
            ->push('Too many requests', 429)
            ->push($this->match('40.6', '-112.4'), 200);

        $geocoder = $this->app->make(Geocoder::class);

        $this->assertTrue($geocoder->locate($this->listing()));
        $this->assertFalse($geocoder->wasRateLimited());
    }

    public function test_a_batch_stops_early_once_the_service_refuses(): void
    {
        Http::fake([self::ENDPOINT => Http::response('Too many requests', 429)]);

        $listings = collect(range(1, 5))->map(fn ($i) => $this->listing(['mls_number' => "200000{$i}"]));

        $attempted = 0;
        $geocoder = $this->app->make(Geocoder::class);
        $geocoder->locateMany($listings, function () use (&$attempted) {
            $attempted++;
        });

        // Continuing would report four more listings as unknown when they were never asked.
        $this->assertSame(1, $attempted);
    }

    public function test_it_skips_a_listing_that_already_has_coordinates(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->match('40.6', '-112.4'))]);

        $listing = $this->listing(['latitude' => 40.1, 'longitude' => -112.1]);

        $this->assertTrue($this->app->make(Geocoder::class)->locate($listing));

        Http::assertNothingSent();
    }

    public function test_it_does_not_bother_asking_without_a_street_and_city(): void
    {
        Http::fake();

        $this->assertFalse($this->app->make(Geocoder::class)->locate($this->listing(['city' => null])));

        Http::assertNothingSent();
    }
}
