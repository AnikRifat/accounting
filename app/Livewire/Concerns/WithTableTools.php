<?php

namespace App\Livewire\Concerns;

use App\Support\TableExport;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Row selection, Excel export and printing for a list. The component supplies the scoped, filtered query
 * the page itself shows and one column definition; selected ids only ever narrow that query.
 */
trait WithTableTools
{
    /** @var list<string> ids of the ticked rows, kept across pages */
    public array $selected = [];

    abstract protected function tableQuery(): Builder;

    abstract protected function tableExport(): TableExport;

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /**
     * @param  'xlsx'|'print'  $format
     * @param  'all'|'selected'  $scope
     * @param  list<string>  $columns
     */
    public function exportTable(string $format, string $scope = 'all', array $columns = [], string $orientation = 'portrait'): ?BinaryFileResponse
    {
        $query = $this->tableQuery();
        if ($scope === 'selected') {
            $query->whereKey(array_map('intval', $this->selected));
        }
        $export = $this->tableExport()->only(array_map('strval', $columns));
        if ($format === 'xlsx') {
            return response()->download($export->toXlsx($query->lazy(500)), $export->filename('xlsx'))->deleteFileAfterSend();
        }
        $token = $export->toPrint($query->limit(TableExport::PRINT_LIMIT)->get(), $orientation);
        $this->dispatch('print-table', url: route('admin.print', $token));

        return null;
    }

    /** @return array<string, string> column key → label, for the export and print options */
    public function tableColumns(): array
    {
        return $this->tableExport()->labels();
    }
}
