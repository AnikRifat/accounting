<?php

use App\Support\Crm;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Services and statuses are the company's own setup and go with it; leads and calls never vanish silently.
        Schema::create('crm_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('crm_statuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10);
            $table->string('name', 60);
            $table->string('tone', 10)->default('neutral');
            // A closed lead status ends follow-ups: such leads leave the call queues.
            $table->boolean('is_closed')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'type', 'name']);
        });

        Schema::create('leads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('name', 150)->nullable();
            $table->string('phone', 20);
            $table->string('email', 150)->nullable();
            $table->string('organization', 150)->nullable();
            $table->string('address')->nullable();
            $table->string('source', 100)->nullable();
            $table->foreignId('crm_service_id')->nullable()->constrained('crm_services');
            $table->foreignId('crm_status_id')->constrained('crm_statuses');
            $table->foreignId('assigned_to')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('next_call_on')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->timestamps();
            // Phones are normalised (see Crm::normalizePhone) so one number is one lead per company.
            $table->unique(['company_id', 'phone']);
            $table->index(['company_id', 'next_call_on']);
        });

        Schema::create('lead_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 10)->default('call');
            $table->dateTime('called_at');
            $table->foreignId('call_status_id')->nullable()->constrained('crm_statuses');
            $table->foreignId('lead_status_id')->constrained('crm_statuses');
            $table->string('summary', 1000)->nullable();
            $table->date('next_call_on')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'called_at']);
            $table->index(['lead_id', 'called_at']);
        });

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            Crm::createDefaults((int) $companyId);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_calls');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('crm_statuses');
        Schema::dropIfExists('crm_services');
    }
};
