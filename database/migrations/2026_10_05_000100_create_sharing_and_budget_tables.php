<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sharing (M9) and budgeting (M10) from the TDD's schema. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_links', function (Blueprint $table) {
            $table->id();
            $table->char('code', 8)->unique();
            $table->foreignId('vehicle_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 16);
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamp('last_clicked_at')->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'platform']);
            $table->index(['lot_id', 'platform']);
        });

        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // Minor units (kobo), like every other amount.
            $table->unsignedBigInteger('monthly_income');
            $table->unsignedBigInteger('monthly_commitments')->default(0);
            $table->unsignedBigInteger('deposit')->default(0);
            $table->unsignedSmallInteger('tenor_months');
            $table->decimal('interest_rate', 5, 2);
            $table->unsignedBigInteger('max_price');
            $table->char('currency', 3)->default('NGN');
            $table->timestamps();
        });

        Schema::table('vehicles', function (Blueprint $table) {
            // Fingerprint of what the share cards show; a new one means re-render.
            $table->string('share_card_hash', 40)->nullable()->after('price_changed_at');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', fn (Blueprint $table) => $table->dropColumn('share_card_hash'));
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('share_links');
    }
};
