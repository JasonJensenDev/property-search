<?php

namespace Tests\Unit;

use App\Services\Ure\SearchResultParser;
use Tests\TestCase;

class SearchResultParserTest extends TestCase
{
    public function test_it_pulls_every_card_off_a_results_page(): void
    {
        $cards = (new SearchResultParser)->parse($this->fixture('search-results-page.html'));

        $this->assertNotEmpty($cards);

        foreach ($cards as $card) {
            $this->assertMatchesRegularExpression('/^\d+$/', $card['mls_number']);
            $this->assertIsInt($card['price']);
            $this->assertGreaterThan(0, $card['price']);
        }
    }

    public function test_it_reads_the_summary_figures_from_a_card(): void
    {
        $cards = (new SearchResultParser)->parse($this->fixture('search-results-page.html'));
        $card = $cards[0];

        $this->assertNotNull($card['total_sqft']);
        $this->assertNotNull($card['beds']);
        $this->assertNotNull($card['baths']);
        $this->assertNotEmpty($card['status']);

        // The address arrives as one line ending in the state.
        $this->assertSame('UT', $card['state']);
        $this->assertStringContainsString('Grantsville', $card['address_line']);
    }

    public function test_it_returns_nothing_for_an_empty_page(): void
    {
        $this->assertSame([], (new SearchResultParser)->parse('<ul class="property___cards"></ul>'));
    }
}
