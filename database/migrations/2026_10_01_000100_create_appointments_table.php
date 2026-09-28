<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained('users');
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 16);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 16)->default('pending');
            $table->text('notes')->nullable();
            // Customer's choice at booking: WhatsApp reminders, else SMS.
            $table->boolean('whatsapp_reminders')->default(true);
            $table->unsignedBigInteger('deposit_payment_id')->nullable(); // Test-drive deposits arrive in S9.
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->timestamp('reminded_24h_at')->nullable();
            $table->timestamp('reminded_2h_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamps();

            $table->index(['lot_id', 'starts_at']);
            $table->index(['customer_id', 'starts_at']);
            $table->index(['status', 'starts_at']);
        });

        Schema::table('lots', function (Blueprint $table) {
            $table->boolean('booking_auto_confirm')->default(true)->after('timezone');
            $table->unsignedSmallInteger('booking_min_notice_minutes')->default(120)->after('booking_auto_confirm');
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->dropColumn(['booking_auto_confirm', 'booking_min_notice_minutes']);
        });

        Schema::dropIfExists('appointments');
    }
};
