<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_activities', function (Blueprint $table) {
            $table->id();
            // Nullable + nullOnDelete: history survives if the account is later deleted.
            // Attempts against unknown emails are deliberately never recorded.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Links a row to the session's API token so logout can be recorded. No FK: tokens get deleted.
            $table->unsignedBigInteger('token_id')->nullable()->index();
            $table->string('status', 16);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('login_at');
            $table->timestamp('logout_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'login_at']);
            $table->index('status');
            $table->index('login_at'); // retention pruning and date filters
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_activities');
    }
};
