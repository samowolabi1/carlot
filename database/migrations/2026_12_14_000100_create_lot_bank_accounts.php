<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LotLink never takes money for cars: buyers pay the lot directly. Lots keep the bank accounts
 * they share with customers, and reservation deposits are confirmed by the lot when the transfer lands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lot_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->string('bank_name', 80);
            $table->string('account_number', 20);
            $table->string('account_name', 120);
            $table->boolean('is_default')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['lot_id', 'account_number', 'bank_name']);
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->string('reference', 12)->nullable()->unique()->after('ulid'); // transfer narration, e.g. RES-7K3Q9D
            $table->timestamp('pay_by')->nullable()->after('hours'); // the request lapses if the lot hasn't confirmed by then
            $table->timestamp('buyer_paid_at')->nullable()->after('pay_by'); // the buyer says they sent the transfer
            $table->foreignId('confirmed_by')->nullable()->after('activated_at')->constrained('users')->nullOnDelete();
            $table->boolean('refund_due')->default(false)->after('end_reason'); // the lot owes the buyer the deposit back
            $table->timestamp('refunded_at')->nullable()->after('refund_due');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropUnique(['reference']);
            $table->dropColumn(['reference', 'pay_by', 'buyer_paid_at', 'refund_due', 'refunded_at']);
        });
        Schema::dropIfExists('lot_bank_accounts');
    }
};
