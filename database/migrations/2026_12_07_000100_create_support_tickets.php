<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Support desk: lots open tickets with LotLink and message the team; admins answer in /admin. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('reference', 12)->unique(); // shown to people, e.g. T-7K3Q9D
            $table->foreignId('lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // who opened it
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject', 160);
            $table->string('category', 20);
            $table->string('priority', 10)->default('normal');
            $table->string('status', 12)->default('open'); // open (with LotLink), pending (with the lot), resolved, closed
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('lot_read_at')->nullable();
            $table->timestamp('admin_read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_message_at']);
            $table->index(['lot_id', 'status']);
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('from_admin')->default(false);
            $table->boolean('internal')->default(false); // admin-only notes, never shown to the lot
            $table->text('body');
            $table->string('attachment_path')->nullable(); // private `local` disk
            $table->string('attachment_name', 160)->nullable();
            $table->timestamps();

            $table->index(['support_ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
    }
};
