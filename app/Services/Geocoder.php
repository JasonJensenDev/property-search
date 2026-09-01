<?php

namespace App\Services;

use App\Models\Listing;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a listing's street address to coordinates so its location can be mapped.
 *
 * utahrealestate.com does not publish coordinates in its listing pages, so addresses go
 * to Nominatim (OpenStreetMap). Its usage policy asks for an identifying user agent and
 * no more than one request a second, both of which are honoured here.
 */
class Geocoder
{
    private ?float $lastRequestAt = null;

    /**
     * Set once the free service starts refusing requests. A refusal is not the same as an
     * address it does not know, and treating the two alike would report every remaining
     * listing as "not found".
     */
    private bool $rateLimited = false;

    public function __construct(
        private readonly string $endpoint = 'https://nominatim.openstreetmap.org/search',
    ) {}

    public function wasRateLimited(): bool
    {
        return $this->rateLimited;
    }

    /**
     * Look up and store coordinates, returning true when the listing has them afterwards.
     * Already-located listings are left alone unless $force is set.
     */
    public function locate(Listing $listing, bool $force = false): bool
    {
        if (! $force && $listing->latitude !== null && $listing->longitude !== null) {
            return true;
        }

        // Houses in new subdivisions frequently are not mapped individually yet, so fall
        // back to the street, which still answers "whereabouts is this?".
        foreach ($this->queries($listing) as [$precision, $query]) {
            $match = $this->lookup($query, $listing);

            if ($match === null) {
                continue;
            }

            $listing->update([
                'latitude' => (float) $match['lat'],
                'longitude' => (float) $match['lon'],
                'location_precision' => $precision,
            ]);

            return true;
        }

        return false;
    }

    /** @return array{lat: string, lon: string}|null */
    private function lookup(string $query, Listing $listing): ?array
    {
        $response = $this->request($query);

        // Getting ahead of the rate limit returns 429. Backing off and trying once more is
        // the difference between a located house and a wrong "no match".
        if ($response->status() === 429) {
            usleep($this->delayMicroseconds() * 3);
            $response = $this->request($query);
        }

        if ($response->status() === 429) {
            $this->rateLimited = true;
        }

        if ($response->failed()) {
            Log::warning('Geocoding failed', ['listing' => $listing->mls_number, 'status' => $response->status()]);

            return null;
        }

        $match = $response->json('0');

        return isset($match['lat'], $match['lon']) ? $match : null;
    }

    private function request(string $query): Response
    {
        $this->waitForRateLimit();

        return Http::withHeaders(['User-Agent' => config('geocoding.user_agent')])
            ->timeout(config('geocoding.timeout'))
            ->get($this->endpoint, [
                'q' => $query,
                'format' => 'jsonv2',
                'limit' => 1,
                'countrycodes' => 'us',
            ]);
    }

    /**
     * Nominatim allows one request a second. Throttling here rather than in the calling
     * loop keeps it honest even when a single listing needs several attempts.
     */
    private function waitForRateLimit(): void
    {
        $delay = $this->delayMicroseconds();

        if ($this->lastRequestAt !== null && $delay > 0) {
            $elapsed = (int) ((microtime(true) - $this->lastRequestAt) * 1_000_000);

            if ($elapsed < $delay) {
                usleep($delay - $elapsed);
            }
        }

        $this->lastRequestAt = microtime(true);
    }

    private function delayMicroseconds(): int
    {
        return (int) config('geocoding.delay_ms') * 1000;
    }

    /**
     * Locate a batch of listings, stopping early if the service starts refusing requests
     * so the rest can be retried later instead of being written off. Returns how many
     * gained coordinates.
     *
     * @param  iterable<Listing>  $listings
     */
    public function locateMany(iterable $listings, ?callable $onEach = null): int
    {
        $located = 0;

        foreach ($listings as $listing) {
            $success = $this->locate($listing);
            $located += $success ? 1 : 0;

            if ($onEach) {
                $onEach($listing, $success);
            }

            if ($this->rateLimited) {
                break;
            }
        }

        return $located;
    }

    /**
     * Street types that the MLS feed sometimes cuts off mid-word, e.g. "Pear Stre" or
     * "Dutton Cour", which no geocoder will recognise as written.
     */
    private const STREET_TYPES = [
        'Street', 'Court', 'Drive', 'Lane', 'Circle', 'Road', 'Avenue', 'Boulevard',
        'Place', 'Terrace', 'Trail', 'Parkway', 'Crossing', 'Heights',
    ];

    /**
     * Queries to try, best precision first, each paired with the precision it would give.
     *
     * A house number alone is ambiguous, so the city is always included. The unit is left
     * out on purpose: Nominatim rarely knows individual units and including one tends to
     * turn an exact match into no match at all.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function queries(Listing $listing): array
    {
        if (blank($listing->street_address) || blank($listing->city)) {
            return [];
        }

        $place = collect([$listing->city, $listing->state, $listing->postal_code])
            ->filter()
            ->implode(', ');

        $queries = [['exact', $listing->street_address.', '.$place]];

        $street = trim(preg_replace('/^\s*\d+\s*/', '', $listing->street_address));

        if ($street === '' || $street === $listing->street_address) {
            return $queries;
        }

        $queries[] = ['street', $street.', '.$place];

        $repaired = $this->expandTruncatedStreetType($street);

        if ($repaired !== null) {
            $queries[] = ['street', $repaired.', '.$place];
        }

        return $queries;
    }

    /**
     * Complete a street name whose type was cut short, so "Pear Stre" can be looked up as
     * "Pear Street". Returns null when nothing looks truncated.
     */
    private function expandTruncatedStreetType(string $street): ?string
    {
        $words = preg_split('/\s+/', $street);
        $last = array_pop($words);

        if ($words === [] || $last === null || strlen($last) < 3) {
            return null;
        }

        foreach (self::STREET_TYPES as $type) {
            // Only a genuine truncation, not a complete word and not a standard
            // abbreviation like "Ct" or "Dr", which geocoders already understand.
            if (strlen($last) < strlen($type) && strcasecmp($last, substr($type, 0, strlen($last))) === 0) {
                return implode(' ', [...$words, $type]);
            }
        }

        return null;
    }
}
