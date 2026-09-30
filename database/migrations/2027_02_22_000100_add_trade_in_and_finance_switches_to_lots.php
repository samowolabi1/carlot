<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Owners choose whether buyers can send trade-ins and car loan applications (both on, as before). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->boolean('accepts_trade_ins')->default(true)->after('accepts_offers');
            $table->boolean('accepts_finance')->default(true)->after('accepts_trade_ins');
        });
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->dropColumn(['accepts_trade_ins', 'accepts_finance']);
        });
    }
};
