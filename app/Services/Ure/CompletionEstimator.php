<?php

namespace App\Services\Ure;

use Carbon\CarbonImmutable;

/**
 * Works out whether a listing is finished, and if not, when it is likely to be.
 *
 * utahrealestate.com states this directly with a badge on the photo ("To Be Built" or
 * "Under Construction", absent once finished), which is the signal trusted here. The
 * completion date itself is only ever in free text ("this one will be complete by the
 * end of March", "ready in 60 days"), so that is read from the description as a best
 * guess and shown as such in the UI.
 *
 * The point is to stop houses that cannot be moved into in time from crowding out the
 * ones that can.
 */
class CompletionEstimator
{
    private const MONTHS = [
        'january' => 1, 'jan' => 1, 'february' => 2, 'feb' => 2, 'march' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4, 'may' => 5, 'june' => 6, 'jun' => 6, 'july' => 7, 'jul' => 7,
        'august' => 8, 'aug' => 8, 'september' => 9, 'sep' => 9, 'sept' => 9,
        'october' => 10, 'oct' => 10, 'november' => 11, 'nov' => 11, 'december' => 12, 'dec' => 12,
    ];

    /** Nothing has been built yet, so no completion date can be assumed. */
    public const TO_BE_BUILT = 'To Be Built';

    /**
     * Phrases that mean the home is not finished. These win over any "move-in ready"
     * marketing language, because a "to be built" listing frequently also boasts about
     * how ready it will be.
     */
    private const UNBUILT_PHRASES = [
        'to be built', 'to-be-built', 'proposed construction', 'under construction',
        'pre-construction', 'preconstruction', 'currently being built', 'now building',
        'will be complete', 'will complete', 'estimated completion', 'projected completion',
        'completion date', 'under const', 'framing stage', 'foundation stage', 'permit stage',
    ];

    /**
     * @param  string|null  $constructionStatus  The badge from the listing page, if any.
     * @return array{is_new_construction: bool, completion_estimate: ?CarbonImmutable, completion_note: ?string}
     */
    public function estimate(
        ?string $description,
        ?int $yearBuilt,
        ?string $constructionStatus = null,
        ?CarbonImmutable $now = null,
    ): array {
        $now ??= CarbonImmutable::now();
        $original = (string) $description;
        $text = strtolower($original);

        $badge = trim((string) $constructionStatus);
        $badgeSaysUnbuilt = $badge !== '';

        $mentionsUnbuilt = false;
        foreach (self::UNBUILT_PHRASES as $phrase) {
            if (str_contains($text, $phrase)) {
                $mentionsUnbuilt = true;
                break;
            }
        }

        // A year built in the future means the home cannot be standing yet.
        $futureYear = $yearBuilt !== null && $yearBuilt > $now->year;

        if (! $badgeSaysUnbuilt && ! $mentionsUnbuilt && ! $futureYear) {
            return ['is_new_construction' => false, 'completion_estimate' => null, 'completion_note' => null];
        }

        // "To Be Built" means ground has not broken. Treat the date as unknown rather
        // than inventing one, so it fails any move-in deadline instead of passing on a
        // guess.
        if (strcasecmp($badge, self::TO_BE_BUILT) === 0) {
            return [
                'is_new_construction' => true,
                'completion_estimate' => null,
                'completion_note' => 'Listed as "To Be Built" - construction has not started.',
            ];
        }

        [$date, $note] = $this->findDate($text, $original, $now, $yearBuilt);

        if ($note === null && $badge !== '') {
            $note = "Listed as \"{$badge}\" with no completion date given.";
        }

        return [
            'is_new_construction' => true,
            'completion_estimate' => $date,
            'completion_note' => $note,
        ];
    }

    /** @return array{0: ?CarbonImmutable, 1: ?string} */
    private function findDate(string $text, string $original, CarbonImmutable $now, ?int $yearBuilt): array
    {
        $monthNames = implode('|', array_keys(self::MONTHS));

        // "complete by the end of March", "completion March 2027", "ready in October"
        $pattern = '/(?:complet\w*|finish\w*|ready|delivery|deliver|possession|move[- ]?in)'
            .'[^.!?]{0,40}?(?:by\s+)?(?:the\s+)?(?:end\s+of\s+|beginning\s+of\s+|mid[- ])?'
            .'('.$monthNames.')\.?\s*(?:of\s*)?(\d{4})?/i';

        if (preg_match($pattern, $text, $m)) {
            $month = self::MONTHS[strtolower($m[1])];
            $year = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : $this->inferYear($month, $now);
            $date = CarbonImmutable::create($year, $month, 1)->endOfMonth();

            return [$date, $this->snippet($original, $m[0])];
        }

        // "ready in 60 days", "complete in 3 months", "6-8 weeks out"
        if (preg_match(
            '/(?:complet\w*|finish\w*|ready|delivery|possession|move[- ]?in)[^.!?]{0,30}?'
            .'(\d{1,3})\s*(?:-\s*\d{1,3}\s*)?(day|week|month)s?/i',
            $text,
            $m
        )) {
            $amount = (int) $m[1];
            $date = match (strtolower($m[2])) {
                'day' => $now->addDays($amount),
                'week' => $now->addWeeks($amount),
                'month' => $now->addMonths($amount),
            };

            return [$date, $this->snippet($original, $m[0])];
        }

        // Nothing quotable in the text: fall back to the end of the stated build year.
        if ($yearBuilt !== null && $yearBuilt > $now->year) {
            return [
                CarbonImmutable::create($yearBuilt, 12, 31),
                "Listed as built in {$yearBuilt}; no completion date given.",
            ];
        }

        return [null, null];
    }

    /** A bare month with no year means the next time that month comes around. */
    private function inferYear(int $month, CarbonImmutable $now): int
    {
        return $month >= $now->month ? $now->year : $now->year + 1;
    }

    /** Pull the surrounding sentence so the UI can show why this date was inferred. */
    private function snippet(string $text, string $match): string
    {
        $position = mb_stripos($text, $match);

        if ($position === false) {
            return ucfirst(preg_replace('/\s+/', ' ', $match));
        }

        $start = max(0, $position - 90);
        $snippet = trim(mb_substr($text, $start, mb_strlen($match) + 180));

        return ucfirst(preg_replace('/\s+/', ' ', $snippet));
    }
}
