<?php

namespace Tests\Feature;

use App\Enums\Decision;
use App\Models\Listing;
use App\Models\SearchProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
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
            'is_active' => true,
        ]);
    }

    private function listing(array $attributes = []): Listing
    {
        static $counter = 0;
        $counter++;

        return Listing::create(array_merge([
            'mls_number' => '210000'.$counter,
            'url' => 'https://example.test/210000'.$counter,
            'street_address' => $counter.' Overview St',
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

    public function test_the_overview_shows_maybe_listings_under_the_shortlist(): void
    {
        $this->listing([
            'street_address' => '10 Favorite Ave',
            'decision' => Decision::Favorite->value,
        ]);
        $this->listing([
            'street_address' => '20 Maybe Ln',
            'decision' => Decision::Maybe->value,
        ]);

        $html = $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Your shortlist')
            ->assertSee('>Maybes</h2>', false)
            ->assertSee('10 Favorite Ave')
            ->assertSee('20 Maybe Ln')
            ->getContent();

        $shortlistPos = strpos($html, 'Your shortlist');
        $maybesHeadingPos = strpos($html, '>Maybes</h2>');
        $maybeAddressPos = strpos($html, '20 Maybe Ln');
        $favoriteAddressPos = strpos($html, '10 Favorite Ave');

        $this->assertNotFalse($shortlistPos);
        $this->assertNotFalse($maybesHeadingPos);
        $this->assertLessThan($maybesHeadingPos, $shortlistPos);
        $this->assertLessThan($maybeAddressPos, $maybesHeadingPos);
        $this->assertLessThan($maybesHeadingPos, $favoriteAddressPos);
    }

    public function test_the_overview_shows_an_empty_maybes_state(): void
    {
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('>Maybes</h2>', false)
            ->assertSee('Nothing flagged as a maybe yet.');
    }
}
