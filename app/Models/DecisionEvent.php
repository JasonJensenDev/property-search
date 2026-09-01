<?php

namespace App\Models;

use App\Enums\Decision;
use App\Enums\RejectionReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DecisionEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'from_decision' => Decision::class,
        'to_decision' => Decision::class,
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function getReasonLabelAttribute(): ?string
    {
        return RejectionReason::tryFrom((string) $this->reason_code)?->label();
    }
}
