<?php

namespace App\Http\Controllers;

use App\Jobs\RunScrapeJob;
use App\Models\ScrapeRun;
use App\Models\SearchProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class ScrapeRunController extends Controller
{
    public function store(): RedirectResponse
    {
        $profile = SearchProfile::active();

        $running = ScrapeRun::where('status', 'running')
            ->where('started_at', '>', now()->subHour())
            ->exists();

        if ($running) {
            return back()->with('status', 'A scrape is already running.');
        }

        RunScrapeJob::dispatch($profile->id);

        return back()->with('status', 'Scrape queued. Progress appears below as it works.');
    }

    /** Polled by the dashboard so a running scrape shows live progress. */
    public function status(): JsonResponse
    {
        $run = ScrapeRun::latest('id')->first();

        if (! $run) {
            return response()->json(['status' => 'none']);
        }

        return response()->json([
            'id' => $run->id,
            'status' => $run->status,
            'cards_found' => $run->cards_found,
            'listings_created' => $run->listings_created,
            'details_fetched' => $run->details_fetched,
            'price_changes' => $run->price_changes,
            'delisted' => $run->delisted,
            'message' => $run->message,
            'started_at' => $run->started_at?->diffForHumans(),
            'log' => collect($run->log ?? [])->take(-12)->values(),
        ]);
    }
}
