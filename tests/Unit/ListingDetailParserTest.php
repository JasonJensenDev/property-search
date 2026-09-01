<?php

namespace Tests\Unit;

use App\Services\Ure\ListingDetailParser;
use Tests\TestCase;

/**
 * Parses real saved pages from utahrealestate.com. If they change their markup these
 * fail loudly, which is much better than silently storing nulls and quietly dropping
 * listings out of the review queue.
 */
class ListingDetailParserTest extends TestCase
{
    private function parse(string $fixture, string $mls): array
    {
        return $this->app->make(ListingDetailParser::class)->parse($this->fixture($fixture), $mls);
    }

    public function test_it_reads_the_exact_figures_their_search_cannot_filter_on(): void
    {
        $data = $this->parse('listing-built-with-hoa.html', '2174077');

        // Their search only offers 3000+ or 4000+, so the precise value matters most.
        $this->assertSame(3675, $data['total_sqft']);

        // Lot size to two decimals, where their filter only has coarse buckets.
        $this->assertSame(0.49, $data['acres']);

        // Monthly dues, which their search cannot filter on at all.
        $this->assertSame(40, $data['hoa_monthly']);
        $this->assertStringContainsString('$40/Monthly', $data['hoa_details']);
    }

    public function test_it_reads_address_price_and_room_counts(): void
    {
        $data = $this->parse('listing-built-with-hoa.html', '2174077');

        $this->assertSame('1072 Davenport Dr', $data['street_address']);
        $this->assertSame('Grantsville', $data['city']);
        $this->assertSame('UT', $data['state']);
        $this->assertSame('84029', $data['postal_code']);
        $this->assertSame(625000, $data['price']);

        // Their inline mortgage widget transposes these two, so they come from the
        // visible overview instead.
        $this->assertSame(7, $data['beds']);
        $this->assertSame(4.0, $data['baths']);
    }

    public function test_it_reads_structure_and_feature_detail(): void
    {
        $data = $this->parse('listing-built-with-hoa.html', '2174077');

        $this->assertSame(1999, $data['year_built']);
        $this->assertSame('Single Family', $data['property_type']);
        $this->assertSame('2-Story', $data['style']);
        $this->assertSame(3, $data['garage_capacity']);
        $this->assertSame(90, $data['basement_finished_pct']);

        $this->assertSame(
            ['Floor 2' => 1069, 'Floor 1' => 1341, 'Basement 1' => 1265],
            $data['sqft_levels']
        );

        $this->assertNotEmpty($data['interior_features']);
        $this->assertNotEmpty($data['exterior_features']);
        $this->assertSame('Grantsville', $data['schools']['High School']);
    }

    public function test_it_collects_photos_with_captions_and_sizes(): void
    {
        $data = $this->parse('listing-built-with-hoa.html', '2174077');

        $this->assertGreaterThan(20, count($data['photos']));

        $first = $data['photos'][0];
        $this->assertStringContainsString('/1024x768/2174077_', $first['url']);
        $this->assertStringContainsString('/280x210/2174077_', $first['thumb_url']);
        $this->assertStringContainsString('/2048x1536/2174077_', $first['full_url']);
        $this->assertNotEmpty($first['caption']);

        // Photos must be unique and in a stable order.
        $urls = array_column($data['photos'], 'url');
        $this->assertSame($urls, array_values(array_unique($urls)));
        $this->assertSame(0, $data['photos'][0]['position']);
    }

    public function test_a_finished_home_is_not_flagged_as_new_construction(): void
    {
        $data = $this->parse('listing-built-with-hoa.html', '2174077');

        $this->assertNull($data['construction_status']);
        $this->assertFalse($data['is_new_construction']);
        $this->assertNull($data['completion_estimate']);
    }

    public function test_it_detects_a_home_that_has_not_been_built(): void
    {
        $data = $this->parse('listing-to-be-built.html', '2170616');

        $this->assertSame('To Be Built', $data['construction_status']);
        $this->assertTrue($data['is_new_construction']);

        // Nothing has broken ground, so refuse to invent a completion date.
        $this->assertNull($data['completion_estimate']);
        $this->assertStringContainsString('To Be Built', $data['completion_note']);
    }

    public function test_it_finds_no_hoa_when_the_listing_has_none(): void
    {
        $data = $this->parse('listing-to-be-built.html', '2170616');

        $this->assertSame(0, $data['hoa_monthly']);
    }
}
