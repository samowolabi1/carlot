<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** S9 (TDD M12): offers and counters, trade-in valuations, reservations and test-drive deposits. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users');
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('NGN');
            $table->text('message')->nullable();
            // pending → accepted | declined | countered → (buyer) accepted | declined; expired; withdrawn
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('counter_amount')->nullable();
            $table->string('counter_message', 500)->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['lot_id', 'status']);
            $table->index(['vehicle_id', 'customer_id']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('trade_ins', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained('users');
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete(); // the car it goes towards
            $table->foreignId('make_id')->constrained();
            $table->foreignId('vehicle_model_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model_name', 60)->nullable(); // when the model isn't in the catalogue
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('mileage_km');
            $table->string('condition', 16);
            $table->text('notes')->nullable();
            $table->json('photos'); // paths on the private disk
            $table->unsignedBigInteger('estimate_low')->nullable();
            $table->unsignedBigInteger('estimate_high')->nullable();
            $table->char('currency', 3)->default('NGN');
            $table->string('valuation_note', 500)->nullable();
            $table->foreignId('valued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('valued_at')->nullable();
            $table->string('status', 16)->default('submitted'); // submitted, valued, accepted, declined
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['lot_id', 'status']);
            $table->index('customer_id');
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users');
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount'); // the deposit
            $table->unsignedBigInteger('price'); // the car's price when reserved (an accepted offer's, or the list price)
            $table->char('currency', 3)->default('NGN');
            $table->unsignedTinyInteger('hours');
            // pending (awaiting payment) → active → converted | expired | cancelled; refunded when the deposit goes back
            $table->string('status', 16)->default('pending');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason')->nullable();
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['vehicle_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::table('lots', function (Blueprint $table) {
            $table->boolean('accepts_offers')->default(true);
            $table->unsignedBigInteger('reservation_deposit')->nullable(); // null: reservations off
            $table->boolean('reservation_refundable')->default(true); // refund on expiry without a sale
            $table->unsignedBigInteger('test_drive_deposit')->nullable(); // null: no deposit
            $table->string('paystack_subaccount', 40)->nullable(); // deposits settle to the lot through a split
        });

        // Offers and deposits are Pro features (spec: monetisation).
        foreach (DB::table('plans')->get() as $plan) {
            $features = json_decode((string) $plan->features, true) ?: [];
            $allowed = in_array($plan->code, ['pro', 'enterprise'], true);
            DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode([...$features, 'offers' => $allowed, 'deposits' => $allowed])]);
        }
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->dropColumn(['accepts_offers', 'reservation_deposit', 'reservation_refundable', 'test_drive_deposit', 'paystack_subaccount']);
        });
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('trade_ins');
        Schema::dropIfExists('offers');
    }
};
