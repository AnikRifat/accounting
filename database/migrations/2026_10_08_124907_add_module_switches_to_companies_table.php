<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which of the switchable modules a company uses; existing companies keep everything on.
        Schema::table('companies', function (Blueprint $table): void {
            $table->boolean('sales_enabled')->default(true)->after('is_active');
            $table->boolean('crm_enabled')->default(true)->after('sales_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['sales_enabled', 'crm_enabled']);
        });
    }
};
