<?php

namespace App\Http\Controllers;

use App\Enums\Decision;
use App\Models\Listing;
use App\Models\ScrapeRun;
use App\Models\SearchProfile;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $profile = SearchProfile::query()->where('is_active', true)->orderBy('id')->first();

        return view('dashboard', [
            'profile' => $profile,
            'lastRun' => ScrapeRun::latest('id')->first(),
            'stats' => [
                'scraped' => Listing::active()->count(),
                'matching' => Listing::active()->matching()->count(),
                'queue' => Listing::reviewQueue()->count(),
                'favorites' => Listing::active()->decision(Decision::Favorite)->count(),
                'maybe' => Listing::active()->decision(Decision::Maybe)->count(),
                'rejected' => Listing::decision(Decision::Rejected)->count(),
                'delisted' => Listing::whereNotNull('delisted_at')->count(),
            ],
            'favorites' => Listing::active()
                ->decision(Decision::Favorite)
                ->with('photos')
                ->orderBy('price')
                ->get(),
            'maybes' => Listing::active()
                ->decision(Decision::Maybe)
                ->with('photos')
                ->orderBy('price')
                ->get(),
            'priceDrops' => Listing::active()
                ->whereHas('priceChanges', fn ($q) => $q->whereColumn('new_price', '<', 'old_price'))
                ->with(['priceChanges', 'photos'])
                ->latest('updated_at')
                ->limit(6)
                ->get(),
            // Listings excluded only by square footage or lot size, ordered by how close
            // they came. Useful for spotting a criterion that is slightly too strict.
            'nearMisses' => Listing::active()
                ->where('meets_criteria', false)
                ->whereNotNull('detail_scraped_at')
                ->where('decision', Decision::Undecided->value)
                ->get()
                ->filter(fn (Listing $l) => count($l->criteria_failures ?? []) === 1)
                ->sortBy('price')
                ->take(8)
                ->values(),
        ]);
    }
}
