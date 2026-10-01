<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lots', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('owner_id')->constrained('users');
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('tagline')->nullable();
            $table->text('about')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('cover_path')->nullable();
            $table->char('brand_color', 7)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('whatsapp', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('landmark')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->char('country', 2)->default('NG');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('timezone')->default('Africa/Lagos');
            $table->string('status', 16)->default('pending');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('custom_domain')->nullable()->unique();
            $table->foreignId('plan_id')->nullable()->constrained('plans');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['city', 'status']);
        });

        // "Lots near me" can use a spatial index, which only MySQL 8 provides (MariaDB, common on cPanel, has no
        // SRID columns, so it is skipped there; search uses the latitude/longitude columns either way). The POINT is
        // generated from latitude/longitude so application code only ever writes decimals.
        if (DB::getDriverName() === 'mysql' && ! str_contains((string) DB::connection()->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION), 'MariaDB')) {
            DB::statement('ALTER TABLE lots ADD location POINT GENERATED ALWAYS AS (ST_SRID(POINT(COALESCE(longitude, 0), COALESCE(latitude, 0)), 4326)) STORED NOT NULL SRID 4326');
            DB::statement('ALTER TABLE lots ADD SPATIAL INDEX lots_location_spatial (location)');
        }

        Schema::create('lot_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['lot_id', 'user_id']);
        });

        Schema::create('lot_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->string('phone_or_email');
            $table->string('role', 16);
            $table->string('token', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('lot_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->unsignedSmallInteger('slot_minutes')->default(30);
            $table->unsignedTinyInteger('slot_capacity')->default(2);
            $table->timestamps();

            $table->unique(['lot_id', 'weekday']);
        });

        Schema::create('lot_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['lot_id', 'date']);
        });

        Schema::create('staff_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();
        });

        Schema::create('lot_followers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['lot_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_followers');
        Schema::dropIfExists('staff_availability');
        Schema::dropIfExists('lot_closures');
        Schema::dropIfExists('lot_hours');
        Schema::dropIfExists('lot_invitations');
        Schema::dropIfExists('lot_members');
        Schema::dropIfExists('lots');
    }
};
