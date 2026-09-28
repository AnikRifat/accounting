<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Sales module: items, templates, numbering, custom fields, documents with their lines, payments and activity,
 * and recurring invoice schedules. Money is BIGINT paisa; quantities are integer thousandths; rates are basis points.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            $table->string('unit', 20)->nullable();
            $table->unsignedBigInteger('price')->default(0);
            $table->unsignedInteger('tax_rate')->default(0);
            // The income category a sale of this item posts to.
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('document_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('layout', 20);
            $table->string('accent_color', 7);
            $table->string('font', 30);
            // Ordered blocks: [{key, visible}], plus free text blocks carrying their own text.
            $table->json('sections');
            $table->string('vat_number', 50)->nullable();
            $table->text('header_text')->nullable();
            $table->text('footer_text')->nullable();
            $table->text('bank_details')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        // Number format and default template per company and document type; the row is locked while numbering.
        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('prefix', 20);
            $table->unsignedInteger('padding')->default(5);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->foreignId('template_id')->nullable()->constrained('document_templates')->nullOnDelete();
            $table->text('default_notes')->nullable();
            $table->text('default_terms')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'type']);
        });

        Schema::create('document_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Null: the field is offered on every document type.
            $table->string('document_type', 20)->nullable();
            $table->string('label', 60);
            $table->string('kind', 10);
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('recurring_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('frequency', 20);
            $table->unsignedTinyInteger('day');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('next_run_on')->nullable();
            $table->date('last_run_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['is_active', 'next_run_on']);
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            // Drafts have no number; it is taken on issue so the sequence has no gaps.
            $table->string('number', 40)->nullable();
            $table->string('status', 20);
            $table->foreignId('party_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('document_templates')->nullOnDelete();
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->string('title', 150)->nullable();
            $table->string('reference', 100)->nullable();
            $table->boolean('tax_inclusive')->default(false);
            $table->string('discount_type', 10)->nullable();
            $table->unsignedBigInteger('discount_value')->default(0);
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount_total')->default(0);
            $table->unsignedBigInteger('tax_total')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->longText('body')->nullable();
            $table->json('custom_values')->nullable();
            $table->boolean('post_to_accounts')->default(false);
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained()->nullOnDelete();
            // The document this one came from: offer → invoice, PO → bill, invoice → delivery or credit note.
            $table->foreignId('source_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->foreignId('recurring_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->date('recurring_period')->nullable();
            $table->string('share_token', 64)->nullable()->unique();
            $table->timestamp('share_expires_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'type', 'number']);
            $table->unique(['recurring_invoice_id', 'recurring_period']);
            $table->index(['company_id', 'type', 'issue_date']);
            $table->index('party_id');
        });

        Schema::table('recurring_invoices', function (Blueprint $table): void {
            // The invoice whose lines, party and terms each run copies.
            $table->foreignId('source_id')->after('company_id')->constrained('documents')->restrictOnDelete();
        });

        Schema::create('document_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            // The income (sales) or expense (purchases) category the line posts to.
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 500);
            $table->unsignedBigInteger('quantity');
            $table->string('unit', 20)->nullable();
            $table->unsignedBigInteger('unit_price');
            $table->string('discount_type', 10)->nullable();
            $table->unsignedBigInteger('discount_value')->default(0);
            $table->unsignedInteger('tax_rate')->default(0);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('discount');
            $table->unsignedBigInteger('net');
            $table->unsignedBigInteger('tax');
            $table->unsignedBigInteger('total');
            $table->unsignedInteger('sort')->default(0);
        });

        // Payments on a document that is not posted to the books; a posted document's payments are ledger settlements.
        Schema::create('document_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->date('paid_on');
            $table->unsignedBigInteger('amount');
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 100)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('document_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 30);
            $table->string('details', 500)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_activities');
        Schema::dropIfExists('document_payments');
        Schema::dropIfExists('document_lines');
        Schema::table('recurring_invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_id');
        });
        Schema::dropIfExists('documents');
        Schema::dropIfExists('recurring_invoices');
        Schema::dropIfExists('document_fields');
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('document_templates');
        Schema::dropIfExists('items');
    }
};
