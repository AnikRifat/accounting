<?php

namespace App\Support;

use App\Models\ApplicationSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * The admin-editable settings, grouped by module for the Settings page. Each setting is defined once here with its type,
 * default and rules; a value is stored in `application_settings` only while it differs from the default, so a changed
 * default reaches every install that never touched it. Read one with Configuration::get('sales.default_due_days').
 *
 * Field types: text, email, bool, int (empty means none), decimal (kept as a string), select (options; `cast` int or string).
 */
final class Configuration
{
    /** @var array<string, mixed>|null */
    private ?array $stored = null;

    public static function get(string $key): mixed
    {
        return app(self::class)->value($key);
    }

    public function value(string $key): mixed
    {
        $field = self::fields()[$key] ?? throw new InvalidArgumentException("Unknown setting [{$key}].");
        $this->stored ??= ApplicationSetting::query()->whereIn('key', array_keys(self::fields()))->pluck('value', 'key')->all();

        return array_key_exists($key, $this->stored) ? self::cast($field, $this->stored[$key]) : $field['default'];
    }

    /**
     * Stores cast values, removing the row of any value that is back at its default.
     *
     * @param  array<string, mixed>  $values  key => value already cast with cast()
     */
    public function save(array $values): void
    {
        $fields = self::fields();
        DB::transaction(function () use ($values, $fields): void {
            foreach ($values as $key => $value) {
                if ($value === $fields[$key]['default']) {
                    ApplicationSetting::query()->where('key', $key)->delete();
                } else {
                    ApplicationSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
                }
            }
        });
        $this->stored = null;
    }

    /**
     * Tabs of the Settings page: key => [label, emoji, module, groups (label => fields)]. A tab shows while its module is
     * enabled; a field with `available` false (its module is off in config/modules.php) is left out.
     *
     * @return array<string, array{label: string, emoji: string, module: string, groups: array<string, array<string, array<string, mixed>>>}>
     */
    public static function sections(): array
    {
        $hours = collect(range(0, 23))->mapWithKeys(fn (int $hour): array => [$hour => sprintf('%02d:00', $hour)])->all();
        $months = collect(range(1, 12))->mapWithKeys(fn (int $month): array => [$month => CarbonImmutable::create(2000, $month)->format('F')])->all();
        $days = fn (string $label, string $help): array => ['type' => 'int', 'label' => $label, 'help' => $help, 'default' => null, 'rules' => ['min:0', 'max:365']];
        $module = fn (string $key, string $label, string $help): array => ['type' => 'bool', 'label' => $label, 'help' => $help, 'default' => true,
            'available' => (bool) config('modules.'.$key)];

        return [
            'general' => ['label' => __('General'), 'emoji' => '⚙️', 'module' => Modules::ORGANISATION, 'groups' => [
                __('Application') => [
                    'app_name' => ['type' => 'text', 'label' => __('Application name'), 'default' => config('settings.app_name'), 'rules' => ['required', 'max:80']],
                    'support_email' => ['type' => 'email', 'label' => __('Support email'), 'default' => config('settings.support_email'), 'rules' => ['nullable', 'email', 'max:255']],
                    'registration_enabled' => ['type' => 'bool', 'label' => __('Allow public account registration'), 'default' => (bool) config('settings.registration_enabled'),
                        'help' => __('Lets anyone create an API account. Keep it off unless an outside app needs it.')],
                ],
                __('Modules') => [
                    'modules.accounting' => $module(Modules::ACCOUNTING, __('Accounting'), __('Transactions, parties, payment methods and reports. Sales needs it.')),
                    'modules.sales' => $module(Modules::SALES, __('Sales'), __('Quotations, invoices, bills, recurring invoices and sales reports.')),
                    'modules.crm' => $module(Modules::CRM, __('CRM'), __('Leads, calls, follow-ups and CRM reports.')),
                ],
                __('Lists and printing') => [
                    'general.rows_per_page' => ['type' => 'select', 'cast' => 'int', 'label' => __('Rows per page'), 'default' => 25,
                        'options' => [10 => '10', 15 => '15', 25 => '25', 50 => '50', 100 => '100'], 'help' => __('Used by every list with pages.')],
                    'general.export_format' => ['type' => 'select', 'label' => __('Export opens on'), 'default' => 'xlsx',
                        'options' => ['xlsx' => __('Excel'), 'print' => __('PDF / Print')]],
                    'general.print_orientation' => ['type' => 'select', 'label' => __('Print page'), 'default' => 'portrait',
                        'options' => ['portrait' => __('Portrait'), 'landscape' => __('Landscape')]],
                ],
            ]],
            'accounting' => ['label' => __('Accounting'), 'emoji' => '📒', 'module' => Modules::ACCOUNTING, 'groups' => [
                __('Periods') => [
                    'accounting.fiscal_year_start' => ['type' => 'select', 'cast' => 'int', 'label' => __('Financial year starts in'), 'default' => 7, 'options' => $months,
                        'help' => __('Sets "This fiscal year" and "Last fiscal year" in every report.')],
                    'accounting.report_period' => ['type' => 'select', 'label' => __('Reports open on'), 'default' => 'all_time', 'options' => [
                        'all_time' => __('All time'), 'this_month' => __('This month'), 'last_month' => __('Last month'),
                        'this_fiscal_year' => __('This fiscal year'), 'last_fiscal_year' => __('Last fiscal year')], 'help' => __('Sales reports too.')],
                    'accounting.dashboard_months' => ['type' => 'select', 'cast' => 'int', 'label' => __('Dashboard trend shows'), 'default' => 6,
                        'options' => [6 => __('6 months'), 12 => __('12 months')]],
                ],
                __('Transactions') => [
                    'accounting.allow_future_dates' => ['type' => 'bool', 'label' => __('Allow future-dated transactions'), 'default' => true,
                        'help' => __('When off, income, expenses, transfers, payments and posted documents must be dated today or earlier.')],
                    'accounting.default_due_days' => $days(__('Unpaid amounts are due in (days)'),
                        __('Fills the due date of new income and expenses. Leave empty to enter it each time.')),
                ],
            ]],
            'sales' => ['label' => __('Sales'), 'emoji' => '🧾', 'module' => Modules::SALES, 'groups' => [
                __('New documents') => [
                    'sales.default_due_days' => $days(__('Documents are due in (days)'),
                        __('Fills the due or valid-until date from the issue date. Leave empty to enter it each time.')),
                    'sales.default_vat' => ['type' => 'decimal', 'label' => __('VAT on new sales lines (%)'), 'default' => '15',
                        'rules' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'], 'help' => __('Purchase lines start at 0%.')],
                    'sales.tax_inclusive' => ['type' => 'bool', 'label' => __('Prices include VAT'), 'default' => false],
                    'sales.post_to_accounts' => ['type' => 'bool', 'label' => __('Post invoices and bills to the accounts'), 'default' => false,
                        'help' => __('Ticks "Post to accounts" on new invoices and bills for people who can record transactions.')],
                ],
                __('Sharing and automation') => [
                    'sales.share_days' => ['type' => 'select', 'label' => __('Shared links last'), 'default' => '30',
                        'options' => ['7' => __('7 days'), '30' => __('30 days'), '' => __('Until revoked')]],
                    'sales.recurring_enabled' => ['type' => 'bool', 'label' => __('Create recurring invoices automatically'), 'default' => true,
                        'help' => __('Turns due recurring schedules into draft invoices once a day. Needs the cron job.')],
                    'sales.recurring_hour' => ['type' => 'select', 'cast' => 'int', 'label' => __('Create them at'), 'default' => 6, 'options' => $hours],
                ],
            ]],
            'crm' => ['label' => __('CRM'), 'emoji' => '🎯', 'module' => Modules::CRM, 'groups' => [
                __('Leads and calls') => [
                    'crm.assign_to_creator' => ['type' => 'bool', 'label' => __('Assign new leads to the person adding them'), 'default' => true,
                        'help' => __('People who see only their own leads always get the leads they add.')],
                    'crm.follow_up_days' => $days(__('Next call after (days)'), __('Fills the next call date of a new call. Leave empty to choose it each time.')),
                ],
            ]],
        ];
    }

    /** @return array<string, array<string, mixed>> every field by key */
    public static function fields(): array
    {
        return collect(self::sections())->flatMap(fn (array $section): array => array_merge(...array_values($section['groups'])))->all();
    }

    /**
     * Validation rules of one field.
     *
     * @param  array<string, mixed>  $field
     * @return list<mixed>
     */
    public static function rules(array $field): array
    {
        return match ($field['type']) {
            'bool' => ['boolean'],
            'int' => ['nullable', 'integer', ...$field['rules'] ?? []],
            'select' => [$field['default'] === '' || array_key_exists('', $field['options']) ? 'nullable' : 'required', Rule::in(array_map('strval', array_keys($field['options'])))],
            default => $field['rules'] ?? ['nullable', 'string', 'max:255'],
        };
    }

    /** @param array<string, mixed> $field */
    public static function cast(array $field, mixed $value): mixed
    {
        return match (true) {
            $field['type'] === 'bool' => (bool) $value,
            $field['type'] === 'int' => $value === null || $value === '' ? null : (int) $value,
            ($field['cast'] ?? null) === 'int' => (int) $value,
            default => trim((string) $value),
        };
    }
}
