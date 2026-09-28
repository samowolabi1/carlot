<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** S10 Lot Manager Pro (TDD M19): instalments, papers and handover, car costs, daily summary, referrals. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instalments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->date('due_date');
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('paid_amount')->default(0);
            $table->string('status', 16)->default('pending'); // pending, part_paid, paid, overdue
            $table->timestamp('reminded_before_at')->nullable(); // 3 days before
            $table->timestamp('reminded_due_at')->nullable();    // on the day
            $table->timestamps();

            $table->unique(['sales_order_id', 'sequence']);
            $table->index(['status', 'due_date']);
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            // Payments made before the plan was set don't count towards it.
            $table->bigInteger('instalments_from_paid')->nullable();
        });

        Schema::create('order_documents', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24); // customs_papers, proof_of_ownership, plate_number, registration, spare_key, other
            $table->string('label', 80)->nullable();
            $table->boolean('mandatory')->default(false);
            $table->string('status', 16)->default('pending'); // pending, received, handed_over
            $table->string('file_path')->nullable(); // private disk
            $table->timestamp('received_at')->nullable();
            $table->timestamp('handed_over_at')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicle_costs', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16); // purchase, clearing, repair, transport, cleaning, other
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('NGN');
            $table->string('supplier', 120)->nullable();
            $table->string('note', 500)->nullable();
            $table->date('incurred_at');
            $table->string('receipt_path')->nullable(); // private disk
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['lot_id', 'vehicle_id']);
        });

        Schema::create('lot_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_lot_id')->constrained('lots')->cascadeOnDelete();
            $table->foreignId('referred_lot_id')->nullable()->unique()->constrained('lots')->nullOnDelete();
            $table->string('status', 16)->default('signed_up'); // signed_up, subscribed, rewarded
            $table->unsignedTinyInteger('reward_months')->default(0);
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamps();
        });

        Schema::table('lots', function (Blueprint $table) {
            $table->string('referral_code', 12)->nullable()->unique();
            $table->date('daily_summary_sent_on')->nullable();
        });

        foreach (DB::table('lots')->whereNull('referral_code')->pluck('id') as $id) {
            DB::table('lots')->where('id', $id)->update(['referral_code' => strtoupper(Str::random(8))]);
        }

        // Plan gating (TDD M19): instalments and the daily summary from Starter; costs, profit,
        // staff reports and Excel export on Pro.
        foreach (DB::table('plans')->get() as $plan) {
            $features = json_decode((string) $plan->features, true) ?: [];
            $starter = in_array($plan->code, ['starter', 'pro', 'enterprise'], true);
            $pro = in_array($plan->code, ['pro', 'enterprise'], true);
            DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode([...$features, 'instalments' => $starter, 'daily_summary' => $starter, 'costs' => $pro])]);
        }
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->dropUnique(['referral_code']);
            $table->dropColumn(['referral_code', 'daily_summary_sent_on']);
        });
        Schema::dropIfExists('lot_referrals');
        Schema::dropIfExists('vehicle_costs');
        Schema::dropIfExists('order_documents');
        Schema::table('sales_orders', fn (Blueprint $table) => $table->dropColumn('instalments_from_paid'));
        Schema::dropIfExists('instalments');
    }
};
