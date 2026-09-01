<?php

namespace App\Services\Ure;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Turns a utahrealestate.com listing page into the exact numbers this app filters on.
 *
 * Their public search rounds square footage into 500/1000-step buckets and offers no
 * "no HOA" toggle at all, but the detail page states both precisely, so everything
 * that matters is read from here.
 */
class ListingDetailParser
{
    public function __construct(private CompletionEstimator $completion) {}

    /** @return array<string, mixed> */
    public function parse(string $html, string $mlsNumber): array
    {
        $crawler = new Crawler($html);

        $facts = $this->facts($crawler);
        $overview = $this->overview($crawler);
        $sizes = $this->propertySize($html);
        $interior = $this->featureList($crawler, '#int_feats');
        $exterior = $this->featureList($crawler, '#ext_feats');
        $other = $this->featureList($crawler, '#other_feats');
        $widget = $this->widgetConfig($html);
        $description = $this->description($crawler);

        $yearBuilt = $this->int($facts['Year Built'] ?? null);
        $constructionStatus = $this->text($crawler->filter('.conststatus___wrap span')->first());

        $completion = $this->completion->estimate($description, $yearBuilt, $constructionStatus);

        $data = [
            'mls_number' => $facts['MLS#'] ?? $mlsNumber,

            'street_address' => $overview['street_address'] ?? ($widget['address'] ?? null),
            'unit' => $overview['unit'] ?? null,
            'city' => $overview['city'] ?? ($widget['city'] ?? null),
            'state' => $overview['state'] ?? ($widget['state'] ?? null),
            'postal_code' => $overview['postal_code'] ?? ($widget['zip'] ?? null),

            'price' => $overview['price'] ?? $this->int($widget['price'] ?? null),
            'beds' => $overview['beds'] ?? null,
            'baths' => $overview['baths'] ?? null,
            'total_sqft' => $sizes['total'] ?? $overview['total_sqft'] ?? null,
            'sqft_levels' => $sizes['levels'] ?: null,
            'acres' => $this->acres($html),

            // The mortgage widget carries the monthly HOA dues as a plain number, and 0
            // there is a reliable "no HOA" signal that their search cannot filter on.
            'hoa_monthly' => $this->int($widget['hoa'] ?? null) ?? 0,
            'hoa_details' => $this->hoaDetails($crawler),
            'property_tax_annual' => $this->int($widget['propTax'] ?? null),

            'property_type' => $facts['Type'] ?? null,
            'style' => $facts['Style'] ?? null,
            'construction_status' => $constructionStatus,
            'year_built' => $yearBuilt,
            'status' => $facts['Status'] ?? null,
            'days_on_ure' => $this->int($facts['Days on URE'] ?? null),

            'garage_capacity' => $this->garageCapacity($exterior),
            'basement_finished_pct' => $this->basementPct($interior),

            'is_new_construction' => $completion['is_new_construction'],
            'completion_estimate' => $completion['completion_estimate'],
            'completion_note' => $completion['completion_note'],

            'description' => $description,
            'interior_features' => $interior ?: null,
            'exterior_features' => $exterior ?: null,
            'other_features' => $other ?: null,
            'schools' => $this->schools($crawler) ?: null,

            'agent_name' => $this->agentName($crawler),
            'broker_name' => $this->brokerName($crawler),
            'agent_phone' => $this->phone($html),

            'photos' => $this->photos($crawler, $mlsNumber),
        ];

        $data['raw'] = [
            'facts' => $facts,
            'sizes' => $sizes,
            'widget' => $widget,
        ];

        return $data;
    }

    /**
     * The "Property Facts" strip: Days on URE, Status, MLS#, Type, Style, Year Built.
     *
     * @return array<string, string>
     */
    private function facts(Crawler $crawler): array
    {
        $facts = [];

        $crawler->filter('.facts___item')->each(function (Crawler $node) use (&$facts) {
            $label = $this->text($node->filter('.facts-header'));
            if (! $label) {
                return;
            }

            // The value is the item's text with the label removed.
            $full = $this->text($node);
            $value = trim(str_replace($label, '', (string) $full));

            if ($value !== '') {
                $facts[$label] = $value;
            }
        });

        return $facts;
    }

    /**
     * Headline block: address, city line, price, beds, baths, square feet.
     *
     * @return array<string, mixed>
     */
    private function overview(Crawler $crawler): array
    {
        $out = [];

        $heading = $this->text($crawler->filter('.prop___overview h2')->first());
        if ($heading) {
            // "125 S Freedom Way  #216"
            if (preg_match('/^(.*?)\s+#\s*([\w-]+)$/', $heading, $m)) {
                $out['street_address'] = trim($m[1]);
                $out['unit'] = $m[2];
            } else {
                $out['street_address'] = $heading;
            }
        }

        $location = $this->text($crawler->filter('#location-data')->first());
        if ($location && preg_match('/^(.*?),\s*([A-Z]{2})\s*([\d-]+)?$/', $location, $m)) {
            $out['city'] = trim($m[1]);
            $out['state'] = $m[2];
            $out['postal_code'] = $m[3] ?? null;
        }

        $crawler->filter('ul.prop-details-overview li')->each(function (Crawler $node) use (&$out) {
            $text = (string) $this->text($node);
            $value = $this->text($node->filter('span')->first());

            if ($value !== null && str_starts_with(trim($value), '$')) {
                $out['price'] = $this->int($value);
            } elseif (str_contains($text, 'Beds')) {
                $out['beds'] = $this->int($value);
            } elseif (str_contains($text, 'Baths')) {
                $out['baths'] = $value === null ? null : (float) $value;
            } elseif (str_contains($text, 'Sq. Ft.')) {
                $out['total_sqft'] = $this->int($value);
            }
        });

        return $out;
    }

    /**
     * The per-level breakdown, e.g. Floor 1 / Basement 1 / Total.
     *
     * @return array{levels: array<string, int>, total: ?int}
     */
    private function propertySize(string $html): array
    {
        $levels = [];
        $total = null;

        if (preg_match('/<ul id="prop_size">(.*?)<\/ul>/s', $html, $block)) {
            preg_match_all('/<li>\s*([^<:]+?):\s*([\d,]+)\s*sq\.\s*ft\.\s*<\/li>/i', $block[1], $matches, PREG_SET_ORDER);

            foreach ($matches as $m) {
                $label = trim($m[1]);
                $value = (int) str_replace(',', '', $m[2]);

                if (strcasecmp($label, 'Total') === 0) {
                    $total = $value;
                } elseif ($value > 0) {
                    $levels[$label] = $value;
                }
            }
        }

        return ['levels' => $levels, 'total' => $total];
    }

    private function acres(string $html): ?float
    {
        if (preg_match('/Lot Size:\s*([\d.]+)\s*Acres/i', $html, $m)) {
            return (float) $m[1];
        }

        // Some listings state the lot in square feet instead.
        if (preg_match('/Lot Size:\s*([\d,]+)\s*sq\.?\s*ft/i', $html, $m)) {
            return round(((int) str_replace(',', '', $m[1])) / 43560, 4);
        }

        return null;
    }

    /**
     * Inline config for the mortgage widget. It holds clean, already-parsed values for
     * price, taxes and HOA dues.
     *
     * Note their beds/baths keys are transposed, so those are read from the visible
     * overview instead.
     *
     * @return array<string, string>
     */
    private function widgetConfig(string $html): array
    {
        $out = [];

        if (preg_match('/cordlessw\(\s*[\'"]message[\'"]\s*,\s*\{(.*?)\}\s*\)\s*;/s', $html, $block)) {
            preg_match_all('/(\w+)\s*:\s*"([^"]*)"/', $block[1], $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $out[$m[1]] = $m[2];
            }
        }

        return $out;
    }

    private function hoaDetails(Crawler $crawler): ?string
    {
        $items = $this->featureList($crawler, '#hoa_info');

        return $items ? implode(' • ', $items) : null;
    }

    /** @return array<int, string> */
    private function featureList(Crawler $crawler, string $selector): array
    {
        $node = $crawler->filter($selector);

        if (! $node->count()) {
            return [];
        }

        return $node->filter('li')
            ->each(fn (Crawler $li) => $this->text($li));
    }

    private function garageCapacity(array $exteriorFeatures): ?int
    {
        foreach ($exteriorFeatures as $feature) {
            if (preg_match('/Garage Capacity:\s*(\d+)/i', (string) $feature, $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    private function basementPct(array $interiorFeatures): ?int
    {
        foreach ($interiorFeatures as $feature) {
            if (preg_match('/Basement:.*?\((\d{1,3})%\s*finished\)/i', (string) $feature, $m)) {
                return min(100, (int) $m[1]);
            }
            // "Basement: Full" with no percentage stated.
            if (preg_match('/Basement:\s*(?:\(\s*\))?\s*(Full|Partial|Daylight|Walkout)/i', (string) $feature)) {
                return null;
            }
        }

        return null;
    }

    /** The marketing blurb, which is where build timing is usually hidden. */
    private function description(Crawler $crawler): ?string
    {
        $node = $crawler->filter('.features-wrap p')->first();

        if (! $node->count()) {
            return null;
        }

        return $this->text($node);
    }

    /** @return array<string, string> */
    private function schools(Crawler $crawler): array
    {
        $out = [];

        $crawler->filter('.serving-school .col-sm-3')->each(function (Crawler $node) use (&$out) {
            $label = $this->text($node->filter('span')->first());
            $value = $this->text($node->filter('a')->first());

            if ($label && $value) {
                $out[$label] = $value;
            }
        });

        return $out;
    }

    private function agentName(Crawler $crawler): ?string
    {
        foreach (['#contact-agent-name', '.agent___name', '.listing-agent-name'] as $selector) {
            $node = $crawler->filter($selector);
            if ($node->count()) {
                return $this->text($node->first());
            }
        }

        // Fall back to the text that follows the "Contact Agent" heading.
        $heading = $crawler->filterXPath('//h2[contains(text(), "Contact Agent")]');
        if ($heading->count()) {
            $sibling = $heading->nextAll()->first();
            if ($sibling->count()) {
                $text = (string) $this->text($sibling);

                // The name is followed by a phone number in the same block.
                $name = trim(preg_split('/\d{3}[-.\s]\d{3}[-.\s]\d{4}/', $text)[0]);

                return $name !== '' ? mb_substr($name, 0, 120) : null;
            }
        }

        return null;
    }

    private function brokerName(Crawler $crawler): ?string
    {
        $heading = $crawler->filterXPath('//h2[contains(text(), "Listing Broker")]');

        if (! $heading->count()) {
            return null;
        }

        $sibling = $heading->nextAll()->first();
        if (! $sibling->count()) {
            return null;
        }

        $text = trim(preg_replace('/\d{3}[-.\s]\d{3}[-.\s]\d{4}\s*$/', '', (string) $this->text($sibling)));

        return $text !== '' ? mb_substr($text, 0, 160) : null;
    }

    private function phone(string $html): ?string
    {
        if (preg_match('/"telephone"\s*:\s*"([^"]+)"/', $html, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Gallery photos. Their CDN serves the same file at several widths, so keep a
     * display size and a large size for the full-screen viewer.
     *
     * @return array<int, array<string, mixed>>
     */
    private function photos(Crawler $crawler, string $mlsNumber): array
    {
        $photos = [];
        $seen = [];

        $crawler->filter('img')->each(function (Crawler $img) use (&$photos, &$seen, $mlsNumber) {
            $src = $img->attr('src') ?? '';

            if (! str_contains($src, 'assets.utahrealestate.com/photos/')) {
                return;
            }

            if (! preg_match('#/photos/\d+x\d+/('.preg_quote($mlsNumber, '#').'_[A-Za-z0-9_]+\.jpe?g)#', $src, $m)) {
                return;
            }

            $file = $m[1];
            if (isset($seen[$file])) {
                return;
            }
            $seen[$file] = true;

            $photos[] = [
                'position' => count($photos),
                'url' => $this->photoUrl($file, '1024x768'),
                'thumb_url' => $this->photoUrl($file, '280x210'),
                'full_url' => $this->photoUrl($file, '2048x1536'),
                'caption' => $img->attr('alt') ?: null,
            ];
        });

        return $photos;
    }

    private function photoUrl(string $file, string $size): string
    {
        return "https://assets.utahrealestate.com/photos/{$size}/{$file}";
    }

    private function text(?Crawler $node): ?string
    {
        if ($node === null || ! $node->count()) {
            return null;
        }

        $text = html_entity_decode($node->text(''), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/', ' ', $text)) ?: null;
    }

    /**
     * Numbers arrive with currency symbols and thousands separators, and the mortgage
     * widget divides annual HOA dues into a long fraction. The decimal point has to be
     * kept and rounded, otherwise "32.083333333333" collapses into 32083333333333.
     */
    private function int(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = preg_replace('/[^\d.]/', '', (string) $value);

        return is_numeric($number) ? (int) round((float) $number) : null;
    }
}
