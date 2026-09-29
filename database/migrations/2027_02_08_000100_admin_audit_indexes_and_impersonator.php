<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            // Who was really acting during "Log in as" (user_id is the account they were using).
            $table->foreignId('impersonator_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            // The admin audit log lists newest first and filters by action.
            $table->index('created_at');
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('impersonator_id');
            $table->dropIndex(['created_at']);
            $table->dropIndex(['action', 'created_at']);
        });
    }
};
