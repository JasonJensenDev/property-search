<?php

namespace App\Services;

use App\Enums\Decision;
use App\Models\Listing;

/**
 * Records keep/cross-off decisions and keeps an audit trail, so a listing that was
 * ruled out months ago can still explain itself and be brought back if needed.
 */
class DecisionRecorder
{
    public function record(
        Listing $listing,
        Decision $decision,
        ?string $reasonCode = null,
        ?string $reason = null,
    ): Listing {
        $previous = $listing->decision;

        $listing->update([
            'decision' => $decision,
            'decision_reason_code' => $decision === Decision::Undecided ? null : $reasonCode,
            'decision_reason' => $decision === Decision::Undecided ? null : $reason,
            'decided_at' => $decision === Decision::Undecided ? null : now(),
        ]);

        $listing->decisionEvents()->create([
            'from_decision' => $previous?->value,
            'to_decision' => $decision->value,
            'reason_code' => $reasonCode,
            'reason' => $reason,
        ]);

        return $listing->refresh();
    }

    /** Put a listing back into the review queue. */
    public function reset(Listing $listing): Listing
    {
        return $this->record($listing, Decision::Undecided);
    }

    /** Step back to whatever the listing was before the most recent change. */
    public function undoLast(Listing $listing): ?Listing
    {
        $last = $listing->decisionEvents()->first();

        if (! $last) {
            return null;
        }

        $target = $last->from_decision ?? Decision::Undecided;

        // Find the reason that was in force before this change, if any.
        $previousEvent = $listing->decisionEvents()
            ->where('id', '<', $last->id)
            ->where('to_decision', $target->value)
            ->first();

        $listing->update([
            'decision' => $target,
            'decision_reason_code' => $previousEvent?->reason_code,
            'decision_reason' => $previousEvent?->reason,
            'decided_at' => $target === Decision::Undecided ? null : ($previousEvent?->created_at ?? now()),
        ]);

        $last->delete();

        return $listing->refresh();
    }
}
