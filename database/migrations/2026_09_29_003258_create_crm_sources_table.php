<?php

use App\Support\Crm;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Lead sources become a managed list per company; the free-text sources already typed move into it. */
    public function up(): void
    {
        Schema::create('crm_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });
        Schema::table('leads', function (Blueprint $table): void {
            $table->foreignId('crm_source_id')->nullable()->after('crm_service_id')->constrained('crm_sources');
        });

        $now = now();
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            Crm::createDefaultSources((int) $companyId);
            $known = DB::table('crm_sources')->where('company_id', $companyId)->pluck('id', 'name')
                ->mapWithKeys(fn (mixed $id, string $name): array => [mb_strtolower($name) => (int) $id]);
            $typed = DB::table('leads')->where('company_id', $companyId)->whereNotNull('source')->where('source', '!=', '')->distinct()->pluck('source');
            foreach ($typed as $source) {
                $key = mb_strtolower(trim($source));
                if (! $known->has($key)) {
                    $known[$key] = (int) DB::table('crm_sources')->insertGetId(['company_id' => $companyId, 'name' => mb_substr(trim($source), 0, 100),
                        'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
                }
                DB::table('leads')->where('company_id', $companyId)->where('source', $source)->update(['crm_source_id' => $known[$key]]);
            }
        }

        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn('source');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->string('source', 100)->nullable()->after('address');
        });
        foreach (DB::table('crm_sources')->get(['id', 'name']) as $source) {
            DB::table('leads')->where('crm_source_id', $source->id)->update(['source' => $source->name]);
        }
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('crm_source_id');
        });
        Schema::dropIfExists('crm_sources');
    }
};
