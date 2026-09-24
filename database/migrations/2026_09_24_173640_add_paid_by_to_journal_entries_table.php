<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            // The user who paid (expense) or received (income) the money paid now; null when nothing was paid.
            $table->foreignId('paid_by')->nullable()->after('bill_id')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('paid_by');
        });
    }
};
