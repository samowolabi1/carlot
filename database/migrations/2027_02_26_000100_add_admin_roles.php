<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The admin team: each LotLink staff member's role in /admin. Admins from before roles existed become owners. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('admin_role', 16)->nullable()->after('role');
            $table->foreignId('invited_by')->nullable()->after('admin_role')->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at')->nullable()->after('invited_by');
        });

        DB::table('users')->where('role', 'admin')->update(['admin_role' => 'owner']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invited_by');
            $table->dropColumn(['admin_role', 'invited_at']);
        });
    }
};
