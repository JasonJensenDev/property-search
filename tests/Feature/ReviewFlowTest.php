<?php

namespace Tests\Feature;

use App\Enums\Decision;
use App\Models\Listing;
use App\Models\SearchProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SearchProfile::create([
            'name' => 'Test',
            'cities' => ['Grantsville'],
            'remote_statuses' => ['1'],
            'min_sqft' => 3500,
            'max_price' => 800000,
        ]);
    }

    private function listing(array $attributes = []): Listing
    {
        static $counter = 0;
        $counter++;

        return Listing::create(array_merge([
            'mls_number' => '200000'.$counter,
            'url' => 'https://example.test/200000'.$counter,
            'street_address' => $counter.' Test St',
            'city' => 'Grantsville',
            'state' => 'UT',
            'price' => 600000 + $counter,
            'total_sqft' => 3600,
            'acres' => 0.5,
            'beds' => 4,
            'baths' => 3,
            'meets_criteria' => true,
            'decision' => Decision::Undecided->value,
        ], $attributes));
    }

    public function test_the_queue_shows_the_cheapest_undecided_match_first(): void
    {
        $expensive = $this->listing(['price' => 790000]);
        $cheap = $this->listing(['price' => 510000]);

        $this->get(route('review.index'))->assertRedirect(route('review.show', $cheap));
        $this->assertNotSame($expensive->id, $cheap->id);
    }

    public function test_listings_outside_the_criteria_stay_out_of_the_queue(): void
    {
        $this->listing(['meets_criteria' => false]);

        $this->get(route('review.index'))->assertOk()->assertSee('Nothing left to review');
    }

    public function test_an_off_market_listing_stays_out_of_the_queue(): void
    {
        $this->listing(['delisted_at' => now()]);

        $this->get(route('review.index'))->assertOk()->assertSee('Nothing left to review');
    }

    public function test_the_review_page_shows_the_detail_needed_to_judge_a_listing(): void
    {
        $listing = $this->listing([
            'total_sqft' => 3712,
            'acres' => 0.42,
            'hoa_monthly' => 0,
            'description' => 'A very specific description of this house.',
        ]);

        $this->get(route('review.show', $listing))
            ->assertOk()
            ->assertSee('3,712')
            ->assertSee('0.42 acres')
            ->assertSee('No HOA')
            ->assertSee('A very specific description of this house.');
    }

    public function test_the_review_page_links_to_the_original_listing(): void
    {
        $listing = $this->listing(['url' => 'https://www.utahrealestate.com/2150062']);

        $this->get(route('review.show', $listing))
            ->assertOk()
            ->assertSee('https://www.utahrealestate.com/2150062')
            ->assertSee('View listing on UtahRealEstate.com');
    }

    public function test_a_located_listing_shows_a_map_that_opens_google_maps(): void
    {
        $listing = $this->listing(['latitude' => 40.5984088, 'longitude' => -112.4606984]);

        $this->get(route('review.show', $listing))
            ->assertOk()
            ->assertSee('tile.openstreetmap.org', false)
            ->assertSee('© OpenStreetMap', false)
            ->assertSee('google.com/maps/search/?api=1&amp;query=40.5984088,-112.4606984', false);
    }

    public function test_a_street_level_location_says_so_on_the_map(): void
    {
        // New subdivisions are often only mapped to the street. Saying so beats a pin that
        // looks more precise than it is.
        $listing = $this->listing([
            'latitude' => 40.5883186,
            'longitude' => -112.4530311,
            'location_precision' => 'street',
        ]);

        $this->get(route('review.show', $listing))
            ->assertOk()
            ->assertSee('Street only');
    }

    public function test_an_exactly_located_listing_is_not_labelled_approximate(): void
    {
        $listing = $this->listing([
            'latitude' => 40.5984088,
            'longitude' => -112.4606984,
            'location_precision' => 'exact',
        ]);

        $this->get(route('review.show', $listing))
            ->assertOk()
            ->assertDontSee('Street only');
    }

    public function test_a_listing_without_coordinates_still_offers_a_map_lookup(): void
    {
        $listing = $this->listing(['street_address' => '120 Deseret Cir', 'latitude' => null]);

        $response = $this->get(route('review.show', $listing));

        $response->assertOk()
            ->assertDontSee('tile.openstreetmap.org', false)
            ->assertSee('Look up on Google Maps');

        // The search still has to name the house, not just the town.
        $this->assertStringContainsString('120+Deseret+Cir', $response->getContent());
    }

    public function test_the_decision_form_posts_a_decision_field(): void
    {
        // The field is filled in by JavaScript on click. It once relied on an Alpine
        // binding that had not flushed by the time the form submitted, so every decision
        // posted empty and came back "The decision field is required."
        $listing = $this->listing();

        $response = $this->get(route('review.show', $listing))->assertOk();

        $this->assertStringContainsString('name="decision"', $response->getContent());
        $this->assertStringNotContainsString('name="decision" x-model', $response->getContent());
    }

    public function test_keeping_a_listing_records_it_as_a_favorite(): void
    {
        $listing = $this->listing();

        $this->post(route('review.decide', $listing), ['decision' => 'favorite'])
            ->assertRedirect();

        $listing->refresh();
        $this->assertSame(Decision::Favorite, $listing->decision);
        $this->assertNotNull($listing->decided_at);
    }

    public function test_crossing_a_listing_off_stores_the_reason(): void
    {
        $listing = $this->listing();

        $this->post(route('review.decide', $listing), [
            'decision' => 'rejected',
            'reason_code' => 'bad_layout',
            'reason' => 'Bedrooms are all upstairs and the kitchen is tiny.',
        ])->assertRedirect();

        $listing->refresh();
        $this->assertSame(Decision::Rejected, $listing->decision);
        $this->assertSame('bad_layout', $listing->decision_reason_code);
        $this->assertStringContainsString('kitchen is tiny', $listing->decision_reason);
    }

    public function test_it_refuses_to_cross_a_listing_off_without_a_reason(): void
    {
        $listing = $this->listing();

        $this->from(route('review.show', $listing))
            ->post(route('review.decide', $listing), ['decision' => 'rejected'])
            ->assertSessionHasErrors('reason_code');

        $this->assertSame(Decision::Undecided, $listing->fresh()->decision);
    }

    public function test_free_text_alone_is_enough_of_a_reason(): void
    {
        $listing = $this->listing();

        $this->post(route('review.decide', $listing), [
            'decision' => 'rejected',
            'reason' => 'Backs onto the highway.',
        ])->assertRedirect();

        $this->assertSame(Decision::Rejected, $listing->fresh()->decision);
    }

    public function test_a_decided_listing_leaves_the_queue(): void
    {
        $listing = $this->listing();

        $this->post(route('review.decide', $listing), [
            'decision' => 'rejected',
            'reason_code' => 'location',
        ]);

        $this->assertSame(0, Listing::reviewQueue()->count());
    }

    public function test_deciding_moves_on_to_the_next_listing(): void
    {
        $first = $this->listing(['price' => 500000]);
        $second = $this->listing(['price' => 600000]);

        $this->post(route('review.decide', $first), ['decision' => 'favorite'])
            ->assertRedirect(route('review.show', $second));
    }

    public function test_every_decision_is_kept_in_history(): void
    {
        $listing = $this->listing();

        $this->post(route('review.decide', $listing), ['decision' => 'maybe']);
        $this->post(route('review.decide', $listing), [
            'decision' => 'rejected',
            'reason_code' => 'dated',
        ]);

        $events = $listing->decisionEvents()->orderBy('id')->get();

        $this->assertCount(2, $events);
        $this->assertSame(Decision::Maybe, $events[0]->to_decision);
        $this->assertSame(Decision::Maybe, $events[1]->from_decision);
        $this->assertSame(Decision::Rejected, $events[1]->to_decision);
    }

    public function test_undo_steps_back_to_the_previous_decision(): void
    {
        $listing = $this->listing();

        $this->post(route('review.decide', $listing), ['decision' => 'maybe']);
        $this->post(route('review.decide', $listing), [
            'decision' => 'rejected',
            'reason_code' => 'dated',
        ]);

        $this->post(route('review.undo', $listing))->assertRedirect();

        $this->assertSame(Decision::Maybe, $listing->fresh()->decision);
    }

    public function test_a_crossed_off_listing_can_be_put_back_in_the_queue(): void
    {
        $listing = $this->listing();

        $this->post(route('review.decide', $listing), [
            'decision' => 'rejected',
            'reason_code' => 'location',
        ]);

        $this->post(route('review.decide', $listing), ['decision' => 'undecided', 'stay' => '1']);

        $listing->refresh();
        $this->assertSame(Decision::Undecided, $listing->decision);
        $this->assertNull($listing->decision_reason_code);
        $this->assertSame(1, Listing::reviewQueue()->count());
    }

    public function test_notes_are_saved_against_a_listing(): void
    {
        $listing = $this->listing();

        $this->post(route('review.notes', $listing), ['notes' => 'Ask about the well water.'])
            ->assertRedirect();

        $this->assertSame('Ask about the well water.', $listing->fresh()->notes);
    }

    public function test_the_rejected_list_shows_why_each_one_is_out(): void
    {
        $listing = $this->listing();

        $this->post(route('review.decide', $listing), [
            'decision' => 'rejected',
            'reason_code' => 'lot_too_small',
            'reason' => 'Lot is mostly a steep hillside.',
        ]);

        $this->get(route('listings.index', ['decision' => 'rejected']))
            ->assertOk()
            ->assertSee('Lot is mostly a steep hillside.');
    }
}
