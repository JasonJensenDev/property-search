<?php

namespace App\Enums;

/**
 * Preset reasons for crossing a listing off. These are shortcuts only: every
 * decision can also carry free-text detail, and "other" exists for anything
 * that does not fit a preset.
 */
enum RejectionReason: string
{
    case LotTooSmall = 'lot_too_small';
    case LotUnusable = 'lot_unusable';
    case BadLayout = 'bad_layout';
    case NeedsTooMuchWork = 'needs_too_much_work';
    case Dated = 'dated';
    case Location = 'location';
    case BusyRoad = 'busy_road';
    case Neighbors = 'neighbors';
    case UnfinishedBasement = 'unfinished_basement';
    case NotEnoughGarage = 'not_enough_garage';
    case CompletionTooLate = 'completion_too_late';
    case HasHoa = 'has_hoa';
    case Overpriced = 'overpriced';
    case PhotosMisleading = 'photos_misleading';
    case UnderContract = 'under_contract';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::LotTooSmall => 'Lot too small',
            self::LotUnusable => 'Lot shape / slope unusable',
            self::BadLayout => 'Floor plan does not work',
            self::NeedsTooMuchWork => 'Needs too much work',
            self::Dated => 'Too dated inside',
            self::Location => 'Location',
            self::BusyRoad => 'On a busy road',
            self::Neighbors => 'Neighbors / surroundings',
            self::UnfinishedBasement => 'Basement unfinished',
            self::NotEnoughGarage => 'Not enough garage',
            self::CompletionTooLate => 'Completes too late',
            self::HasHoa => 'Has an HOA',
            self::Overpriced => 'Overpriced for what it is',
            self::PhotosMisleading => 'Photos hide problems',
            self::UnderContract => 'Already under contract',
            self::Other => 'Other',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}
