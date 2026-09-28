<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** S11 (TDD M15, M8 live): event tracking, daily rollups and live location sessions. */
return new class extends Migration
{
    public function up(): void
    {
        // Raw events from TrackEvent; kept 90 days. Dashboards read only the rollups.
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 16); // view, save, share, lead, booking
            $table->string('channel', 24)->nullable(); // share platform, lead source, view referrer (share link)
            $table->timestamp('occurred_at');

            $table->index(['lot_id', 'occurred_at']);
            $table->index('occurred_at');
        });

        Schema::create('daily_vehicle_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->date('date'); // in the lot's timezone
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('saves')->default(0);
            $table->unsignedInteger('shares')->default(0);
            $table->unsignedInteger('leads')->default(0);
            $table->unsignedInteger('bookings')->default(0);

            $table->unique(['vehicle_id', 'date']);
            $table->index(['lot_id', 'date']);
        });

        Schema::create('location_sessions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sharer_id')->constrained('users')->cascadeOnDelete();
            $table->string('sharer_side', 8); // customer or lot
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            // Only the latest point, cleared when the session ends (TDD: privacy).
            $table->decimal('last_latitude', 10, 7)->nullable();
            $table->decimal('last_longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('accuracy_m')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['appointment_id', 'ended_at']);
            $table->index(['ended_at', 'expires_at']);
        });

        // Plan gating (spec): basic analytics on Starter, full analytics (sources, staff, exports) on Pro.
        foreach (DB::table('plans')->get() as $plan) {
            $features = json_decode((string) $plan->features, true) ?: [];
            DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode([
                ...$features,
                'analytics' => in_array($plan->code, ['starter', 'pro', 'enterprise'], true),
                'analytics_full' => in_array($plan->code, ['pro', 'enterprise'], true),
            ])]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('location_sessions');
        Schema::dropIfExists('daily_vehicle_stats');
        Schema::dropIfExists('analytics_events');
    }
};
