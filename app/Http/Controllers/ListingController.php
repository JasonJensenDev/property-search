<?php

namespace App\Http\Controllers;

use App\Enums\Decision;
use App\Enums\RejectionReason;
use App\Models\Listing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ListingController extends Controller
{
    private const SORTS = [
        'price' => ['Price', 'price', 'asc'],
        'price_desc' => ['Price (high first)', 'price', 'desc'],
        'sqft' => ['Square feet (big first)', 'total_sqft', 'desc'],
        'acres' => ['Lot size (big first)', 'acres', 'desc'],
        'ppsf' => ['Price per sq ft', 'price_per_sqft', 'asc'],
        'newest' => ['Newest listing', 'days_on_ure', 'asc'],
        'year' => ['Year built (newest)', 'year_built', 'desc'],
    ];

    public function index(Request $request): View
    {
        $sortKey = array_key_exists($request->query('sort'), self::SORTS) ? $request->query('sort') : 'price';
        [, $column, $direction] = self::SORTS[$sortKey];

        $query = Listing::query()->with('photos');

        $this->applyFilters($query, $request);

        // Price per square foot is derived, so sort it in SQL rather than in PHP.
        if ($column === 'price_per_sqft') {
            $query->orderByRaw('case when total_sqft > 0 then price * 1.0 / total_sqft end '.$direction);
        } else {
            $query->orderBy($column, $direction);
        }

        $listings = $query->paginate(24)->withQueryString();

        return view('listings.index', [
            'listings' => $listings,
            'sorts' => collect(self::SORTS)->map(fn ($s) => $s[0]),
            'sortKey' => $sortKey,
            'filters' => $request->only(['decision', 'match', 'status', 'hoa', 'min_sqft', 'max_price', 'min_acres', 'search', 'delisted']),
            'reasons' => RejectionReason::options(),
        ]);
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        $query->when(
            $request->query('delisted') === '1',
            fn (Builder $q) => $q->whereNotNull('delisted_at'),
            fn (Builder $q) => $q->whereNull('delisted_at'),
        );

        if ($decision = $request->query('decision')) {
            if ($decision !== 'all' && Decision::tryFrom($decision)) {
                $query->where('decision', $decision);
            }
        }

        match ($request->query('match')) {
            'yes' => $query->where('meets_criteria', true),
            'no' => $query->where('meets_criteria', false),
            default => null,
        };

        match ($request->query('hoa')) {
            'none' => $query->where('hoa_monthly', 0)->whereNull('hoa_details'),
            'has' => $query->where(fn (Builder $q) => $q->where('hoa_monthly', '>', 0)->orWhereNotNull('hoa_details')),
            default => null,
        };

        if ($status = $request->query('status')) {
            $query->where('status', 'like', '%'.$status.'%');
        }

        if ($minSqft = $request->integer('min_sqft')) {
            $query->where('total_sqft', '>=', $minSqft);
        }

        if ($maxPrice = $request->integer('max_price')) {
            $query->where('price', '<=', $maxPrice);
        }

        if ($minAcres = $request->query('min_acres')) {
            $query->where('acres', '>=', (float) $minAcres);
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn (Builder $q) => $q
                ->where('street_address', 'like', "%{$search}%")
                ->orWhere('mls_number', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhere('notes', 'like', "%{$search}%"));
        }
    }
}
