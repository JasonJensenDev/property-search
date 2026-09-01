<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);

            // Cities to sweep on utahrealestate.com. The portal only accepts one city
            // per search, so the scraper runs once per entry.
            $table->json('cities');

            // Coarse criteria handed to utahrealestate.com. Their square footage filter
            // only offers 500/1000-step buckets, so we deliberately ask for a looser
            // value here and tighten it locally with min_sqft below.
            $table->unsignedInteger('remote_min_sqft')->nullable();
            $table->string('remote_min_acres')->nullable();
            $table->unsignedInteger('remote_max_price')->nullable();
            $table->json('remote_statuses');

            // Exact criteria enforced locally, which is the whole point of this app.
            $table->unsignedInteger('min_sqft')->nullable();
            $table->unsignedInteger('max_sqft')->nullable();
            $table->decimal('min_acres', 10, 4)->nullable();
            $table->decimal('max_acres', 10, 4)->nullable();
            $table->unsignedInteger('min_price')->nullable();
            $table->unsignedInteger('max_price')->nullable();
            $table->unsignedSmallInteger('min_beds')->nullable();
            $table->decimal('min_baths', 4, 1)->nullable();
            $table->unsignedSmallInteger('min_garage')->nullable();
            $table->boolean('exclude_hoa')->default(false);
            $table->unsignedInteger('max_hoa_monthly')->nullable();

            // Move-in timing. A listing is "ready" when it is already built, or when a
            // build completion date lands on or before ready_by.
            $table->date('ready_by')->nullable();
            $table->boolean('require_move_in_ready')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_profiles');
    }
};
