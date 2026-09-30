<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Car loans start on LotLink and continue at the lender: the lender's next steps for the buyer. And a record of who
 * accepted which version of the Terms, Privacy Policy and Lender Terms, and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lenders', function (Blueprint $table) {
            $table->string('next_steps', 1000)->nullable()->after('about');
            $table->string('terms_version', 20)->nullable()->after('review_note');
            $table->timestamp('terms_accepted_at')->nullable()->after('terms_version');
        });

        Schema::table('finance_applications', function (Blueprint $table) {
            $table->string('next_steps', 1000)->nullable()->after('partner_message');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('terms_version', 20)->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
        });

        Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lender_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('document', 20); // terms | privacy | lender_terms
            $table->string('version', 20);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('accepted_at');
            $table->index(['user_id', 'document']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['terms_version', 'terms_accepted_at']));
        Schema::table('finance_applications', fn (Blueprint $table) => $table->dropColumn('next_steps'));
        Schema::table('lenders', fn (Blueprint $table) => $table->dropColumn(['next_steps', 'terms_version', 'terms_accepted_at']));
    }
};
