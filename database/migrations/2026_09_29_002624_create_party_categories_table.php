<?php

use App\Models\PartyCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A company's own labels for grouping parties (Customer, Supplier, Landlord…).
        Schema::create('party_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            // The built-in Employee category: kept on employee parties by the application, never edited by hand.
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        // Deleting a category leaves its parties uncategorised.
        Schema::table('parties', function (Blueprint $table): void {
            $table->foreignId('party_category_id')->nullable()->after('company_id')->constrained('party_categories')->nullOnDelete();
        });

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $categoryId = PartyCategory::employeeCategoryId((int) $companyId);
            DB::table('parties')->where('company_id', $companyId)->whereNotNull('user_id')->update(['party_category_id' => $categoryId]);
        }
    }

    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('party_category_id');
        });
        Schema::dropIfExists('party_categories');
    }
};
