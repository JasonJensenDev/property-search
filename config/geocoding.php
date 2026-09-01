<?php

return [
    /*
     * Nominatim asks that clients identify themselves and stay under one request a
     * second. Both are courtesy requirements for a free service; please leave them alone.
     */
    'user_agent' => env('GEOCODING_USER_AGENT', 'PropertySearch/1.0 (personal house-hunting tool)'),

    'delay_ms' => (int) env('GEOCODING_DELAY_MS', 1100),

    'timeout' => (int) env('GEOCODING_TIMEOUT', 15),
];
