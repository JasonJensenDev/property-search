<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('url');
            $table->string('thumb_url')->nullable();
            $table->string('full_url')->nullable();
            $table->text('caption')->nullable();
            $table->timestamps();

            $table->unique(['listing_id', 'url']);
            $table->index(['listing_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_photos');
    }
};
