<?php

namespace App\Enums;

enum Decision: string
{
    case Undecided = 'undecided';
    case Favorite = 'favorite';
    case Maybe = 'maybe';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Undecided => 'Undecided',
            self::Favorite => 'Favorite',
            self::Maybe => 'Maybe',
            self::Rejected => 'Crossed off',
        };
    }

    /** Tailwind classes for pills and badges. */
    public function classes(): string
    {
        return match ($this) {
            self::Undecided => 'bg-slate-100 text-slate-600 ring-slate-200',
            self::Favorite => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            self::Maybe => 'bg-amber-50 text-amber-700 ring-amber-200',
            self::Rejected => 'bg-rose-50 text-rose-700 ring-rose-200',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Undecided => '○',
            self::Favorite => '★',
            self::Maybe => '?',
            self::Rejected => '✕',
        };
    }
}
