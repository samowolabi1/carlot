<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('makes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('logo_path')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicle_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('make_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('body_type', 16)->nullable();
            // Models dealers add themselves wait here for an admin to check them.
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['make_id', 'slug']);
        });

        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('group', 16);
            $table->timestamps();
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('make_id')->nullable()->constrained();
            $table->foreignId('vehicle_model_id')->nullable()->constrained();
            $table->string('slug')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('trim')->nullable();
            $table->string('body_type', 16)->nullable();
            $table->unsignedInteger('mileage_km')->nullable();
            $table->unsignedBigInteger('price')->nullable();
            $table->char('currency', 3)->default('NGN');
            $table->boolean('negotiable')->default(true);
            $table->string('condition', 16)->nullable();
            $table->string('transmission', 16)->nullable();
            $table->string('fuel', 16)->nullable();
            $table->unsignedSmallInteger('engine_cc')->nullable();
            $table->string('drivetrain', 8)->nullable();
            $table->string('colour', 40)->nullable();
            $table->string('interior_colour', 40)->nullable();
            $table->string('vin', 17)->nullable();
            $table->string('duty_status', 8)->nullable();
            $table->boolean('registered')->default(false);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamp('spotlight_until')->nullable();
            $table->timestamp('listed_at')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('price_changed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['lot_id', 'status']);
            $table->index(['status', 'listed_at']);
            $table->index(['make_id', 'vehicle_model_id', 'year']);
            $table->index('vin');
        });

        Schema::create('vehicle_media', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16)->default('photo');
            $table->string('status', 16)->default('processing');
            $table->string('original_path')->nullable();
            $table->string('path')->nullable();
            $table->string('thumb_path')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_cover')->default(false);
            $table->string('error')->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'sort_order']);
        });

        Schema::create('vehicle_features', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();

            $table->primary(['vehicle_id', 'feature_id']);
        });

        Schema::create('vehicle_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('old_price');
            $table->unsignedBigInteger('new_price');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_price_history');
        Schema::dropIfExists('vehicle_features');
        Schema::dropIfExists('vehicle_media');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('features');
        Schema::dropIfExists('vehicle_models');
        Schema::dropIfExists('makes');
    }
};
