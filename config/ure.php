<?php

return [
    'base_url' => env('URE_BASE_URL', 'https://www.utahrealestate.com'),

    'api_url' => env('URE_API_URL', 'https://v1backend.utahrealestate.com'),

    'user_agent' => env('URE_USER_AGENT', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '.
        'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'),

    'timeout' => (int) env('URE_TIMEOUT', 30),

    // Pause between requests so a full sweep stays gentle on their servers.
    'delay_ms' => (int) env('URE_DELAY_MS', 1200),

    'retries' => (int) env('URE_RETRIES', 3),

    // Fetching every detail page is the slow part of a run; this caps a single run.
    'max_details_per_run' => (int) env('URE_MAX_DETAILS_PER_RUN', 400),

    // Skip re-fetching a detail page seen within this window unless price/status moved.
    'detail_ttl_hours' => (int) env('URE_DETAIL_TTL_HOURS', 12),

    /*
     * Residential property class on utahrealestate.com.
     */
    'property_class' => '1',

    /*
     * Listing status codes used by their search form.
     *   1  Active
     *   7  Accepting backup offers
     *   13 Coming soon
     *   3  Under contract
     */
    'statuses' => [
        'active' => '1',
        'backup' => '7',
        'coming_soon' => '13',
        'under_contract' => '3',
    ],

    /*
     * Their square footage filter only offers these buckets, which is exactly why
     * this app exists: pick the closest one at or below the real target and then
     * filter precisely in the database.
     */
    'sqft_buckets' => [250, 500, 1000, 1500, 2000, 2500, 3000, 4000, 5000, 7500, 10000],

    /*
     * Likewise for lot size.
     */
    'acre_buckets' => ['.10', '.20', '.25', '.33', '.5', '.75', '1', '1.5', '2', '5', '10'],
];
