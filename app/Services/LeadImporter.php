<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CrmService;
use App\Models\CrmSource;
use App\Models\CrmStatus;
use App\Models\Lead;
use App\Support\Crm;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Imports leads from the first sheet of a CSV or XLSX file into one company. The first row names the columns
 * (see COLUMNS for the accepted headers; only Phone is required). Rows whose phone is invalid are skipped and
 * reported; numbers already in the company, or repeated in the file, are skipped as duplicates. Service, source
 * and status cells match the company's active names, ignoring case, and fall back to the chosen defaults.
 */
class LeadImporter
{
    public const MAX_ROWS = 5000;

    /** Field => accepted header spellings (lower case). */
    public const COLUMNS = [
        'name' => ['name', 'lead name', 'customer name', 'full name'],
        'phone' => ['phone', 'mobile', 'phone number', 'mobile number', 'contact', 'contact number'],
        'email' => ['email', 'e-mail', 'email address'],
        'organization' => ['organization', 'organisation', 'company', 'business'],
        'address' => ['address'],
        'source' => ['source', 'lead source'],
        'service' => ['service', 'interest', 'product'],
        'status' => ['status', 'lead status'],
        'next_call_on' => ['next call', 'next call date', 'next_call_on', 'follow up', 'follow-up'],
        'notes' => ['notes', 'note', 'remarks', 'comment', 'comments'],
    ];

    /**
     * @return array{created: int, duplicates: int, invalid: list<int>} invalid holds the spreadsheet row numbers
     */
    public function import(string $path, string $extension, Company $company, int $defaultStatusId, ?int $defaultServiceId, ?int $defaultSourceId, ?int $assignTo, int $actorId): array
    {
        $rows = $this->rows($path, $extension);
        $header = array_shift($rows) ?? [];
        $columns = $this->mapHeader($header);
        if (! isset($columns['phone'])) {
            throw ValidationException::withMessages(['file' => __('The first row must name the columns and include a Phone column.')]);
        }
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => __('Import at most :max rows at a time.', ['max' => self::MAX_ROWS])]);
        }

        $known = Lead::query()->where('company_id', $company->id)->pluck('phone')->flip()->all();
        $services = CrmService::query()->where('company_id', $company->id)->where('is_active', true)->pluck('id', 'name')
            ->mapWithKeys(fn (mixed $id, string $name): array => [mb_strtolower($name) => (int) $id])->all();
        $sources = CrmSource::query()->where('company_id', $company->id)->where('is_active', true)->pluck('id', 'name')
            ->mapWithKeys(fn (mixed $id, string $name): array => [mb_strtolower($name) => (int) $id])->all();
        $statuses = CrmStatus::query()->where('company_id', $company->id)->lead()->where('is_active', true)->get(['id', 'name', 'is_closed'])
            ->keyBy(fn (CrmStatus $status): string => mb_strtolower($status->name));
        $closedIds = $statuses->where('is_closed', true)->pluck('id')->all();
        $defaultClosed = (bool) CrmStatus::query()->whereKey($defaultStatusId)->value('is_closed');

        [$records, $duplicates, $invalid, $now] = [[], 0, [], now()];
        foreach ($rows as $index => $row) {
            $cell = fn (string $field): string => isset($columns[$field]) ? $this->text($row[$columns[$field]] ?? null) : '';
            if (implode('', array_map(fn (mixed $value): string => $this->text($value), $row)) === '') {
                continue;
            }
            $phone = Crm::normalizePhone($cell('phone'));
            if (! preg_match('/^\+?\d{6,15}$/', $phone)) {
                $invalid[] = $index + 2;

                continue;
            }
            if (isset($known[$phone])) {
                $duplicates++;

                continue;
            }
            $known[$phone] = true;
            $status = $statuses->get(mb_strtolower($cell('status')));
            $statusId = $status?->id ?? $defaultStatusId;
            $closed = $status ? in_array($status->id, $closedIds, true) : $defaultClosed;
            $email = $cell('email');
            $records[] = [
                'company_id' => $company->id, 'phone' => $phone,
                'name' => mb_substr($cell('name'), 0, 150) ?: null, 'email' => filter_var($email, FILTER_VALIDATE_EMAIL) && mb_strlen($email) <= 150 ? $email : null,
                'organization' => mb_substr($cell('organization'), 0, 150) ?: null, 'address' => mb_substr($cell('address'), 0, 255) ?: null,
                'crm_source_id' => $sources[mb_strtolower($cell('source'))] ?? $defaultSourceId, 'notes' => mb_substr($cell('notes'), 0, 1000) ?: null,
                'crm_service_id' => $services[mb_strtolower($cell('service'))] ?? $defaultServiceId, 'crm_status_id' => $statusId,
                'next_call_on' => $closed ? null : $this->date(isset($columns['next_call_on']) ? ($row[$columns['next_call_on']] ?? null) : null),
                'assigned_to' => $assignTo, 'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::transaction(function () use ($records): void {
            foreach (array_chunk($records, 500) as $chunk) {
                DB::table('leads')->insert($chunk);
            }
        });

        return ['created' => count($records), 'duplicates' => $duplicates, 'invalid' => $invalid];
    }

    /** @return list<list<mixed>> every row of the first sheet */
    private function rows(string $path, string $extension): array
    {
        $reader = strtolower($extension) === 'xlsx' ? new XlsxReader : new CsvReader;
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                $rows = [];
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                    if (count($rows) > self::MAX_ROWS + 1) {
                        break;
                    }
                }

                return $rows;
            }
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => __('The file could not be read. Upload a CSV or Excel (.xlsx) file.')]);
        } finally {
            $reader->close();
        }

        return [];
    }

    /**
     * @param  list<mixed>  $header
     * @return array<string, int> field => column index
     */
    private function mapHeader(array $header): array
    {
        $columns = [];
        foreach ($header as $index => $title) {
            $title = mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $this->text($title)) ?? ''));
            foreach (self::COLUMNS as $field => $aliases) {
                if (! isset($columns[$field]) && in_array($title, $aliases, true)) {
                    $columns[$field] = $index;
                }
            }
        }

        return $columns;
    }

    private function text(mixed $value): string
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_float($value) && floor($value) === $value => (string) (int) $value,
            is_scalar($value) => trim((string) $value),
            default => '',
        };
    }

    /** A date cell as Y-m-d: an Excel date, or text as 2026-10-01, 01/10/2026, 01-10-2026 or 01.10.2026 (day first). */
    private function date(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }
        $text = $this->text($value);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $text);
            } catch (Throwable) {
                continue;
            }
            if ($date && $date->format($format) === $text) {
                return $date->toDateString();
            }
        }

        return null;
    }
}
