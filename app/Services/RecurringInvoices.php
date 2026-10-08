<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\RecurringFrequency;
use App\Models\Company;
use App\Models\Document;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Support\Modules;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Recurring invoice schedules and the generator the daily cron (and "Generate due now") runs. Each run copies the
 * schedule's source invoice into one draft per due period through DocumentService, as the schedule's creator.
 * Drafts are never issued here (decision D3). A period is generated at most once: the schedule row is locked while
 * it runs and documents are unique per (recurring_invoice_id, recurring_period).
 */
class RecurringInvoices
{
    /** Most periods one schedule catches up in a single run. */
    public const MAX_PERIODS_PER_RUN = 12;

    public function __construct(private readonly DocumentService $documents) {}

    /**
     * Creates or edits a schedule. Data: source_id, name, frequency, day (1–31), starts_on, ends_on?, is_active.
     * A new schedule, or one whose timing changed or that is switched back on, gets its next run recomputed.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException|AuthorizationException
     */
    public function save(?RecurringInvoice $schedule, Company $company, array $data, User $actor): RecurringInvoice
    {
        Gate::forUser($actor)->authorize('sales.update');
        if (! $actor->canAccessCompany($company->id, Modules::SALES)) {
            throw new AuthorizationException(__('You do not have access to this company.'));
        }
        if ($schedule && $schedule->company_id !== $company->id) {
            throw new LogicException('A schedule keeps its company.');
        }

        $data = Validator::make($data, [
            'source_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:100'],
            'frequency' => ['required', Rule::enum(RecurringFrequency::class)],
            'day' => ['required', 'integer', 'min:1', 'max:31'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'is_active' => ['boolean'],
        ], [], ['source_id' => __('invoice'), 'starts_on' => __('start date'), 'ends_on' => __('end date')])->validate();

        return DB::transaction(function () use ($schedule, $company, $data, $actor): RecurringInvoice {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);
            if (! $company->is_active) {
                throw ValidationException::withMessages(['company' => __('This company is inactive and does not accept new documents.')]);
            }
            $source = Document::query()->whereKey($data['source_id'])->where('company_id', $company->id)->where('type', DocumentType::Invoice)
                ->whereIn('status', [DocumentStatus::Draft, DocumentStatus::Issued])->first();
            if ($source === null) {
                throw ValidationException::withMessages(['source_id' => __('Choose a draft or issued invoice of this company.')]);
            }
            $schedule = $schedule ? RecurringInvoice::query()->lockForUpdate()->findOrFail($schedule->id) : new RecurringInvoice;
            $frequency = RecurringFrequency::from($data['frequency']);
            $startsOn = CarbonImmutable::parse($data['starts_on']);
            $active = (bool) ($data['is_active'] ?? true);
            $retimed = ! $schedule->exists || $schedule->next_run_on === null || $schedule->frequency !== $frequency
                || $schedule->day !== (int) $data['day'] || $schedule->starts_on->toDateString() !== $startsOn->toDateString()
                || ($active && ! $schedule->is_active);

            $schedule->forceFill([
                'company_id' => $company->id, 'source_id' => $source->id, 'name' => trim($data['name']), 'frequency' => $frequency,
                'day' => (int) $data['day'], 'starts_on' => $startsOn->toDateString(), 'ends_on' => $data['ends_on'] ?? null, 'is_active' => $active,
            ]);
            if ($retimed) {
                // A new schedule starts on its start date (earlier periods are caught up); an edited one from today.
                $from = $schedule->exists ? $startsOn->max(CarbonImmutable::today()) : $startsOn;
                $schedule->next_run_on = self::firstRun($frequency, (int) $data['day'], $startsOn, $from);
            }
            if (! $schedule->exists) {
                $schedule->created_by = $actor->id;
            }
            $schedule->save();

            return $schedule;
        });
    }

    /**
     * The first run date on or after $from: weekly schedules repeat every 7 days from their start date; month-based
     * ones fall on their day of the month, clamped to short months.
     */
    public static function firstRun(RecurringFrequency $frequency, int $day, CarbonImmutable $startsOn, ?CarbonImmutable $from = null): CarbonImmutable
    {
        $from = ($from ?? $startsOn)->startOfDay();
        $startsOn = $startsOn->startOfDay();
        if ($frequency === RecurringFrequency::Weekly) {
            if ($from->lte($startsOn)) {
                return $startsOn;
            }
            $weeks = (int) ceil($startsOn->diffInDays($from) / 7);

            return $startsOn->addDays($weeks * 7);
        }
        $month = $from->startOfMonth();
        $candidate = $month->setDay(min($day, $month->daysInMonth));
        if ($candidate->lt($from)) {
            $month = $month->addMonthNoOverflow();
            $candidate = $month->setDay(min($day, $month->daysInMonth));
        }

        return $candidate;
    }

    /**
     * Generates the drafts of every active schedule due on or before $today in companies that use Sales, optionally only
     * for some companies.
     *
     * @param  list<int>|null  $companyIds
     * @return array{created: int, documents: list<int>, skipped: list<array{schedule: string, reason: string}>}
     */
    public function run(?CarbonInterface $today = null, ?array $companyIds = null): array
    {
        $today = CarbonImmutable::parse(($today ?? CarbonImmutable::today())->toDateString());
        $summary = ['created' => 0, 'documents' => [], 'skipped' => []];
        $due = RecurringInvoice::query()->where('is_active', true)->whereNotNull('next_run_on')->where('next_run_on', '<=', $today->toDateString())
            ->whereIn('company_id', Company::query()->usingModule(Modules::SALES)->select('id'))
            ->when($companyIds !== null, fn ($query) => $query->whereIn('company_id', $companyIds))
            ->orderBy('next_run_on')->orderBy('id')->pluck('name', 'id');

        foreach ($due as $scheduleId => $name) {
            try {
                $created = DB::transaction(fn (): array => $this->runSchedule((int) $scheduleId, $today));
                $summary['created'] += count($created);
                array_push($summary['documents'], ...$created);
            } catch (ValidationException $exception) {
                $summary['skipped'][] = ['schedule' => $name, 'reason' => collect($exception->errors())->flatten()->first() ?? $exception->getMessage()];
            } catch (AuthorizationException $exception) {
                $summary['skipped'][] = ['schedule' => $name, 'reason' => __('Its creator can no longer create invoices in this company.')];
            } catch (UniqueConstraintViolationException) {
                $summary['skipped'][] = ['schedule' => $name, 'reason' => __('Another run generated it at the same time.')];
            }
        }

        return $summary;
    }

    /**
     * Generates the due periods of one schedule, oldest first, inside the caller's transaction.
     *
     * @return list<int> ids of the created drafts
     *
     * @throws ValidationException|AuthorizationException
     */
    private function runSchedule(int $scheduleId, CarbonImmutable $today): array
    {
        // Company row first, then the schedule: the same order as save() and DocumentService, so concurrent writers can't deadlock.
        $companyId = RecurringInvoice::query()->whereKey($scheduleId)->value('company_id');
        $company = $companyId ? Company::query()->lockForUpdate()->find($companyId) : null;
        $schedule = RecurringInvoice::query()->lockForUpdate()->find($scheduleId);
        if ($company === null || $schedule === null || ! $schedule->is_active || $schedule->next_run_on === null || $schedule->next_run_on->gt($today)) {
            return [];
        }
        if (! $company->is_active) {
            throw ValidationException::withMessages(['company' => __('The company is inactive.')]);
        }
        $creator = User::query()->find($schedule->created_by);
        if ($creator === null || ! $creator->is_active) {
            throw ValidationException::withMessages(['created_by' => __('Its creator is inactive.')]);
        }
        if (! $creator->canAccessCompany($company->id, Modules::SALES)) {
            throw new AuthorizationException;
        }
        $source = Document::query()->with('lines')->findOrFail($schedule->source_id);
        if ($source->isVoid()) {
            throw ValidationException::withMessages(['source_id' => __('Its invoice :number is void.', ['number' => $source->displayNumber()])]);
        }

        $gap = $source->due_date ? (int) $source->issue_date->diffInDays($source->due_date) : null;
        $period = CarbonImmutable::parse($schedule->next_run_on->toDateString());
        $endsOn = $schedule->ends_on ? CarbonImmutable::parse($schedule->ends_on->toDateString()) : null;
        $created = [];
        $lastRun = $schedule->last_run_on;
        for ($runs = 0; $runs < self::MAX_PERIODS_PER_RUN && $period->lte($today) && ($endsOn === null || $period->lte($endsOn)); $runs++) {
            $exists = Document::query()->where('recurring_invoice_id', $schedule->id)->where('recurring_period', $period->toDateString())->exists();
            if (! $exists) {
                $document = $this->documents->save(null, $company, DocumentType::Invoice, $this->copyOf($source, $period, $gap), $creator);
                $document->forceFill(['recurring_invoice_id' => $schedule->id, 'recurring_period' => $period->toDateString()])->save();
                $created[] = $document->id;
            }
            $lastRun = $period;
            $period = $schedule->frequency->next($period, $schedule->day);
        }
        $schedule->forceFill([
            'next_run_on' => $period->toDateString(),
            'last_run_on' => $lastRun?->toDateString(),
            'is_active' => $endsOn === null || $period->lte($endsOn),
        ])->save();

        return $created;
    }

    /**
     * The source invoice as DocumentService::save() data, dated for one period.
     *
     * @return array<string, mixed>
     */
    private function copyOf(Document $source, CarbonImmutable $period, ?int $gap): array
    {
        return [
            'party_id' => $source->party_id, 'issue_date' => $period->toDateString(), 'due_date' => $gap === null ? null : $period->addDays($gap)->toDateString(),
            'title' => $source->title, 'template_id' => $source->template_id, 'tax_inclusive' => $source->tax_inclusive,
            'discount_type' => $source->discount_type, 'discount_value' => $source->discount_value, 'notes' => $source->notes, 'terms' => $source->terms,
            'custom_values' => $source->custom_values ?? [], 'post_to_accounts' => $source->post_to_accounts,
            'lines' => $source->lines->map(fn ($line): array => $line->only(['item_id', 'account_id', 'description', 'quantity', 'unit',
                'unit_price', 'discount_type', 'discount_value', 'tax_rate']))->values()->all(),
        ];
    }
}
