<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favourites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            // Price when saved, to show "Price dropped" on the Saved page.
            $table->unsignedBigInteger('saved_price')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'vehicle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favourites');
    }
};
