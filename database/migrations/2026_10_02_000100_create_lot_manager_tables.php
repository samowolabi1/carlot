<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lot Manager (M19) lite, from the TDD's schema. Instalments, papers and costs arrive in S10. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lot_customers', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('phone', 20);
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('source', 16)->default('walk_in');
            $table->json('tags')->nullable();
            $table->unsignedBigInteger('budget_max')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('consent_whatsapp')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['lot_id', 'phone']);
        });

        Schema::create('walk_ins', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lot_customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('visited_at');
            $table->json('vehicles_viewed')->nullable();
            $table->string('interest', 16)->default('browsing');
            $table->string('next_step', 16)->default('none');
            $table->timestamp('follow_up_at')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->timestamps();

            $table->index(['lot_id', 'visited_at']);
        });

        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->string('order_no', 32);
            $table->foreignId('lot_customer_id')->constrained();
            $table->foreignId('vehicle_id')->constrained();
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('list_price');
            $table->unsignedBigInteger('agreed_price');
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('trade_in_id')->nullable(); // Trade-ins arrive in S9.
            $table->unsignedBigInteger('trade_in_value')->default(0);
            $table->unsignedBigInteger('deposit_required')->default(0);
            // Cached from order_payments; can go negative only if a refund exceeds payments.
            $table->bigInteger('total_paid')->default(0);
            $table->bigInteger('balance')->default(0);
            $table->char('currency', 3)->default('NGN');
            $table->string('payment_plan', 16)->default('full');
            $table->string('status', 16)->default('draft');
            $table->unsignedBigInteger('reservation_id')->nullable(); // Reservations arrive in S9.
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_reason')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->timestamps();

            $table->unique(['lot_id', 'order_no']);
            $table->index(['lot_id', 'status']);
        });

        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            // Negative for refunds.
            $table->bigInteger('amount');
            $table->string('method', 16);
            $table->string('reference')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at');
            $table->string('receipt_no', 32)->nullable();
            $table->string('receipt_path')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->timestamps();

            $table->unique(['lot_id', 'receipt_no']);
        });

        Schema::create('follow_up_tasks', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lot_customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('walk_in_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 16)->default('call');
            $table->timestamp('due_at');
            $table->string('note')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->index(['assigned_to', 'due_at']);
            $table->index(['lot_id', 'done_at', 'due_at']);
        });

        // Gap-free, per-lot sequences for order and receipt numbers.
        Schema::create('lot_counters', function (Blueprint $table) {
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->string('name', 32);
            $table->unsignedInteger('value')->default(0);

            $table->primary(['lot_id', 'name']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['lot_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('lot_counters');
        Schema::dropIfExists('follow_up_tasks');
        Schema::dropIfExists('order_payments');
        Schema::dropIfExists('sales_orders');
        Schema::dropIfExists('walk_ins');
        Schema::dropIfExists('lot_customers');
    }
};
