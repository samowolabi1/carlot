<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** S14 (TDD M9, M6, M10, Security): social auto-post, custom domains, finance hand-off, admin 2FA, account deletion. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 12); // facebook, instagram
            $table->string('page_id', 64);
            $table->string('name', 160);
            $table->text('token'); // encrypted cast
            $table->timestamp('expires_at')->nullable();
            $table->boolean('auto_post')->default(true);
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_error_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->unique(['lot_id', 'provider']);
        });

        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 128)->nullable();
            $table->string('status', 12)->default('queued'); // queued, posted, failed
            $table->string('error', 255)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['vehicle_id', 'social_account_id']);
        });

        // lots.custom_domain (unique) exists since S1; this adds ownership proof.
        Schema::table('lots', function (Blueprint $table) {
            $table->string('domain_token', 40)->nullable()->after('custom_domain');
            $table->timestamp('domain_verified_at')->nullable()->after('domain_token');
        });

        Schema::create('finance_applications', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('partner', 32);
            $table->unsignedBigInteger('amount'); // loan asked for, kobo
            $table->unsignedBigInteger('deposit')->default(0);
            $table->unsignedSmallInteger('tenor_months');
            $table->char('currency', 3)->default('NGN');
            $table->text('applicant'); // what the buyer agreed to share (JSON, encrypted cast)
            $table->timestamp('consented_at');
            $table->string('status', 16)->default('submitted'); // submitted, received, pre_approved, declined, failed
            $table->string('external_ref', 100)->nullable();
            $table->string('partner_message', 255)->nullable();
            $table->unsignedBigInteger('approved_amount')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->unique(['partner', 'external_ref']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            $table->timestamp('deletion_requested_at')->nullable()->after('deleted_at');
            $table->timestamp('anonymised_at')->nullable()->after('deletion_requested_at');
        });

        foreach (DB::table('plans')->get() as $plan) {
            $features = json_decode((string) $plan->features, true) ?: [];
            DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode([...$features, 'custom_domain' => $plan->code === 'enterprise'])]);
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'deletion_requested_at', 'anonymised_at']));
        Schema::dropIfExists('finance_applications');
        Schema::table('lots', fn (Blueprint $table) => $table->dropColumn(['domain_token', 'domain_verified_at']));
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('social_accounts');
    }
};
