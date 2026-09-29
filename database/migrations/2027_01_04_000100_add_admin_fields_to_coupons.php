<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Admins can pause a coupon without deleting it, and note what it's for. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->boolean('active')->default(true)->after('expires_at');
            $table->string('note', 160)->nullable()->after('active'); // e.g. "Kano dealer meetup, Jan"
        });
    }

    public function down(): void
    {
        Schema::table('coupons', fn (Blueprint $table) => $table->dropColumn(['active', 'note']));
    }
};
