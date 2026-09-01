<?php

namespace App\Services\Ure;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to utahrealestate.com's public search the same way their own front-end does.
 *
 * Their search is stateful: criteria live in the PHP session and are pushed there one
 * "changed field" at a time by /search/chained.update. The results endpoint then reads
 * whatever the session currently holds. Posting criteria straight to the results
 * endpoint silently returns an unfiltered firehose, so the order here matters:
 *
 *   1. GET  /search/map.search/type/1        establish a session
 *   2. POST /search/chained.update           push the whole criteria set (all=1)
 *   3. POST /search/map.inline.results/...   page through the matches
 */
class UreClient
{
    /**
     * Geo criteria the site clears whenever a new location is chosen. Sent as
     * param_reset so a stale city or polygon cannot leak into a fresh search.
     */
    private const GEO_FIELDS = [
        'housenum', 'dir_pre', 'street', 'streettype', 'dir_post', 'city', 'county_code',
        'zip', 'area', 'subdivision', 'quadrant', 'unitnbr1', 'unitnbr2', 'geometry',
        'coord_ns1', 'coord_ns2', 'coord_ew1', 'coord_ew2',
    ];

    private CookieJar $cookies;

    private bool $sessionStarted = false;

    private float $lastRequestAt = 0.0;

    public function __construct()
    {
        $this->cookies = new CookieJar;
    }

    private function baseUrl(): string
    {
        return rtrim(config('ure.base_url'), '/');
    }

    private function request(): PendingRequest
    {
        return Http::withOptions([
            'cookies' => $this->cookies,
            'allow_redirects' => true,
        ])
            ->withHeaders([
                'User-Agent' => config('ure.user_agent'),
                'Accept-Language' => 'en-US,en;q=0.9',
                'Referer' => $this->baseUrl().'/search/map.search',
            ])
            ->timeout(config('ure.timeout'))
            ->retry(config('ure.retries'), 1500, throw: false);
    }

    /** Keep a polite gap between calls. */
    private function throttle(): void
    {
        $delay = (int) config('ure.delay_ms');

        if ($delay > 0 && $this->lastRequestAt > 0) {
            $elapsedMs = (microtime(true) - $this->lastRequestAt) * 1000;
            if ($elapsedMs < $delay) {
                usleep((int) (($delay - $elapsedMs) * 1000));
            }
        }

        $this->lastRequestAt = microtime(true);
    }

    public function startSession(): void
    {
        if ($this->sessionStarted) {
            return;
        }

        $this->throttle();

        $response = $this->request()->get($this->baseUrl().'/search/map.search/type/'.config('ure.property_class'));

        if (! $response->successful()) {
            throw new RuntimeException("Could not open utahrealestate.com search (HTTP {$response->status()}).");
        }

        $this->sessionStarted = true;
    }

    /**
     * Resolve a place name to the coordinates and bounding box their autocomplete
     * would have supplied, so the search is anchored the same way.
     *
     * @return array{city: string, latitude: ?float, longitude: ?float, bbox: ?array}|null
     */
    public function resolveCity(string $name): ?array
    {
        $this->throttle();

        $response = $this->request()->get(rtrim(config('ure.api_url'), '/').'/search/location-filter', [
            'geocache' => 1,
            'type' => config('ure.property_class'),
            'query' => $name,
        ]);

        $hits = data_get($response->json(), 'data.geocache.hits.hits', []);

        foreach ($hits as $hit) {
            $source = $hit['_source'] ?? [];
            if (! filled($source['city'] ?? null)) {
                continue;
            }

            return [
                'city' => $source['city'],
                'latitude' => isset($source['latitude']) ? (float) $source['latitude'] : null,
                'longitude' => isset($source['longitude']) ? (float) $source['longitude'] : null,
                'bbox' => $source['bbox'] ?? null,
            ];
        }

        return null;
    }

    /**
     * Push a complete criteria set into the session.
     *
     * @param  array<string, mixed>  $criteria  Raw utahrealestate.com form fields.
     * @return array<string, mixed> The criteria the server says it stored.
     */
    public function applyCriteria(string $city, array $criteria, ?array $geo = null): array
    {
        $this->startSession();
        $this->throttle();

        $form = [
            // The one "changed" field. For a city search their UI synthesises an input
            // named after the location type, and tx=true asks the server to translate
            // the city name into its internal city id.
            'param' => 'city',
            'value' => $city,
            'tx' => 'true',
            'op' => '4',

            // all=1 makes the endpoint accept the entire serialised form rather than
            // just the single changed field.
            'all' => '1',
            'advanced_search' => '0',
            'param_reset' => $this->paramReset(),

            // Mirrors the site's hidden "high level location" inputs.
            'htype' => 'city',
            'hval' => $city,
            'loc' => $city,
            'accr' => '100',

            'type' => config('ure.property_class'),
            'geolocation' => $city,
            'geocoded' => $city,
            'accuracy' => '100',
            'state' => 'UT',
        ];

        if ($geo) {
            $form['lat'] = (string) ($geo['latitude'] ?? '');
            $form['lng'] = (string) ($geo['longitude'] ?? '');
            if ($geo['bbox'] ?? null) {
                $form['box'] = json_encode($geo['bbox']);
            }
        }

        $form = array_merge($form, $criteria);

        $response = $this->request()
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->asForm()
            ->post($this->baseUrl().'/search/chained.update', $form);

        if (! $response->successful()) {
            throw new RuntimeException("Applying search criteria failed (HTTP {$response->status()}).");
        }

        $stored = data_get($response->json(), 'params', []);

        // The server reports the city as its internal numeric id once accepted. If that
        // is missing the location silently did not stick, and results would be statewide.
        if (! isset($stored['city'])) {
            throw new RuntimeException(
                "utahrealestate.com did not accept \"{$city}\" as a city filter. ".
                'Check the spelling, or that the city exists in their search.'
            );
        }

        return $stored;
    }

    private function paramReset(): string
    {
        return implode(',', self::GEO_FIELDS).','.
            implode(',', array_map(fn (string $f) => 'o_'.$f, self::GEO_FIELDS));
    }

    /**
     * Fetch one page of results for the criteria currently in the session.
     *
     * @return array{count: int, html: string, listnos: array<int, string>}
     */
    public function resultsPage(int $page, string $sort = 'list_price_asc'): array
    {
        $this->throttle();

        $url = sprintf(
            '%s/search/map.inline.results/pg/%d/sort/%s/paging/1/dh/1200',
            $this->baseUrl(),
            $page,
            $sort
        );

        $response = $this->request()
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->asForm()
            ->post($url, []);

        if (! $response->successful()) {
            throw new RuntimeException("Fetching results page {$page} failed (HTTP {$response->status()}).");
        }

        $json = $response->json();

        return [
            'count' => (int) ($json['page_count'] ?? 0),
            'html' => (string) ($json['html'] ?? ''),
            'listnos' => collect($json['listing_data'] ?? [])
                ->pluck('listno')
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /** Fetch a listing's public detail page. */
    public function detailPage(string $mlsNumber): string
    {
        $this->throttle();

        $response = $this->request()->get($this->baseUrl().'/'.$mlsNumber);

        if (! $response->successful()) {
            throw new RuntimeException("Fetching listing {$mlsNumber} failed (HTTP {$response->status()}).");
        }

        return $response->body();
    }

    public function listingUrl(string $mlsNumber): string
    {
        return $this->baseUrl().'/'.$mlsNumber;
    }
}
