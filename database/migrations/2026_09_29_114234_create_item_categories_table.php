<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Items get an optional category from a managed list per company (Services, Hardware…). */
    public function up(): void
    {
        Schema::create('item_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });
        Schema::table('items', function (Blueprint $table): void {
            $table->foreignId('item_category_id')->nullable()->after('company_id')->constrained('item_categories');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('item_category_id');
        });
        Schema::dropIfExists('item_categories');
    }
};
