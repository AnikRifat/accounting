<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Every user is an employee: staff details live on the login account. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('employee_code', 30)->nullable()->unique();
            $table->string('designation')->nullable();
            $table->string('department')->nullable();
            $table->string('phone', 40)->nullable();
            $table->bigInteger('monthly_salary')->default(0);
            $table->date('joined_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['employee_code']);
            $table->dropColumn(['employee_code', 'designation', 'department', 'phone', 'monthly_salary', 'joined_on']);
        });
    }
};
