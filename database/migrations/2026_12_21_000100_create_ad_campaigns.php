<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Paid adverts lots buy from LotLink: homepage banners and search banners, reviewed before they run. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_campaigns', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->string('placement', 20); // home_banner, search_banner
            $table->string('status', 12)->default('draft'); // draft (unpaid), in_review, approved, rejected, removed
            $table->string('headline', 60);
            $table->string('subtext', 120)->nullable();
            $table->string('cta', 30);
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete(); // null: the ad opens the lot page
            $table->string('image_path')->nullable(); // WebP on the media disk; null uses the car's photo
            $table->json('targeting')->nullable(); // search banners: make_id, body_type, city
            $table->unsignedSmallInteger('days');
            $table->date('requested_start');
            $table->timestamp('starts_at')->nullable(); // set on approval
            $table->timestamp('ends_at')->nullable();
            $table->unsignedBigInteger('price'); // kobo
            $table->char('currency', 3)->default('NGN');
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 255)->nullable();
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamps();

            $table->index(['placement', 'status', 'starts_at', 'ends_at']);
            $table->index(['lot_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_campaigns');
    }
};
