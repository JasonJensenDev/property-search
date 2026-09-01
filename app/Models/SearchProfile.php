<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SearchProfile extends Model
{
    protected $guarded = [];

    protected $casts = [
        'cities' => 'array',
        'remote_statuses' => 'array',
        'is_active' => 'boolean',
        'exclude_hoa' => 'boolean',
        'require_move_in_ready' => 'boolean',
        'min_acres' => 'float',
        'max_acres' => 'float',
        'min_baths' => 'float',
        'ready_by' => 'date',
    ];

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function scrapeRuns(): HasMany
    {
        return $this->hasMany(ScrapeRun::class)->latest();
    }

    public static function active(): self
    {
        return static::where('is_active', true)->orderBy('id')->firstOrFail();
    }
}
