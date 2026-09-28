<?php

use App\Enums\AccountType;
use App\Enums\SystemAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which system account a row is (receivable, payable, opening equity, VAT payable), so the ledger no
        // longer has to assume there is only one system account per type.
        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('system_key', 30)->nullable()->after('is_system');
            $table->unique(['company_id', 'system_key']);
        });
        Schema::table('parties', function (Blueprint $table): void {
            $table->string('email', 150)->nullable()->after('phone');
        });

        $keys = [AccountType::Asset->value => SystemAccount::Receivable, AccountType::Liability->value => SystemAccount::Payable,
            AccountType::Equity->value => SystemAccount::OpeningEquity];
        foreach ($keys as $type => $key) {
            DB::table('accounts')->where('is_system', true)->where('type', $type)->update(['system_key' => $key->value]);
        }
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            SystemAccount::VatPayable->ensureFor((int) $companyId);
        }
    }

    public function down(): void
    {
        DB::table('accounts')->where('system_key', SystemAccount::VatPayable->value)
            ->whereNotExists(fn ($lines) => $lines->from('journal_lines')->whereColumn('journal_lines.account_id', 'accounts.id'))->delete();
        Schema::table('parties', function (Blueprint $table): void {
            $table->dropColumn('email');
        });
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'system_key']);
            $table->dropColumn('system_key');
        });
    }
};
