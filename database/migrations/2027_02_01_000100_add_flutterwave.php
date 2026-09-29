<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which provider bills a subscription's renewals (it stays with the one it started on).
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('provider', 20)->nullable()->after('status');
        });
        DB::table('subscriptions')->where(fn ($q) => $q->whereNotNull('provider_ref')->orWhereNotNull('customer_code'))->update(['provider' => 'paystack']);

        // Flutterwave's payment plan id, next to the Paystack plan code.
        Schema::table('plans', function (Blueprint $table) {
            $table->string('flutterwave_plan_id', 40)->nullable()->after('provider_plan_code');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('flutterwave_plan_id');
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('provider');
        });
    }
};
