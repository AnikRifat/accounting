<?php

namespace App\Enums;

use Illuminate\Support\Facades\DB;

/** The accounts the application maintains in every company's chart (`accounts.system_key`). */
enum SystemAccount: string
{
    case Receivable = 'receivable';
    case Payable = 'payable';
    case OpeningEquity = 'opening_equity';
    case VatPayable = 'vat_payable';

    /** @return array{0: string, 1: string, 2: AccountType} default code, name and type */
    public function definition(): array
    {
        return match ($this) {
            self::Receivable => ['1200', 'Accounts Receivable', AccountType::Asset],
            self::Payable => ['2000', 'Accounts Payable', AccountType::Liability],
            self::OpeningEquity => ['3000', 'Opening Balance Equity', AccountType::Equity],
            self::VatPayable => ['2100', 'VAT Payable', AccountType::Liability],
        };
    }

    /** Receivable and payable: the accounts whose lines make up what a bill still owes. */
    public static function dueKeys(): array
    {
        return [self::Receivable->value, self::Payable->value];
    }

    /**
     * Adds this system account to a company that lacks it: an account of the same name is adopted, otherwise the
     * default code (or the next free one after it) is used. Uses the query builder so migrations can call it.
     */
    public function ensureFor(int $companyId): int
    {
        $accounts = DB::table('accounts')->where('company_id', $companyId);
        if ($id = (clone $accounts)->where('system_key', $this->value)->value('id')) {
            return (int) $id;
        }
        [$code, $name, $type] = $this->definition();
        if ($id = (clone $accounts)->where('name', $name)->where('type', $type->value)->value('id')) {
            DB::table('accounts')->where('id', $id)->update(['is_system' => true, 'system_key' => $this->value, 'is_active' => true]);

            return (int) $id;
        }
        $used = (clone $accounts)->pluck('code')->all();
        while (in_array($code, $used, true)) {
            $code = (string) ((int) $code + 1);
        }
        $name = (clone $accounts)->where('name', $name)->exists() ? $name.' (system)' : $name;

        return (int) DB::table('accounts')->insertGetId(['company_id' => $companyId, 'code' => $code, 'name' => $name, 'type' => $type->value,
            'is_cash' => false, 'is_system' => true, 'system_key' => $this->value, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }
}
