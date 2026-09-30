<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Lenders (car loan partners) as accounts: they apply or are onboarded by an admin, have a team,
 * loan products, and work applications in the lender portal or through their own API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lenders', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->string('status', 16)->default('pending')->index();
            $table->string('licence_type', 24);
            $table->string('licence_number', 40);
            $table->string('licence_path')->nullable(); // private disk
            $table->string('contact_name', 80);
            $table->string('contact_email', 190);
            $table->string('contact_phone', 20);
            $table->string('website', 190)->nullable();
            $table->string('about', 600)->nullable();
            // Products: yearly rate in basis points (2400 = 24%), amounts in kobo.
            $table->unsignedSmallInteger('rate_bp');
            $table->unsignedBigInteger('min_amount');
            $table->unsignedBigInteger('max_amount');
            $table->unsignedTinyInteger('min_deposit_percent')->default(10);
            $table->json('tenors');
            $table->json('states')->nullable(); // null: lends everywhere
            $table->string('integration', 12)->default('portal');
            $table->string('api_url', 255)->nullable();
            $table->text('api_key')->nullable(); // encrypted
            $table->text('webhook_secret')->nullable(); // encrypted
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('lender_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lender_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12)->default('officer');
            $table->timestamps();
            $table->unique(['lender_id', 'user_id']);
        });

        Schema::table('finance_applications', function (Blueprint $table) {
            $table->string('status', 24)->default('submitted')->change(); // "documents_requested" is longer than the old 16
        });

        Schema::table('finance_applications', function (Blueprint $table) {
            $table->foreignId('lender_id')->nullable()->after('lot_id')->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->after('lender_id')->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('offer_rate_bp')->nullable()->after('approved_amount');
            $table->unsignedTinyInteger('offer_tenor_months')->nullable()->after('offer_rate_bp');
            $table->unsignedBigInteger('disbursed_amount')->nullable()->after('offer_tenor_months');
            $table->string('disbursed_reference', 64)->nullable()->after('disbursed_amount');
            $table->timestamp('disbursed_at')->nullable()->after('disbursed_reference');
            $table->timestamp('decided_at')->nullable()->after('disbursed_at');
            $table->timestamp('buyer_read_at')->nullable()->after('decided_at');
            $table->timestamp('lender_read_at')->nullable()->after('buyer_read_at');
            $table->index(['lender_id', 'status']);
        });

        Schema::create('finance_messages', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('finance_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('side', 8); // buyer | lender | system
            $table->text('body');
            $table->string('attachment_path')->nullable(); // private disk
            $table->string('attachment_name', 120)->nullable();
            $table->timestamps();
            $table->index(['finance_application_id', 'id']);
        });

        // Applications sent before lenders were accounts belong to the partner that was configured then.
        if (DB::table('finance_applications')->exists()) {
            $code = (string) env('FINANCE_PARTNER_CODE', 'demo');
            $id = DB::table('lenders')->insertGetId([
                'ulid' => (string) Str::ulid(), 'slug' => Str::slug($code) ?: 'partner', 'name' => (string) env('FINANCE_PARTNER_NAME', 'Demo Finance'),
                'status' => 'active', 'licence_type' => 'other', 'licence_number' => 'N/A', 'contact_name' => 'Partner', 'contact_email' => 'partner@example.com',
                'contact_phone' => '+2340000000000', 'rate_bp' => 2400, 'min_amount' => 100_000_000, 'max_amount' => 5_000_000_000, 'min_deposit_percent' => 10,
                'tenors' => json_encode([12, 24, 36, 48]), 'integration' => env('FINANCE_PARTNER_DRIVER') === 'http' ? 'api' : 'demo',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('finance_applications')->whereNull('lender_id')->update(['lender_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_messages');
        Schema::table('finance_applications', function (Blueprint $table) {
            $table->dropForeign(['lender_id']);
            $table->dropForeign(['assigned_to']);
            $table->dropIndex(['lender_id', 'status']);
            $table->dropColumn(['lender_id', 'assigned_to']);
            $table->dropColumn(['offer_rate_bp', 'offer_tenor_months', 'disbursed_amount', 'disbursed_reference', 'disbursed_at', 'decided_at', 'buyer_read_at', 'lender_read_at']);
        });
        Schema::dropIfExists('lender_members');
        Schema::dropIfExists('lenders');
    }
};
