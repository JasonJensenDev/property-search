<?php

namespace App\Http\Controllers;

use App\Enums\Decision;
use App\Enums\RejectionReason;
use App\Models\Listing;
use App\Models\SearchProfile;
use App\Services\DecisionRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The one-at-a-time review flow: show a single listing in full detail, then keep it or
 * cross it off with a reason.
 */
class ReviewController extends Controller
{
    public function __construct(private DecisionRecorder $recorder) {}

    /** Show the next listing awaiting a decision. */
    public function index(): View|RedirectResponse
    {
        $listing = Listing::reviewQueue()->first();

        if (! $listing) {
            return view('review.empty', [
                'profile' => SearchProfile::query()->where('is_active', true)->first(),
                'counts' => $this->counts(),
            ]);
        }

        return redirect()->route('review.show', $listing);
    }

    public function show(Listing $listing): View
    {
        $listing->load(['photos', 'priceChanges', 'decisionEvents']);

        $queue = Listing::reviewQueue()->pluck('id')->all();
        $position = array_search($listing->id, $queue, true);

        return view('review.show', [
            'listing' => $listing,
            'reasons' => RejectionReason::options(),
            'counts' => $this->counts(),
            'position' => $position === false ? null : $position + 1,
            'queueTotal' => count($queue),
            'nextListing' => $this->neighbour($queue, $listing->id, 1),
            'previousListing' => $this->neighbour($queue, $listing->id, -1),
        ]);
    }

    public function decide(Request $request, Listing $listing): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:'.implode(',', array_column(Decision::cases(), 'value'))],
            'reason_code' => ['nullable', 'string', 'in:'.implode(',', array_column(RejectionReason::cases(), 'value'))],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $decision = Decision::from($validated['decision']);

        // Crossing a listing off without saying why defeats the purpose, so require it.
        if ($decision === Decision::Rejected && blank($validated['reason_code'] ?? null) && blank($validated['reason'] ?? null)) {
            return back()->withErrors(['reason_code' => 'Give a reason so you remember why this one is out.']);
        }

        $next = $this->nextAfter($listing);

        $this->recorder->record(
            $listing,
            $decision,
            $validated['reason_code'] ?? null,
            filled($validated['reason'] ?? null) ? trim($validated['reason']) : null,
        );

        if ($request->filled('stay')) {
            return back()->with('status', 'Saved.');
        }

        return $next
            ? redirect()->route('review.show', $next)->with('status', $this->confirmation($listing, $decision))
            : redirect()->route('review.index')->with('status', $this->confirmation($listing, $decision));
    }

    public function notes(Request $request, Listing $listing): RedirectResponse
    {
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:5000']]);

        $listing->update(['notes' => $validated['notes'] ?: null]);

        return back()->with('status', 'Notes saved.');
    }

    public function undo(Listing $listing): RedirectResponse
    {
        $restored = $this->recorder->undoLast($listing);

        return redirect()
            ->route('review.show', $listing)
            ->with('status', $restored ? 'Reverted to '.$restored->decision->label().'.' : 'Nothing to undo.');
    }

    private function confirmation(Listing $listing, Decision $decision): string
    {
        $address = $listing->street_address ?: $listing->mls_number;

        return match ($decision) {
            Decision::Favorite => "Kept {$address}.",
            Decision::Rejected => "Crossed off {$address}.",
            Decision::Maybe => "Flagged {$address} as a maybe.",
            Decision::Undecided => "Put {$address} back in the queue.",
        };
    }

    /** The listing that will be next once the current one leaves the queue. */
    private function nextAfter(Listing $listing): ?Listing
    {
        return Listing::reviewQueue()->where('id', '!=', $listing->id)->first();
    }

    /** @param array<int, int> $queue */
    private function neighbour(array $queue, int $currentId, int $offset): ?Listing
    {
        $index = array_search($currentId, $queue, true);

        if ($index === false) {
            return null;
        }

        $targetId = $queue[$index + $offset] ?? null;

        return $targetId ? Listing::find($targetId) : null;
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'queue' => Listing::reviewQueue()->count(),
            'favorites' => Listing::active()->decision(Decision::Favorite)->count(),
            'maybe' => Listing::active()->decision(Decision::Maybe)->count(),
            'rejected' => Listing::decision(Decision::Rejected)->count(),
            'total' => Listing::active()->count(),
            'matching' => Listing::active()->matching()->count(),
        ];
    }
}
