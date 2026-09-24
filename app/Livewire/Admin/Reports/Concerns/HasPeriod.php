<?php

namespace App\Livewire\Admin\Reports\Concerns;

use Carbon\CarbonImmutable;
use Livewire\Attributes\Url;

/**
 * Period presets for reports. Presets fill `from`/`to` on every render; editing a date switches to `custom`.
 * The Bangladesh fiscal year runs 1 July – 30 June.
 */
trait HasPeriod
{
    #[Url]
    public string $period = 'this_month';

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
        return ['this_month' => __('This month'), 'last_month' => __('Last month'), 'this_fiscal_year' => __('This fiscal year'),
            'last_fiscal_year' => __('Last fiscal year'), 'custom' => __('Custom dates')];
    }

    /**
     * Normalises the period and returns its inclusive bounds, or null (with an error) when custom dates are invalid.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function resolvePeriod(): ?array
    {
        if (! array_key_exists($this->period, $this->periodOptions())) {
            $this->period = 'this_month';
        }
        $this->resetErrorBag('to');
        $preset = self::presetRange($this->period, CarbonImmutable::today());
        if ($preset !== null) {
            [$this->from, $this->to] = $preset;

            return $preset;
        }
        if (! $this->isDate($this->from) || ! $this->isDate($this->to)) {
            $this->addError('to', __('Enter both a start and an end date.'));

            return null;
        }
        if ($this->from > $this->to) {
            $this->addError('to', __('The end date must be on or after the start date.'));

            return null;
        }

        return [$this->from, $this->to];
    }

    /** @return array<string, string> the period as query parameters, e.g. for a link back to the same view */
    protected function periodQuery(): array
    {
        return $this->period === 'custom' ? ['period' => 'custom', 'from' => $this->from, 'to' => $this->to] : ['period' => $this->period];
    }

    /** @param array{0: string, 1: string}|null $range */
    protected function periodLabel(?array $range): string
    {
        return $range === null ? '' : CarbonImmutable::parse($range[0])->format('d M Y').' – '.CarbonImmutable::parse($range[1])->format('d M Y');
    }

    private function isDate(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
