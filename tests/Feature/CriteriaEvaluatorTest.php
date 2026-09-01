<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\SearchProfile;
use App\Services\CriteriaEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the filtering utahrealestate.com cannot do: an exact square footage floor, an
 * exact lot size, excluding HOA properties, and ruling out homes that will not be
 * finished in time.
 */
class CriteriaEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private CriteriaEvaluator $evaluator;

    private SearchProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = $this->app->make(CriteriaEvaluator::class);

        $this->profile = SearchProfile::create([
            'name' => 'Test',
            'cities' => ['Grantsville'],
            'remote_statuses' => ['1'],
            'min_sqft' => 3500,
            'min_acres' => 0.25,
            'max_price' => 800000,
            'exclude_hoa' => true,
            'require_move_in_ready' => true,
            'ready_by' => '2026-10-15',
        ]);
    }

    private function listing(array $attributes = []): Listing
    {
        return new Listing(array_merge([
            'mls_number' => '1000001',
            'url' => 'https://example.test/1000001',
            'total_sqft' => 3600,
            'acres' => 0.5,
            'price' => 700000,
            'hoa_monthly' => 0,
            'is_new_construction' => false,
        ], $attributes));
    }

    private function codes(Listing $listing): array
    {
        return array_column($this->evaluator->evaluate($listing, $this->profile)['failures'], 'code');
    }

    public function test_a_listing_inside_every_bound_passes(): void
    {
        $result = $this->evaluator->evaluate($this->listing(), $this->profile);

        $this->assertTrue($result['meets']);
        $this->assertSame([], $result['failures']);
    }

    public function test_it_rejects_a_home_just_under_the_square_footage_floor(): void
    {
        // The whole reason this app exists: their filter would have returned this as a
        // "3000+" match even though it misses the real target by 12 square feet.
        $this->assertContains('sqft', $this->codes($this->listing(['total_sqft' => 3488])));
    }

    public function test_it_accepts_a_home_exactly_on_the_floor(): void
    {
        $this->assertNotContains('sqft', $this->codes($this->listing(['total_sqft' => 3500])));
    }

    public function test_it_rejects_a_lot_below_the_minimum(): void
    {
        $this->assertContains('acres', $this->codes($this->listing(['acres' => 0.24])));
        $this->assertNotContains('acres', $this->codes($this->listing(['acres' => 0.25])));
    }

    public function test_it_rejects_anything_over_budget(): void
    {
        $this->assertContains('price', $this->codes($this->listing(['price' => 800001])));
        $this->assertNotContains('price', $this->codes($this->listing(['price' => 800000])));
    }

    public function test_it_rejects_a_property_with_hoa_dues(): void
    {
        $codes = $this->codes($this->listing(['hoa_monthly' => 25]));

        $this->assertContains('hoa', $codes);
    }

    public function test_it_rejects_an_hoa_that_only_shows_in_the_detail_text(): void
    {
        // Some listings describe an association without stating a monthly figure.
        $codes = $this->codes($this->listing(['hoa_monthly' => 0, 'hoa_details' => 'Pool; Clubhouse']));

        $this->assertContains('hoa', $codes);
    }

    public function test_it_can_allow_an_hoa_up_to_a_cap_instead(): void
    {
        $this->profile->update(['exclude_hoa' => false, 'max_hoa_monthly' => 50]);

        $this->assertNotContains('hoa', $this->codes($this->listing(['hoa_monthly' => 40])));
        $this->assertContains('hoa', $this->codes($this->listing(['hoa_monthly' => 60])));
    }

    public function test_it_rejects_a_build_finishing_after_the_move_date(): void
    {
        $codes = $this->codes($this->listing([
            'is_new_construction' => true,
            'completion_estimate' => '2026-12-31',
        ]));

        $this->assertContains('timing', $codes);
    }

    public function test_it_accepts_a_build_finishing_in_time(): void
    {
        $codes = $this->codes($this->listing([
            'is_new_construction' => true,
            'completion_estimate' => '2026-10-01',
        ]));

        $this->assertNotContains('timing', $codes);
    }

    public function test_it_rejects_an_unfinished_home_with_no_completion_date(): void
    {
        $codes = $this->codes($this->listing([
            'is_new_construction' => true,
            'construction_status' => 'To Be Built',
            'completion_estimate' => null,
        ]));

        $this->assertContains('timing', $codes);
    }

    public function test_timing_is_ignored_when_the_deadline_is_switched_off(): void
    {
        $this->profile->update(['require_move_in_ready' => false]);

        $codes = $this->codes($this->listing([
            'is_new_construction' => true,
            'completion_estimate' => '2027-06-01',
        ]));

        $this->assertNotContains('timing', $codes);
    }

    public function test_a_missing_value_is_not_treated_as_a_failure(): void
    {
        // Guessing here would hide listings whose page simply omitted the field.
        $codes = $this->codes($this->listing(['total_sqft' => null, 'acres' => null]));

        $this->assertNotContains('sqft', $codes);
        $this->assertNotContains('acres', $codes);
    }

    public function test_failures_explain_themselves_in_plain_language(): void
    {
        $result = $this->evaluator->evaluate($this->listing(['total_sqft' => 3000]), $this->profile);

        $this->assertStringContainsString('3,000 sq ft', $result['failures'][0]['label']);
        $this->assertStringContainsString('3,500 sq ft', $result['failures'][0]['label']);
    }

    public function test_a_failure_carries_a_short_form_that_names_the_shortfall(): void
    {
        // Listing cards have room for a phrase, not a sentence, and a bare count of
        // failures would not tell you whether the miss is worth bending on.
        $result = $this->evaluator->evaluate($this->listing(['total_sqft' => 3014]), $this->profile);

        $this->assertSame('486 sq ft short', $result['failures'][0]['short']);
    }

    public function test_the_short_form_covers_every_kind_of_failure(): void
    {
        $shorts = fn (Listing $listing) => array_column(
            $this->evaluator->evaluate($listing, $this->profile)['failures'], 'short', 'code'
        );

        $this->assertSame('0.05 acres short', $shorts($this->listing(['acres' => 0.2]))['acres']);
        $this->assertSame('$25,000 over', $shorts($this->listing(['price' => 825000]))['price']);
        $this->assertSame('HOA $30/mo', $shorts($this->listing(['hoa_monthly' => 30]))['hoa']);

        $late = $this->listing(['is_new_construction' => true, 'completion_estimate' => '2026-11-20']);
        $this->assertSame('ready Nov 20', $shorts($late)['timing']);

        $unknown = $this->listing(['is_new_construction' => true, 'construction_status' => 'To Be Built']);
        $this->assertSame('no completion date', $shorts($unknown)['timing']);
    }

    public function test_refresh_all_reapplies_changed_criteria_to_stored_listings(): void
    {
        $listing = $this->listing(['total_sqft' => 3200]);
        $listing->meets_criteria = true;
        $listing->save();

        $changed = $this->evaluator->refreshAll($this->profile);

        $this->assertSame(1, $changed);
        $this->assertFalse($listing->fresh()->meets_criteria);
    }
}
