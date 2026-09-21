<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('subject', 150);
            $table->text('description');
            $table->string('status', 20)->default('open')->index();
            $table->string('priority', 20)->default('medium')->index();
            // A ticket keeps its history: a department or agent that goes away only unlinks.
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            // Accounts with tickets cannot be deleted (deactivate them instead).
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
