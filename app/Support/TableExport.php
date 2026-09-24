<?php

namespace App\Support;

use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * One definition of a list's columns that turns its rows into an Excel workbook or a print page.
 * Column types: text, money (integer paisa), date, number.
 */
final class TableExport
{
    /** Print pages are built in memory and kept briefly in the cache, so they are capped. */
    public const PRINT_LIMIT = 2000;

    /** How long a prepared print page stays available. */
    private const PRINT_TTL_MINUTES = 10;

    /**
     * @param  array<string, array{label: string, value: Closure(mixed): mixed, type?: string}>  $columns
     */
    public function __construct(public string $title, public array $columns, public string $subtitle = '') {}

    /**
     * Keeps the requested columns in the definition's order; unknown keys are ignored and none means all.
     *
     * @param  list<string>  $keys
     */
    public function only(array $keys): self
    {
        $picked = array_intersect_key($this->columns, array_flip($keys));

        return new self($this->title, $picked === [] ? $this->columns : $picked, $this->subtitle);
    }

    /** @return array<string, string> column key → label, for the export and print options */
    public function labels(): array
    {
        return array_map(fn (array $column): string => $column['label'], $this->columns);
    }

    public function filename(string $extension): string
    {
        return Str::slug($this->title).'-'.now()->format('Y-m-d-His').'.'.$extension;
    }

    /**
     * Writes the rows to a temporary .xlsx file and returns its path. Money becomes a numeric taka cell
     * (paisa / 100) so totals work in the spreadsheet; the ledger itself never leaves integer paisa.
     *
     * @param  iterable<mixed>  $records
     */
    public function toXlsx(iterable $records): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $options = new Options;
        foreach (array_values($this->columns) as $index => $column) {
            $options->setColumnWidth(match ($column['type'] ?? 'text') {
                'money' => 16, 'date' => 13, 'number' => 10, default => 28,
            }, $index + 1);
        }
        $writer = new Writer($options);
        $writer->openToFile($path);
        $bold = (new Style)->withFontBold(true);
        $writer->addRow(new Row([Cell::fromValue($this->title, $bold)]));
        if ($this->subtitle !== '') {
            $writer->addRow(Row::fromValues([$this->subtitle]));
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValuesWithStyle(array_values($this->labels()), $bold->withBackgroundColor('F1F5F9')));
        $money = (new Style)->withFormat('#,##0.00');
        $date = (new Style)->withFormat('dd mmm yyyy');
        foreach ($records as $record) {
            $cells = [];
            foreach ($this->columns as $column) {
                $value = ($column['value'])($record);
                $cells[] = match ($column['type'] ?? 'text') {
                    'money' => $value === null ? Cell::fromValue(null) : Cell::fromValue(((int) $value) / 100, $money),
                    'date' => $value instanceof DateTimeInterface ? Cell::fromValue($value, $date) : Cell::fromValue($value === null ? null : (string) $value),
                    'number' => Cell::fromValue($value === null ? null : (int) $value),
                    default => Cell::fromValue($value === null ? null : (string) $value),
                };
            }
            $writer->addRow(new Row($cells));
        }
        $writer->close();

        return $path;
    }

    /**
     * Formats the rows for printing, stores them for the current user and returns the token of the print page.
     *
     * @param  iterable<mixed>  $records
     */
    public function toPrint(iterable $records, string $orientation = 'portrait'): string
    {
        $rows = [];
        foreach ($records as $record) {
            $rows[] = array_map(fn (array $column): string => $this->display(($column['value'])($record), $column['type'] ?? 'text'), array_values($this->columns));
            if (count($rows) >= self::PRINT_LIMIT) {
                break;
            }
        }
        $token = Str::random(40);
        Cache::put('table-print:'.$token, [
            'user_id' => auth()->id(),
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'columns' => array_values(array_map(fn (array $column): array => ['label' => $column['label'], 'type' => $column['type'] ?? 'text'], $this->columns)),
            'rows' => $rows,
            'orientation' => $orientation === 'landscape' ? 'landscape' : 'portrait',
            'truncated' => count($rows) >= self::PRINT_LIMIT,
        ], now()->addMinutes(self::PRINT_TTL_MINUTES));

        return $token;
    }

    private function display(mixed $value, string $type): string
    {
        return match (true) {
            $value === null || $value === '' => '—',
            $type === 'money' => Money::format((int) $value),
            $value instanceof DateTimeInterface => $value->format('d M Y'),
            default => (string) $value,
        };
    }
}
