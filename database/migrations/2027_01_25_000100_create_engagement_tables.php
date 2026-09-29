<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Admin announcements, tips and promos to lot owners (and managers).
        Schema::create('engagement_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('title', 120);
            $table->text('body');
            $table->string('cta_label', 40)->nullable();
            $table->string('cta_url', 500)->nullable();
            $table->json('audience');
            $table->json('channels');
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Every engagement message a person got: from a broadcast or an automated rule, with its click.
        Schema::create('engagement_messages', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('broadcast_id')->nullable()->constrained('engagement_broadcasts')->cascadeOnDelete();
            $table->string('rule', 40)->nullable();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('url', 500)->nullable();
            $table->timestamp('sent_at');
            $table->timestamp('clicked_at')->nullable();
            $table->timestamps();

            $table->unique(['broadcast_id', 'user_id']); // a broadcast reaches each person once
            $table->index(['rule', 'user_id', 'sent_at']);
            $table->index(['rule', 'lot_id', 'sent_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_seen_at']);
        });
        Schema::dropIfExists('engagement_messages');
        Schema::dropIfExists('engagement_broadcasts');
    }
};
