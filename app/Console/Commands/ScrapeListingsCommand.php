<?php

namespace App\Console\Commands;

use App\Models\SearchProfile;
use App\Services\ListingScraper;
use Illuminate\Console\Command;

class ScrapeListingsCommand extends Command
{
    protected $signature = 'listings:scrape
        {--profile= : Search profile id or name (defaults to the active profile)}
        {--fresh-details : Re-fetch every listing page instead of only stale ones}';

    protected $description = 'Scrape utahrealestate.com for listings matching a search profile';

    public function handle(ListingScraper $scraper): int
    {
        $profile = $this->resolveProfile();

        if (! $profile) {
            $this->components->error('No search profile found. Run "php artisan db:seed" first.');

            return self::FAILURE;
        }

        $this->components->info("Scraping profile \"{$profile->name}\" (".implode(', ', $profile->cities).').');

        $run = $scraper
            ->forceDetails($this->option('fresh-details'))
            ->onProgress(fn (string $line) => $this->line("  <fg=gray>{$line}</>"))
            ->run($profile);

        $this->newLine();
        $this->components->twoColumnDetail('Listings seen', (string) $run->cards_found);
        $this->components->twoColumnDetail('New', (string) $run->listings_created);
        $this->components->twoColumnDetail('Updated', (string) $run->listings_updated);
        $this->components->twoColumnDetail('Details fetched', (string) $run->details_fetched);
        $this->components->twoColumnDetail('Price changes', (string) $run->price_changes);
        $this->components->twoColumnDetail('No longer listed', (string) $run->delisted);

        $matching = $profile->listings()->whereNull('delisted_at')->where('meets_criteria', true)->count();
        $this->newLine();
        $this->components->info("{$matching} listing(s) meet your exact criteria.");

        return self::SUCCESS;
    }

    private function resolveProfile(): ?SearchProfile
    {
        $key = $this->option('profile');

        if (! $key) {
            return SearchProfile::where('is_active', true)->orderBy('id')->first()
                ?? SearchProfile::orderBy('id')->first();
        }

        return SearchProfile::query()
            ->when(is_numeric($key), fn ($q) => $q->where('id', $key), fn ($q) => $q->where('name', $key))
            ->first();
    }
}
