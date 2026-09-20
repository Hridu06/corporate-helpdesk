<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('description', 500)->nullable();
            // Departments are deactivated rather than deleted once they are in use.
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        // Staff (agents / admins) belonging to a department.
        Schema::create('department_user', function (Blueprint $table) {
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['department_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_user');
        Schema::dropIfExists('departments');
    }
};
