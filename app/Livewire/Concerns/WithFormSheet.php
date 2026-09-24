<?php

namespace App\Livewire\Concerns;

use App\Support\CompanyContext;
use Livewire\Attributes\Url;

/**
 * Create and edit forms open in an off-canvas sheet over their list instead of a separate page.
 * The sheet lives in the URL (?sheet=create, ?sheet=edit:12, ?sheet=create:income) so any page can
 * link straight into it. The form component inside keeps every authorization and company re-check.
 */
trait WithFormSheet
{
    #[Url(as: 'sheet', except: '')]
    public string $sheet = '';

    public function mountWithFormSheet(): void
    {
        $this->guardSheetCompany();
    }

    public function openSheet(string $sheet): void
    {
        $this->sheet = $sheet;
        $this->guardSheetCompany();
    }

    public function closeSheet(): void
    {
        $this->sheet = '';
    }

    /** The part before the colon: "create", "edit", "settle"… */
    public function sheetAction(): string
    {
        return strstr($this->sheet, ':', true) ?: $this->sheet;
    }

    /** The part after the colon: a record id or a type such as "income". */
    public function sheetArgument(): string
    {
        return (string) substr((string) strstr($this->sheet, ':'), 1);
    }

    /** Route name of the list page, so a sheet can come back to it after a company is chosen. */
    abstract protected function sheetRoute(): string;

    /**
     * Sheet actions that create company data and so need one active company in the header.
     *
     * @return list<string>
     */
    protected function sheetsNeedingCompany(): array
    {
        return [];
    }

    /** Mirrors the company.selected middleware of the create routes: ask for a company first, then return here. */
    private function guardSheetCompany(): void
    {
        if ($this->sheet === '' || ! in_array($this->sheetAction(), $this->sheetsNeedingCompany(), true)
            || app(CompanyContext::class)->company()?->is_active) {
            return;
        }
        $next = route($this->sheetRoute(), ['sheet' => $this->sheet], false);
        $this->sheet = '';
        $this->redirectRoute('admin.choose-company', ['next' => $next], navigate: true);
    }
}
