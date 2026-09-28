<?php

namespace App\Support;

use App\Enums\CrmStatusType;
use App\Models\CrmService;
use App\Models\CrmSource;
use App\Models\CrmStatus;
use App\Models\Lead;
use App\Models\LeadCall;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Shared CRM rules: default statuses, phone normalisation and who a lead can be assigned to. */
final class Crm
{
    /** Badge tones a status can use (see x-badge). */
    public const TONES = ['neutral', 'info', 'primary', 'success', 'warning', 'danger'];

    /**
     * Statuses every new company starts with: [type, name, tone, closed].
     *
     * @var list<array{0: string, 1: string, 2: string, 3: bool}>
     */
    public const DEFAULT_STATUSES = [
        ['lead', 'New', 'info', false], ['lead', 'Contacted', 'primary', false], ['lead', 'Qualified', 'primary', false],
        ['lead', 'Proposal sent', 'warning', false], ['lead', 'Negotiation', 'warning', false],
        ['lead', 'Closed won', 'success', true], ['lead', 'Closed lost', 'danger', true],
        ['call', 'Connected', 'success', false], ['call', 'Busy', 'warning', false],
        ['call', 'No response', 'neutral', false], ['call', 'Switched off', 'danger', false],
    ];

    /** Lead sources every new company starts with; each company edits its own list. */
    public const DEFAULT_SOURCES = ['Facebook', 'Website', 'Referral', 'Walk-in', 'Phone call'];

    /** Adds the default lead sources to a company that has none yet. Uses the query builder so migrations can call it. */
    public static function createDefaultSources(int $companyId): void
    {
        if (DB::table('crm_sources')->where('company_id', $companyId)->exists()) {
            return;
        }
        $now = now();
        DB::table('crm_sources')->insert(array_map(fn (string $name): array => ['company_id' => $companyId, 'name' => $name,
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now], self::DEFAULT_SOURCES));
    }

    /**
     * Source names of the companies, for filters and reports.
     *
     * @param  list<int>  $companyIds
     * @return array<string, string>
     */
    public static function sourceOptions(array $companyIds): array
    {
        return CrmSource::query()->whereIn('company_id', $companyIds)->orderBy('name')->pluck('name')
            ->unique()->mapWithKeys(fn (string $name): array => [$name => $name])->all();
    }

    /** Adds the default statuses to a company that has none yet. Uses the query builder so migrations can call it. */
    public static function createDefaults(int $companyId): void
    {
        if (DB::table('crm_statuses')->where('company_id', $companyId)->exists()) {
            return;
        }
        $now = now();
        DB::table('crm_statuses')->insert(array_map(fn (array $status, int $position): array => [
            'company_id' => $companyId, 'type' => $status[0], 'name' => $status[1], 'tone' => $status[2], 'is_closed' => $status[3],
            'position' => $position, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ], self::DEFAULT_STATUSES, array_keys(self::DEFAULT_STATUSES)));
    }

    /**
     * One spelling per number, so duplicates are caught: spaces, dashes, dots and brackets go, and a
     * Bangladeshi mobile number with the country code (+880 / 880), or without its leading 0 as
     * spreadsheets store it, becomes its local 0-prefixed form.
     */
    public static function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[\s\-().]/', '', trim($phone)) ?? '';
        if (preg_match('/^(?:\+?880)?(1[3-9]\d{8})$/', $phone, $match)) {
            return '0'.$match[1];
        }

        return $phone;
    }

    /** A plain Y-m-d (or null), so date comparisons behave the same on SQLite and MySQL. */
    public static function dateString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : Carbon::parse($value)->toDateString();
    }

    /** The first active lead status of the company: where new and imported leads start. */
    public static function defaultLeadStatusId(int $companyId): ?int
    {
        $id = CrmStatus::query()->where('company_id', $companyId)->lead()->where('is_active', true)->orderBy('position')->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Service names of the companies, for filters. Filters match by name so "All companies" merges the lists.
     *
     * @param  list<int>  $companyIds
     * @return array<string, string>
     */
    public static function serviceOptions(array $companyIds): array
    {
        return CrmService::query()->whereIn('company_id', $companyIds)->orderBy('name')->pluck('name')
            ->unique()->mapWithKeys(fn (string $name): array => [$name => $name])->all();
    }

    /**
     * Status names of one type, in status order, for filters.
     *
     * @param  list<int>  $companyIds
     * @return array<string, string>
     */
    public static function statusOptions(array $companyIds, CrmStatusType $type): array
    {
        return CrmStatus::query()->whereIn('company_id', $companyIds)->where('type', $type->value)->orderBy('position')->orderBy('name')->pluck('name')
            ->unique()->mapWithKeys(fn (string $name): array => [$name => $name])->all();
    }

    /**
     * People for the "assigned to" and "logged by" filters: whoever holds leads or logged calls in the companies,
     * and every active CRM user who can access one of them.
     *
     * @param  list<int>  $companyIds
     * @return array<int, string>
     */
    public static function peopleOptions(array $companyIds): array
    {
        $active = User::query()->where('role', '!=', Permissions::ROOT_ROLE)->where('is_active', true)->with('companies:id')->get()
            ->filter(fn (User $user): bool => $user->hasPermission('crm.view')
                && ($user->hasPermission('companies.all') || $user->companies->pluck('id')->intersect($companyIds)->isNotEmpty()))
            ->pluck('id');
        $involved = Lead::query()->whereIn('company_id', $companyIds)->whereNotNull('assigned_to')->distinct()->pluck('assigned_to')
            ->merge(LeadCall::query()->whereIn('company_id', $companyIds)->distinct()->pluck('user_id'));

        return User::query()->whereKey($active->merge($involved)->unique()->all())->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Active employees who work in the CRM and can access the company: the people a lead can be assigned to.
     * The super admin is not an employee and is never assigned.
     *
     * @return Collection<int, User>
     */
    public static function assignableUsers(int $companyId): Collection
    {
        return User::query()->where('role', '!=', Permissions::ROOT_ROLE)->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (User $user): bool => $user->hasPermission('crm.view') && $user->canAccessCompany($companyId))->values();
    }
}
