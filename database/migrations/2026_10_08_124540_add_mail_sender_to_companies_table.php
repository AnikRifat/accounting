<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A company's own sender for lead and document emails; empty falls back to Settings > Mail.
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('mail_from_address')->nullable()->after('phone');
            $table->string('mail_from_name', 80)->nullable()->after('mail_from_address');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['mail_from_address', 'mail_from_name']);
        });
    }
};
