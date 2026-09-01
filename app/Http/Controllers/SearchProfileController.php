<?php

namespace App\Http\Controllers;

use App\Models\ScrapeRun;
use App\Models\SearchProfile;
use App\Services\CriteriaEvaluator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchProfileController extends Controller
{
    public function edit(): View
    {
        return view('criteria.edit', [
            'profile' => SearchProfile::active(),
            'runs' => ScrapeRun::latest('id')->limit(10)->get(),
        ]);
    }

    public function update(Request $request, CriteriaEvaluator $evaluator): RedirectResponse
    {
        $profile = SearchProfile::active();

        $validated = $request->validate([
            'cities' => ['required', 'string', 'max:500'],
            'min_sqft' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'max_sqft' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'min_acres' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'max_acres' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'min_price' => ['nullable', 'integer', 'min:0'],
            'max_price' => ['nullable', 'integer', 'min:0'],
            'min_beds' => ['nullable', 'integer', 'min:0', 'max:30'],
            'min_baths' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'min_garage' => ['nullable', 'integer', 'min:0', 'max:20'],
            'exclude_hoa' => ['nullable', 'boolean'],
            'max_hoa_monthly' => ['nullable', 'integer', 'min:0'],
            'require_move_in_ready' => ['nullable', 'boolean'],
            'ready_by' => ['nullable', 'date'],
            'include_under_contract' => ['nullable', 'boolean'],
        ]);

        $cities = collect(explode(',', $validated['cities']))
            ->map(fn (string $city) => trim($city))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $statuses = ['1', '2', '7', '13'];
        if ($request->boolean('include_under_contract')) {
            $statuses[] = '3';
        }

        $profile->update([
            'cities' => $cities,
            'min_sqft' => $validated['min_sqft'] ?? null,
            'max_sqft' => $validated['max_sqft'] ?? null,
            'min_acres' => $validated['min_acres'] ?? null,
            'max_acres' => $validated['max_acres'] ?? null,
            'min_price' => $validated['min_price'] ?? null,
            'max_price' => $validated['max_price'] ?? null,
            'min_beds' => $validated['min_beds'] ?? null,
            'min_baths' => $validated['min_baths'] ?? null,
            'min_garage' => $validated['min_garage'] ?? null,
            'exclude_hoa' => $request->boolean('exclude_hoa'),
            'max_hoa_monthly' => $validated['max_hoa_monthly'] ?? null,
            'require_move_in_ready' => $request->boolean('require_move_in_ready'),
            'ready_by' => $validated['ready_by'] ?? null,
            'remote_statuses' => $statuses,

            // Ask utahrealestate.com for the nearest bucket at or below the real target so
            // nothing that qualifies is filtered out before it reaches us.
            'remote_min_sqft' => $this->sqftBucket($validated['min_sqft'] ?? null),
            'remote_min_acres' => $this->acreBucket($validated['min_acres'] ?? null),
            'remote_max_price' => $validated['max_price'] ?? null,
        ]);

        $changed = $evaluator->refreshAll($profile->fresh());

        return redirect()
            ->route('criteria.edit')
            ->with('status', "Criteria saved. Re-checked every listing; {$changed} changed.");
    }

    private function sqftBucket(?int $minSqft): ?int
    {
        if (! $minSqft) {
            return null;
        }

        $eligible = array_filter(config('ure.sqft_buckets'), fn ($b) => $b <= $minSqft);

        return $eligible ? max($eligible) : null;
    }

    private function acreBucket(?float $minAcres): ?string
    {
        if (! $minAcres) {
            return null;
        }

        $best = null;
        foreach (config('ure.acre_buckets') as $bucket) {
            if ((float) $bucket <= $minAcres) {
                $best = $bucket;
            }
        }

        return $best;
    }
}
