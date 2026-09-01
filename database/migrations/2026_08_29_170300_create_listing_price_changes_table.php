<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_price_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('old_price')->nullable();
            $table->unsignedInteger('new_price');
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->index(['listing_id', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_price_changes');
    }
};
