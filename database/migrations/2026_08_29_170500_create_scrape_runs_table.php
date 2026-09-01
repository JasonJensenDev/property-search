<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrape_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('search_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('running');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->unsignedInteger('cards_found')->default(0);
            $table->unsignedInteger('listings_created')->default(0);
            $table->unsignedInteger('listings_updated')->default(0);
            $table->unsignedInteger('details_fetched')->default(0);
            $table->unsignedInteger('delisted')->default(0);
            $table->unsignedInteger('price_changes')->default(0);

            $table->text('message')->nullable();
            $table->json('log')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scrape_runs');
    }
};
