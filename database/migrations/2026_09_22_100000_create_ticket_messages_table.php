<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The conversation on a ticket: public replies and staff-only internal notes.
        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            // Kept if the author account is later removed.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // public_reply | internal_note
            $table->text('body');
            $table->timestamps();

            $table->index(['ticket_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_messages');
    }
};
