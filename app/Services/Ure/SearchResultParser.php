<?php

namespace App\Services\Ure;

/**
 * Pulls the summary card data out of a results page. This is only used to discover
 * listings and spot price or status movement cheaply; the authoritative numbers come
 * from ListingDetailParser.
 */
class SearchResultParser
{
    /** @return array<int, array<string, mixed>> */
    public function parse(string $html): array
    {
        $cards = [];

        // Each card starts with <li id="mls-inline-{listno}">.
        $chunks = preg_split('/<li id="mls-inline-/', $html);
        array_shift($chunks);

        foreach ($chunks as $chunk) {
            if (! preg_match('/^(\d+)/', $chunk, $m)) {
                continue;
            }

            $card = ['mls_number' => $m[1]];

            $card['price'] = $this->money($this->match('/class="list___price">\s*\$?([\d,]+)/', $chunk));
            $card['status'] = $this->clean($this->match('/class="status">\s*<i[^>]*><\/i>\s*([A-Z][A-Z\s]*)/', $chunk));
            $card['days_on_ure'] = $this->int($this->match('/Days on URE:\s*([\d,]+)/', $chunk));

            if (preg_match('/(\d+)\s*bds\s*&bull;\s*([\d.]+)\s*ba\s*&bull;\s*([\d,]+)\s*SqFt/i', $chunk, $m2)) {
                $card['beds'] = (int) $m2[1];
                $card['baths'] = (float) $m2[2];
                $card['total_sqft'] = $this->int($m2[3]);
            }

            $address = $this->clean($this->match(
                '/class="listing___address truncate">\s*<span>\s*(.*?)<\/span>/s',
                $chunk
            ));

            // Rendered as "1553 E 1400 N #130 Payson, UT" on one line.
            if ($address && preg_match('/^(.*?)\s*,\s*([A-Z]{2})$/', $address, $m3)) {
                $card['address_line'] = trim($m3[1]);
                $card['state'] = $m3[2];
            } else {
                $card['address_line'] = $address;
            }

            $card['photo_url'] = $this->match('/<img src="(https:\/\/assets\.utahrealestate\.com[^"]+)"/', $chunk);
            if ($card['photo_url'] && str_contains($card['photo_url'], 'nophoto')) {
                $card['photo_url'] = null;
            }

            $cards[] = $card;
        }

        return $cards;
    }

    private function match(string $pattern, string $subject): ?string
    {
        return preg_match($pattern, $subject, $m) ? $m[1] : null;
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/', ' ', $value)) ?: null;
    }

    private function int(?string $value): ?int
    {
        return $value === null ? null : (int) str_replace(',', '', $value);
    }

    private function money(?string $value): ?int
    {
        $int = $this->int($value);

        return $int ?: null;
    }
}
