<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\SearchProfile;
use Carbon\CarbonImmutable;

/**
 * Applies the precise criteria that utahrealestate.com cannot express.
 *
 * Their search rounds square footage to 500/1000-step buckets, has no exact lot size,
 * and no way to exclude HOA properties, so a listing is scraped on loose criteria and
 * judged properly here. Failures are recorded per listing so the UI can explain why
 * something was excluded rather than silently hiding it.
 */
class CriteriaEvaluator
{
    /**
     * @return array{meets: bool, failures: array<int, array{code: string, label: string, short: string}>}
     */
    public function evaluate(Listing $listing, SearchProfile $profile): array
    {
        $failures = [];

        $this->checkRange($failures, 'sqft', 'Square feet', $listing->total_sqft, $profile->min_sqft, $profile->max_sqft, fn ($v) => number_format($v).' sq ft');
        $this->checkRange($failures, 'acres', 'Lot size', $listing->acres, $profile->min_acres, $profile->max_acres, fn ($v) => rtrim(rtrim(number_format($v, 2), '0'), '.').' acres');
        $this->checkRange($failures, 'price', 'Price', $listing->price, $profile->min_price, $profile->max_price, fn ($v) => '$'.number_format($v));

        if ($profile->min_beds && $listing->beds !== null && $listing->beds < $profile->min_beds) {
            $shortBy = $profile->min_beds - $listing->beds;
            $failures[] = $this->failure('beds', "Only {$listing->beds} bedrooms, wanted {$profile->min_beds}+", $shortBy.' bd short');
        }

        if ($profile->min_baths && $listing->baths !== null && $listing->baths < $profile->min_baths) {
            $shortBy = (float) $profile->min_baths - (float) $listing->baths;
            $failures[] = $this->failure('baths', "Only {$listing->baths} bathrooms, wanted {$profile->min_baths}+", $shortBy.' ba short');
        }

        if ($profile->min_garage && $listing->garage_capacity !== null && $listing->garage_capacity < $profile->min_garage) {
            $failures[] = $this->failure(
                'garage',
                "Garage holds {$listing->garage_capacity}, wanted {$profile->min_garage}+",
                'garage fits '.$listing->garage_capacity,
            );
        }

        if ($profile->exclude_hoa && $listing->has_hoa) {
            $dues = $listing->hoa_monthly > 0 ? ' ($'.number_format($listing->hoa_monthly).'/mo)' : '';
            $failures[] = $this->failure(
                'hoa',
                'Has an HOA'.$dues,
                $listing->hoa_monthly > 0 ? 'HOA $'.number_format($listing->hoa_monthly).'/mo' : 'has an HOA',
            );
        } elseif ($profile->max_hoa_monthly !== null && $listing->hoa_monthly > $profile->max_hoa_monthly) {
            $failures[] = $this->failure(
                'hoa',
                'HOA is $'.number_format($listing->hoa_monthly).'/mo, cap is $'.number_format($profile->max_hoa_monthly),
                'HOA $'.number_format($listing->hoa_monthly).'/mo',
            );
        }

        if ($profile->require_move_in_ready) {
            $timing = $this->timingFailure($listing, $profile);
            if ($timing) {
                $failures[] = $timing;
            }
        }

        return ['meets' => $failures === [], 'failures' => $failures];
    }

    /**
     * A listing passes on timing when it is already standing, or when its estimated
     * completion lands on or before the move date.
     */
    private function timingFailure(Listing $listing, SearchProfile $profile): ?array
    {
        if (! $listing->is_new_construction) {
            return null;
        }

        $readyBy = $profile->ready_by
            ? CarbonImmutable::parse($profile->ready_by)
            : null;

        if (! $readyBy) {
            return null;
        }

        if ($listing->completion_estimate === null) {
            return $this->failure(
                'timing',
                $listing->construction_status
                    ? "Listed as \"{$listing->construction_status}\" with no completion date"
                    : 'Not finished yet, and no completion date given',
                'no completion date',
            );
        }

        $estimate = CarbonImmutable::parse($listing->completion_estimate);

        if ($estimate->greaterThan($readyBy)) {
            return $this->failure(
                'timing',
                'Completes around '.$estimate->format('M j, Y').', after '.$readyBy->format('M j, Y'),
                'ready '.$estimate->format('M j'),
            );
        }

        return null;
    }

    /**
     * Records a failure when a value falls outside the wanted range. A missing value is
     * not treated as a failure, because guessing would hide listings whose detail page
     * simply omitted the field.
     */
    private function checkRange(
        array &$failures,
        string $code,
        string $label,
        int|float|null $value,
        int|float|null $min,
        int|float|null $max,
        callable $format,
    ): void {
        if ($value === null) {
            return;
        }

        if ($min !== null && $value < $min) {
            $failures[] = $this->failure(
                $code,
                "{$label} is {$format($value)}, wanted {$format($min)} or more",
                $format($min - $value).' short',
            );
        }

        if ($max !== null && $value > $max) {
            $failures[] = $this->failure(
                $code,
                "{$label} is {$format($value)}, wanted {$format($max)} or less",
                $format($value - $max).' over',
            );
        }
    }

    /**
     * A failure carries both a full sentence for the review screen and a short phrase
     * that fits on a listing card, so a near miss can be judged without opening it.
     *
     * @return array{code: string, label: string, short: string}
     */
    private function failure(string $code, string $label, string $short): array
    {
        return ['code' => $code, 'label' => $label, 'short' => $short];
    }

    /** Re-evaluate every stored listing, e.g. after criteria are edited. */
    public function refreshAll(SearchProfile $profile): int
    {
        $changed = 0;

        Listing::query()->chunkById(200, function ($listings) use ($profile, &$changed) {
            foreach ($listings as $listing) {
                $result = $this->evaluate($listing, $profile);

                if ($listing->meets_criteria !== $result['meets']
                    || $listing->criteria_failures !== $result['failures']) {
                    $listing->update([
                        'meets_criteria' => $result['meets'],
                        'criteria_failures' => $result['failures'],
                    ]);
                    $changed++;
                }
            }
        });

        return $changed;
    }
}
