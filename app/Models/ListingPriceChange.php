<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingPriceChange extends Model
{
    protected $guarded = [];

    protected $casts = [
        'observed_at' => 'datetime',
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function getDeltaAttribute(): ?int
    {
        return $this->old_price ? $this->new_price - $this->old_price : null;
    }
}
