<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Coordinates for brand-new subdivisions often cannot be resolved to a house number,
     * only to the street. Recording which one we got lets the map say so instead of
     * implying a precision it does not have.
     */
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('location_precision')->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('location_precision');
        });
    }
};
