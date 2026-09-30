<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What the CAC registry says about a submitted number (automatic lookup; admins still decide). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot_verifications', function (Blueprint $table) {
            $table->string('registry_result', 20)->nullable()->after('notes'); // found | not_found | unavailable
            $table->string('registry_name', 200)->nullable()->after('registry_result');
            $table->string('registry_status', 40)->nullable()->after('registry_name');
            $table->date('registry_registered_on')->nullable()->after('registry_status');
            $table->string('registry_address', 255)->nullable()->after('registry_registered_on');
            $table->unsignedTinyInteger('registry_name_match')->nullable()->after('registry_address'); // 0–100
            $table->timestamp('registry_checked_at')->nullable()->after('registry_name_match');
        });
    }

    public function down(): void
    {
        Schema::table('lot_verifications', function (Blueprint $table) {
            $table->dropColumn(['registry_result', 'registry_name', 'registry_status', 'registry_registered_on', 'registry_address', 'registry_name_match', 'registry_checked_at']);
        });
    }
};
