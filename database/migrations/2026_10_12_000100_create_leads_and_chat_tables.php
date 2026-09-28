<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Leads and chat (M11) and notification preferences (M18), from the TDD's schema. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete(); // the buyer's LotLink account
            $table->foreignId('lot_customer_id')->nullable()->constrained()->nullOnDelete();  // the lot's customer book
            $table->string('source', 16);
            $table->string('stage', 16)->default('new');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('lost_reason')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->timestamp('follow_up_reminded_at')->nullable();
            $table->timestamp('first_response_at')->nullable(); // for staff response times (S11)
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['lot_id', 'stage']);
            $table->index(['assigned_to', 'next_follow_up_at']);
            $table->index(['lot_id', 'customer_id', 'vehicle_id']);
        });

        Schema::create('lead_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('lead_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('last_message_at')->nullable();
            // Read markers per side, and when each side was last told about unread messages.
            $table->timestamp('customer_read_at')->nullable();
            $table->timestamp('lot_read_at')->nullable();
            $table->timestamp('customer_notified_at')->nullable();
            $table->timestamp('lot_notified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('side', 8); // customer, lot or system
            $table->text('body');
            $table->string('attachment_path')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('notification_preferences'));
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('lead_notes');
        Schema::dropIfExists('leads');
    }
};
