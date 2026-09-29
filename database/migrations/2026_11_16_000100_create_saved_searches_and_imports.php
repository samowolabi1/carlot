<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** S13 (TDD M4, M3, M18): saved-search alerts and bulk vehicle import. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_searches', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->json('filters'); // the /cars query string, whole naira
            $table->string('channel', 8)->default('phone'); // phone (WhatsApp/SMS), mail, app
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::create('vehicle_imports', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_path');
            $table->string('original_name', 160);
            $table->string('status', 12)->default('queued'); // queued, processing, done, failed
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->json('errors')->nullable(); // [{row, messages: []}]
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['lot_id', 'created_at']);
        });

        // Spec: bulk import is an Enterprise feature.
        foreach (DB::table('plans')->get() as $plan) {
            $features = json_decode((string) $plan->features, true) ?: [];
            DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode([...$features, 'bulk_import' => $plan->code === 'enterprise'])]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_imports');
        Schema::dropIfExists('saved_searches');
    }
};
