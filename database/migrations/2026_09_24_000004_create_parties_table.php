<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index()->constrained();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('phone', 40)->nullable();
            $table->string('address')->nullable();
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            // One party per employee (user) per company; custom parties have no user.
            $table->unique(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};
