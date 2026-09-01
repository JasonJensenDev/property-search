<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapeRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'log' => 'array',
    ];

    public function searchProfile(): BelongsTo
    {
        return $this->belongsTo(SearchProfile::class);
    }

    public function getDurationAttribute(): ?int
    {
        return $this->started_at && $this->finished_at
            ? $this->started_at->diffInSeconds($this->finished_at)
            : null;
    }

    public function appendLog(string $line): void
    {
        $log = $this->log ?? [];
        $log[] = ['at' => now()->toDateTimeString(), 'line' => $line];
        $this->update(['log' => $log]);
    }
}
