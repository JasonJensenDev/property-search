<?php

namespace App\Jobs;

use App\Models\SearchProfile;
use App\Services\ListingScraper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunScrapeJob implements ShouldQueue
{
    use Queueable;

    /** A full sweep fetches hundreds of pages with a deliberate delay between each. */
    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $searchProfileId) {}

    public function handle(ListingScraper $scraper): void
    {
        $scraper->run(SearchProfile::findOrFail($this->searchProfileId));
    }
}
