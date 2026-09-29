<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** S12 (TDD M14, M17): lot verification, inspections, reviews, reports and fraud signals. */
return new class extends Migration
{
    public function up(): void
    {
        // CAC certificate and a photo of the lot frontage, checked by an admin.
        Schema::create('lot_verifications', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->string('cac_number', 20);
            $table->json('documents'); // private `local` disk paths
            $table->string('address_photo_path');
            $table->string('status', 12)->default('submitted'); // submitted, approved, rejected
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['lot_id', 'created_at']);
        });

        Schema::create('inspections', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('inspector_type', 12); // dealer, third_party
            $table->foreignId('inspector_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('inspector_name', 120);
            $table->json('checklist'); // {item_key: {status: pass|advisory|fail, note}}
            $table->json('photos')->nullable(); // {item_key: [media-disk path, ...]}
            $table->unsignedTinyInteger('score');
            $table->text('summary')->nullable();
            $table->string('report_path')->nullable();
            $table->timestamp('signed_at')->nullable(); // third-party inspections only
            $table->timestamps();

            $table->index(['vehicle_id', 'created_at']);
        });

        Schema::table('vehicles', function (Blueprint $table) {
            // Off the marketplace while an admin looks at it (3 open reports, or hidden by an admin).
            $table->timestamp('held_at')->nullable()->after('status');
            $table->string('held_reason', 160)->nullable()->after('held_at');
            $table->foreignId('inspection_id')->nullable()->after('held_reason')->constrained()->nullOnDelete();
        });

        // 64-bit difference hash of each processed photo, for duplicate-photo signals.
        Schema::table('vehicle_media', function (Blueprint $table) {
            $table->char('phash', 16)->nullable()->after('height');
            $table->index('phash');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('inspector_since')->nullable()->after('role');
            $table->string('inspector_company', 120)->nullable()->after('inspector_since');
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->json('tags')->nullable();
            $table->text('body')->nullable();
            $table->text('reply')->nullable();
            $table->foreignId('replied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reply_at')->nullable();
            $table->string('status', 8)->default('visible'); // visible, hidden
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->index(['lot_id', 'status', 'created_at']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('review_invited_at')->nullable()->after('completed_at');
        });

        Schema::table('lots', function (Blueprint $table) {
            // Mean of visible reviews, kept by RefreshLotRating; shown once there are 3.
            $table->decimal('rating', 3, 2)->nullable()->after('verified_at');
            $table->unsignedInteger('reviews_count')->default(0)->after('rating');
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->morphs('reportable'); // vehicle, lot, review, message
            $table->foreignId('lot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 24);
            $table->text('details')->nullable();
            $table->string('status', 12)->default('open'); // open, actioned, dismissed
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'reportable_type', 'reportable_id']);
            $table->index(['status', 'created_at']);
        });

        // Added to the admin review queue; they never block anything automatically.
        Schema::create('fraud_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // duplicate_vin, duplicate_photo, low_price, listing_burst
            $table->foreignId('related_vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->json('details')->nullable();
            $table->string('status', 12)->default('open'); // open, cleared, actioned
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->unique(['vehicle_id', 'type']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_signals');
        Schema::dropIfExists('reports');
        Schema::table('lots', fn (Blueprint $table) => $table->dropColumn(['rating', 'reviews_count']));
        Schema::table('appointments', fn (Blueprint $table) => $table->dropColumn('review_invited_at'));
        Schema::dropIfExists('reviews');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['inspector_since', 'inspector_company']));
        Schema::table('vehicle_media', function (Blueprint $table) {
            $table->dropIndex(['phash']);
            $table->dropColumn('phash');
        });
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inspection_id');
            $table->dropColumn(['held_at', 'held_reason']);
        });
        Schema::dropIfExists('inspections');
        Schema::dropIfExists('lot_verifications');
    }
};
