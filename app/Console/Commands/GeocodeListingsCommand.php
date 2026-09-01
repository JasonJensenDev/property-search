<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Services\Geocoder;
use Illuminate\Console\Command;

class GeocodeListingsCommand extends Command
{
    protected $signature = 'listings:geocode
        {--all : Re-locate every listing, including ones that already have coordinates}';

    protected $description = 'Resolve listing addresses to coordinates so they can be mapped';

    public function handle(Geocoder $geocoder): int
    {
        $listings = Listing::query()
            ->when(! $this->option('all'), fn ($query) => $query->whereNull('latitude'))
            ->whereNotNull('street_address')
            ->orderBy('id')
            ->get();

        if ($listings->isEmpty()) {
            $this->components->info('Every listing already has coordinates.');

            return self::SUCCESS;
        }

        $this->components->info("Locating {$listings->count()} listing(s), roughly one a second.");

        $attempted = 0;

        $located = $geocoder->locateMany($listings, function (Listing $listing, bool $success) use (&$attempted) {
            $attempted++;

            $this->line($success
                ? "  <fg=green>✓</> {$listing->full_address} — {$listing->latitude}, {$listing->longitude}"
                : "  <fg=yellow>?</> {$listing->full_address} — no match");
        });

        $this->newLine();
        $this->components->twoColumnDetail('Located', (string) $located);
        $this->components->twoColumnDetail('Not found', (string) ($attempted - $located));

        if ($geocoder->wasRateLimited()) {
            $this->newLine();
            $this->components->warn(
                'OpenStreetMap started refusing requests, so this stopped early. '
                .($listings->count() - $attempted).' listing(s) were not attempted. '
                .'Wait a while and run this again; nothing was lost.'
            );
        }

        return self::SUCCESS;
    }
}
