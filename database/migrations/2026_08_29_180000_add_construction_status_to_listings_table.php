<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            // utahrealestate.com badges unfinished homes as "To Be Built" or
            // "Under Construction" and shows nothing at all once they are complete.
            // This is far more dependable than reading the marketing blurb.
            $table->string('construction_status')->nullable()->after('style');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('construction_status');
        });
    }
};
