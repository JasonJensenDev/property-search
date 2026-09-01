<?php

namespace App\Models;

use App\Enums\Decision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Listing extends Model
{
    protected $guarded = [];

    protected $casts = [
        'decision' => Decision::class,
        'sqft_levels' => 'array',
        'interior_features' => 'array',
        'exterior_features' => 'array',
        'other_features' => 'array',
        'schools' => 'array',
        'raw' => 'array',
        'criteria_failures' => 'array',
        'meets_criteria' => 'boolean',
        'is_new_construction' => 'boolean',
        'acres' => 'float',
        'baths' => 'float',
        'latitude' => 'float',
        'longitude' => 'float',
        'completion_estimate' => 'date',
        'decided_at' => 'datetime',
        'detail_scraped_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'delisted_at' => 'datetime',
    ];

    public function photos(): HasMany
    {
        return $this->hasMany(ListingPhoto::class)->orderBy('position');
    }

    public function priceChanges(): HasMany
    {
        return $this->hasMany(ListingPriceChange::class)->latest('observed_at');
    }

    public function decisionEvents(): HasMany
    {
        return $this->hasMany(DecisionEvent::class)->latest();
    }

    public function searchProfile(): BelongsTo
    {
        return $this->belongsTo(SearchProfile::class);
    }

    /* ---------------------------------------------------------------- scopes */

    public function scopeDecision(Builder $query, Decision $decision): Builder
    {
        return $query->where('decision', $decision->value);
    }

    public function scopeUndecided(Builder $query): Builder
    {
        return $query->where('decision', Decision::Undecided->value);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('delisted_at');
    }

    public function scopeMatching(Builder $query): Builder
    {
        return $query->where('meets_criteria', true);
    }

    /**
     * The review queue: everything still in play that has not been judged yet.
     * Cheapest first so the best value shows up early.
     */
    public function scopeReviewQueue(Builder $query): Builder
    {
        return $query->active()->matching()->undecided()->orderBy('price');
    }

    /* ------------------------------------------------------------ attributes */

    public function getFullAddressAttribute(): string
    {
        $line = trim(($this->street_address ?? '').' '.($this->unit ? '#'.ltrim($this->unit, '#') : ''));

        return trim($line ?: 'Address unavailable');
    }

    public function getCityLineAttribute(): string
    {
        return trim(collect([$this->city, $this->state])->filter()->implode(', ').' '.$this->postal_code);
    }

    public function getHasCoordinatesAttribute(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Google Maps link for the property. Coordinates give an exact pin, so they are
     * preferred over an address search, which can land on the wrong side of a street.
     */
    public function getMapUrlAttribute(): string
    {
        if ($this->has_coordinates) {
            return 'https://www.google.com/maps/search/?api=1&query='.$this->latitude.','.$this->longitude;
        }

        return 'https://www.google.com/maps/search/?api=1&query='
            .urlencode(trim($this->full_address.', '.$this->city_line));
    }

    public function getPricePerSqftAttribute(): ?float
    {
        if (! $this->price || ! $this->total_sqft) {
            return null;
        }

        return round($this->price / $this->total_sqft, 2);
    }

    public function getLotSqftAttribute(): ?int
    {
        return $this->acres ? (int) round($this->acres * 43560) : null;
    }

    public function getHasHoaAttribute(): bool
    {
        return $this->hoa_monthly > 0 || filled($this->hoa_details);
    }

    /**
     * Under-contract listings still show up in search results, so surface them
     * separately rather than treating them as freely available.
     */
    public function getIsUnderContractAttribute(): bool
    {
        return str_contains(strtoupper((string) $this->status), 'UNDER CONTRACT');
    }

    public function getIsAcceptingBackupsAttribute(): bool
    {
        return str_contains(strtoupper((string) $this->status), 'BACKUP');
    }

    public function getBasementSqftAttribute(): ?int
    {
        foreach ($this->sqft_levels ?? [] as $label => $sqft) {
            if (str_contains(strtolower((string) $label), 'basement')) {
                return (int) $sqft;
            }
        }

        return null;
    }

    public function getLatestPriceDropAttribute(): ?ListingPriceChange
    {
        return $this->priceChanges->first(fn (ListingPriceChange $c) => $c->old_price && $c->new_price < $c->old_price);
    }
}
