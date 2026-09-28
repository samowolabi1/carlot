<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->unsignedBigInteger('price')->default(0);
            $table->char('currency', 3)->default('NGN');
            $table->string('interval', 16)->default('month');
            $table->unsignedInteger('listing_limit')->nullable();
            $table->unsignedInteger('staff_limit')->nullable();
            $table->json('features')->nullable();
            $table->unsignedTinyInteger('free_spotlights')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
