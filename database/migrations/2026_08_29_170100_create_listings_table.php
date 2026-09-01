<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->string('mls_number')->unique();
            $table->string('url');

            $table->string('street_address')->nullable();
            $table->string('unit')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 8)->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->string('subdivision')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->unsignedInteger('price')->nullable();
            $table->unsignedInteger('hoa_monthly')->default(0);
            $table->text('hoa_details')->nullable();
            $table->unsignedInteger('property_tax_annual')->nullable();

            $table->unsignedSmallInteger('beds')->nullable();
            $table->decimal('baths', 4, 1)->nullable();
            $table->unsignedInteger('total_sqft')->nullable();

            // Kept as text, not json: MySQL sorts the keys of a json object, and this is
            // displayed floor by floor in the order the listing states them.
            $table->text('sqft_levels')->nullable();
            $table->decimal('acres', 10, 4)->nullable();
            $table->unsignedSmallInteger('garage_capacity')->nullable();
            $table->unsignedSmallInteger('basement_finished_pct')->nullable();

            $table->string('property_type')->nullable();
            $table->string('style')->nullable();
            $table->unsignedSmallInteger('year_built')->nullable();

            $table->string('status')->nullable();
            $table->unsignedInteger('days_on_ure')->nullable();

            // Build timing, so a house finishing after the move date can be ruled out.
            $table->boolean('is_new_construction')->default(false);
            $table->date('completion_estimate')->nullable();
            $table->string('completion_note')->nullable();

            $table->text('description')->nullable();
            $table->json('interior_features')->nullable();
            $table->json('exterior_features')->nullable();
            $table->json('other_features')->nullable();

            // Text for the same reason as sqft_levels: these read elementary through
            // district, which a json object would reorder by key length.
            $table->text('schools')->nullable();

            $table->string('agent_name')->nullable();
            $table->string('agent_phone')->nullable();
            $table->string('broker_name')->nullable();

            $table->string('primary_photo_url')->nullable();
            $table->unsignedInteger('photos_count')->default(0);

            // Everything the parsers understood, kept so new columns can be backfilled
            // from stored data instead of re-scraping.
            $table->json('raw')->nullable();

            $table->foreignId('search_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('meets_criteria')->default(false);
            $table->json('criteria_failures')->nullable();

            $table->string('decision')->default('undecided');
            $table->string('decision_reason_code')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('detail_scraped_at')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('delisted_at')->nullable();

            $table->timestamps();

            $table->index('decision');
            $table->index('meets_criteria');
            $table->index('status');
            $table->index('price');
            $table->index('total_sqft');
            $table->index('acres');
            $table->index('city');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
