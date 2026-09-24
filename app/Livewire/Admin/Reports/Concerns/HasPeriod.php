<?php

namespace App\Livewire\Admin\Reports\Concerns;

use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;

/**
 * Period presets for reports, All time by default. Presets fill `from`/`to` on every render (All time leaves them empty);
 * editing a date switches to `custom`, where an empty date leaves that end open. The Bangladesh fiscal year runs 1 July – 30 June.
 */
trait HasPeriod
{
    /** Bounds that stand for an open start or end; every posted entry falls between them. */
    public const EARLIEST_DATE = '1900-01-01';

    public const LATEST_DATE = '9999-12-31';

    #[Url]
    public string $period = 'all_time';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function updatedFrom(): void
    {
        $this->period = 'custom';
    }

    public function updatedTo(): void
    {
        $this->period = 'custom';
    }

    /** @return array{0: string, 1: string}|null Y-m-d bounds (inclusive) for a preset, or null for custom or unknown values */
    public static function presetRange(string $preset, CarbonImmutable $today): ?array
    {
        $fiscalStart = $today->month >= 7 ? $today->setDate($today->year, 7, 1) : $today->setDate($today->year - 1, 7, 1);
        if ($preset === 'all_time') {
            return [self::EARLIEST_DATE, self::LATEST_DATE];
        }
        $range = match ($preset) {
            'this_month' => [$today->startOfMonth(), $today->endOfMonth()],
            'last_month' => [$today->startOfMonth()->subMonthNoOverflow(), $today->startOfMonth()->subMonthNoOverflow()->endOfMonth()],
            'this_fiscal_year' => [$fiscalStart, $fiscalStart->addYear()->subDay()],
            'last_fiscal_year' => [$fiscalStart->subYear(), $fiscalStart->subDay()],
            default => null,
        };

        return $range === null ? null : [$range[0]->toDateString(), $range[1]->toDateString()];
    }

    /** @return array<string, string> */
    protected function periodOptions(): array
    {
        return ['all_time' => __('All time'), 'this_month' => __('This month'), 'last_month' => __('Last month'), 'this_fiscal_year' => __('This fiscal year'),
            'last_fiscal_year' => __('Last fiscal year'), 'custom' => __('Custom dates')];
    }

    /**
     * Normalises the period and returns its inclusive bounds, or null (with an error) when custom dates are invalid.
     * Open ends come back as EARLIEST_DATE / LATEST_DATE.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function resolvePeriod(): ?array
    {
        if (! array_key_exists($this->period, $this->periodOptions())) {
            $this->period = 'all_time';
        }
        $this->resetErrorBag('to');
        $preset = self::presetRange($this->period, CarbonImmutable::today());
        if ($preset !== null) {
            [$this->from, $this->to] = $this->period === 'all_time' ? ['', ''] : $preset;

            return $preset;
        }
        if (($this->from !== '' && ! $this->isDate($this->from)) || ($this->to !== '' && ! $this->isDate($this->to))) {
            $this->addError('to', __('Enter valid dates.'));

            return null;
        }
        if ($this->from !== '' && $this->to !== '' && $this->from > $this->to) {
            $this->addError('to', __('The end date must be on or after the start date.'));

            return null;
        }

        return [$this->from === '' ? self::EARLIEST_DATE : $this->from, $this->to === '' ? self::LATEST_DATE : $this->to];
    }

    /** @param array{0: string, 1: string}|null $range */
    protected function periodLabel(?array $range): string
    {
        if ($range === null) {
            return '';
        }
        [$from, $to] = [CarbonImmutable::parse($range[0])->format('d M Y'), CarbonImmutable::parse($range[1])->format('d M Y')];

        return match (true) {
            $range === [self::EARLIEST_DATE, self::LATEST_DATE] => __('All time'),
            $range[0] === self::EARLIEST_DATE => __('Up to :date', ['date' => $to]),
            $range[1] === self::LATEST_DATE => __('From :date', ['date' => $from]),
            default => $from.' – '.$to,
        };
    }

    private function isDate(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
