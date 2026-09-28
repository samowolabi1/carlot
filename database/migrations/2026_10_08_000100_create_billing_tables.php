<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Billing (M16), spotlight (M5) and following lots, from the TDD's schema. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // Paystack plan code (PLN_...) for recurring billing; set in the admin or by billing:sync-plans.
            $table->string('provider_plan_code', 64)->nullable()->after('interval');
            $table->boolean('self_serve')->default(true)->after('free_spotlights'); // false: "Talk to us"
            $table->unsignedSmallInteger('sort')->default(0);
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->foreignId('plan_id')->constrained();
            $table->unsignedSmallInteger('trial_days');
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redeemed')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lot_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->string('status', 16); // trialing, active, past_due, cancelled
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('cancel_at_period_end')->nullable(); // when the owner asked to stop renewing
            $table->string('provider_ref', 64)->nullable()->index(); // Paystack subscription code
            $table->string('provider_token', 64)->nullable(); // Paystack email token, needed to disable
            $table->string('customer_code', 64)->nullable()->index();
            $table->string('card_brand', 24)->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('trial_reminded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->nullableMorphs('payable');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 24); // subscription, renewal, spotlight
            $table->string('description');
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('NGN');
            $table->string('provider', 16);
            $table->string('reference', 64)->unique();
            $table->string('status', 16)->default('pending'); // pending, success, failed, refunded
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['lot_id', 'created_at']);
        });

        Schema::create('spotlights', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('placement', 16); // car (search top + home carousel) or featured_lot
            $table->unsignedSmallInteger('days');
            $table->string('status', 16)->default('pending'); // pending, paid, cancelled
            $table->boolean('free')->default(false); // from the plan's monthly allowance
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bought_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['placement', 'ends_at']);
        });

        Schema::table('lots', function (Blueprint $table) {
            $table->timestamp('featured_until')->nullable();
            $table->timestamp('followers_notified_at')->nullable();
        });

        // Every incoming webhook, kept for audit and so a redelivery is handled once.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 16);
            $table->string('event', 64);
            $table->char('hash', 64)->unique();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
        });

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        // Lots that already exist start the 14-day trial now.
        $now = now();
        foreach (DB::table('lots')->whereNull('deleted_at')->get(['id', 'plan_id']) as $lot) {
            if ($lot->plan_id !== null) {
                DB::table('subscriptions')->insert([
                    'lot_id' => $lot->id, 'plan_id' => $lot->plan_id, 'status' => 'trialing',
                    'trial_ends_at' => $now->copy()->addDays(14), 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::table('lots', fn (Blueprint $table) => $table->dropColumn(['featured_until', 'followers_notified_at']));
        Schema::dropIfExists('spotlights');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('coupons');
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn(['provider_plan_code', 'self_serve', 'sort']));
    }
};
