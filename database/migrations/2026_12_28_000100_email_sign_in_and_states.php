<?php

use App\Domain\Support\Regions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sign in with a WhatsApp number or an email address (people pick one), so accounts may have
 * no phone; one-time codes go to either. Lots record which admin onboarded them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->change();
        });

        Schema::table('otp_codes', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->change();
            $table->string('email')->nullable()->after('phone');
            $table->index(['email', 'purpose']);
        });

        // Buyers who signed up by email reach the customer book by email until they add a phone.
        Schema::table('lot_customers', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->change();
            $table->index(['lot_id', 'email']);
        });

        Schema::table('lots', function (Blueprint $table) {
            $table->foreignId('onboarded_by')->nullable()->after('owner_id')->constrained('users')->nullOnDelete();
            $table->index(['state', 'status']);
        });

        // Tidy free-text states ("Lagos State", "Abuja") onto the list of states.
        foreach (DB::table('lots')->whereNotNull('state')->get(['id', 'state']) as $lot) {
            $state = Regions::normalize($lot->state);
            if ($state !== null && $state !== $lot->state) {
                DB::table('lots')->where('id', $lot->id)->update(['state' => $state]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('lots', function (Blueprint $table) {
            $table->dropIndex(['state', 'status']);
            $table->dropConstrainedForeignId('onboarded_by');
        });
        Schema::table('lot_customers', function (Blueprint $table) {
            $table->dropIndex(['lot_id', 'email']);
        });
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropIndex(['email', 'purpose']);
            $table->dropColumn('email');
        });
    }
};
