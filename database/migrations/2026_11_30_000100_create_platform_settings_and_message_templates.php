<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Admin-editable settings: finance rates (TDD M10) and WhatsApp/SMS message templates (TDD notification matrix). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->json('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique(); // the name the code sends, e.g. booking_confirmed
            $table->string('whatsapp_template', 64); // approved Meta template to use (a new version can replace it)
            $table->string('language', 10)->default('en');
            $table->text('sms_text')->nullable(); // null = the wording in the code
            $table->boolean('enabled')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('platform_settings');
    }
};
