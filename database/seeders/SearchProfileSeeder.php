<?php

namespace Database\Seeders;

use App\Models\SearchProfile;
use Illuminate\Database\Seeder;

class SearchProfileSeeder extends Seeder
{
    public function run(): void
    {
        SearchProfile::updateOrCreate(
            ['name' => 'Grantsville'],
            [
                'is_active' => true,
                'cities' => ['Grantsville'],

                // Loose values for utahrealestate.com. 3000 is the largest square footage
                // bucket they offer below the real 3,500 target, so the extra results are
                // pulled in here and trimmed by the exact filters below.
                'remote_min_sqft' => 3000,
                'remote_min_acres' => '.25',
                'remote_max_price' => 800000,
                'remote_statuses' => ['1', '2', '7', '13'],

                // The criteria that actually decide what shows up in the review queue.
                'min_sqft' => 3500,
                'max_sqft' => null,
                'min_acres' => 0.25,
                'max_acres' => null,
                'min_price' => null,
                'max_price' => 800000,
                'min_beds' => null,
                'min_baths' => null,
                'min_garage' => null,
                'exclude_hoa' => true,
                'max_hoa_monthly' => null,

                // The move is at the beginning of October, with a few days of slack. Homes
                // finishing just after this date are not lost: they show up under "So
                // close" on the dashboard, since a week's delay may still be workable.
                'require_move_in_ready' => true,
                'ready_by' => '2026-10-05',
            ],
        );
    }
}
