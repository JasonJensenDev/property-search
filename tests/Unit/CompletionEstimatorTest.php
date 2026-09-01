<?php

namespace Tests\Unit;

use App\Services\Ure\CompletionEstimator;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class CompletionEstimatorTest extends TestCase
{
    private CompletionEstimator $estimator;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->estimator = new CompletionEstimator;
        $this->now = CarbonImmutable::create(2026, 8, 29);
    }

    private function estimate(?string $description, ?int $year = null, ?string $badge = null): array
    {
        return $this->estimator->estimate($description, $year, $badge, $this->now);
    }

    public function test_a_finished_home_has_no_completion_date(): void
    {
        $result = $this->estimate('Beautiful move in ready home on a large lot.', 2021);

        $this->assertFalse($result['is_new_construction']);
        $this->assertNull($result['completion_estimate']);
    }

    public function test_the_site_badge_outranks_marketing_language(): void
    {
        // "To Be Built" listings routinely also advertise how move-in ready they will be.
        $result = $this->estimate('Move in ready! Quick move-in available.', 2026, 'To Be Built');

        $this->assertTrue($result['is_new_construction']);
        $this->assertNull($result['completion_estimate']);
        $this->assertStringContainsString('To Be Built', $result['completion_note']);
    }

    public function test_to_be_built_in_the_description_also_wins(): void
    {
        $result = $this->estimate('*TO BE BUILT* Welcome to The Lexus, a quick move-in home.', 2026);

        $this->assertTrue($result['is_new_construction']);
    }

    public function test_it_reads_a_named_completion_month(): void
    {
        $result = $this->estimate('Under construction, will complete by the end of September.', 2026, 'Under Construction');

        $this->assertTrue($result['is_new_construction']);
        $this->assertSame('2026-09-30', $result['completion_estimate']->toDateString());
    }

    public function test_a_month_already_past_rolls_into_next_year(): void
    {
        $result = $this->estimate('Under construction, estimated completion March.', 2027, 'Under Construction');

        $this->assertSame('2027-03-31', $result['completion_estimate']->toDateString());
    }

    public function test_it_honours_an_explicit_year(): void
    {
        $result = $this->estimate('Projected completion December 2026.', null, 'Under Construction');

        $this->assertSame('2026-12-31', $result['completion_estimate']->toDateString());
    }

    public function test_it_reads_a_relative_completion_window(): void
    {
        $result = $this->estimate('Under construction and ready in 30 days.', 2026, 'Under Construction');

        $this->assertSame('2026-09-28', $result['completion_estimate']->toDateString());
    }

    public function test_a_future_build_year_alone_means_unfinished(): void
    {
        $result = $this->estimate('Lovely home with mountain views.', 2027);

        $this->assertTrue($result['is_new_construction']);
        $this->assertSame('2027-12-31', $result['completion_estimate']->toDateString());
    }

    public function test_it_copes_with_a_missing_description(): void
    {
        $result = $this->estimate(null, 2026, 'Under Construction');

        $this->assertTrue($result['is_new_construction']);
        $this->assertNull($result['completion_estimate']);
        $this->assertStringContainsString('Under Construction', $result['completion_note']);
    }
}
