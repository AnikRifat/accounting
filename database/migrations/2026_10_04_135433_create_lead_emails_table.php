<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_emails', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('to');
            $table->string('cc', 500)->nullable();
            $table->string('subject', 200);
            $table->text('message');
            $table->string('status', 10);
            $table->string('error', 1000)->nullable();
            $table->dateTime('sent_at');
            $table->timestamps();
            $table->index(['company_id', 'sent_at']);
            $table->index(['lead_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_emails');
    }
};
