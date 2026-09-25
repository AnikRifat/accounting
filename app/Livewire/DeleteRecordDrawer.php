<?php

namespace App\Livewire;

use App\Livewire\Admin\Users\ManageableUsers;
use App\Models\Account;
use App\Models\Company;
use App\Models\Party;
use App\Models\User;
use App\Services\RecordDeletion;
use App\Support\CompanyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Layout-level delete dialog for master data and employees, opened by the `open-delete` window
 * event with `{kind, id}`. The record is always reloaded server-side within the user's companies
 * (an employee: among those the user manages, never the user themself).
 */
class DeleteRecordDrawer extends Component
{
    private const ABILITIES = [
        'category' => 'accounts.delete', 'payment-method' => 'accounts.delete', 'account' => 'accounts.delete',
        'party' => 'parties.delete', 'company' => 'companies.delete', 'user' => 'users.delete',
    ];

    public bool $open = false;

    #[Locked]
    public string $kind = '';

    #[Locked]
    public ?int $recordId = null;

    public string $targetId = '';

    public string $confirmCode = '';

    public function load(string $kind, int $id): void
    {
        $this->reset('kind', 'recordId', 'targetId', 'confirmCode');
        $this->resetErrorBag();
        abort_unless(isset(self::ABILITIES[$kind]), 404);
        Gate::authorize(self::ABILITIES[$kind]);
        $this->find($kind, $id) ?? abort(404);
        [$this->kind, $this->recordId, $this->open] = [$kind, $id, true];
    }

    public function deleteUnused(): void
    {
        $this->run(fn (RecordDeletion $service, Account|Party|User $record) => $service->deleteUnused($record, auth()->user()),
            fn (string $name): string => __(':name deleted.', ['name' => $name]));
    }

    public function transfer(): void
    {
        abort_if($this->kind === 'user', 404);
        $this->run(function (RecordDeletion $service, Account|Party|Company $record): void {
            $target = $service->transferTargets($record)->firstWhere('id', (int) $this->targetId);
            if (! $target) {
                throw ValidationException::withMessages(['targetId' => __('Choose where the transactions should move to.')]);
            }
            $service->transfer($record, $target, auth()->user());
        }, fn (string $name): string => __('Transactions moved and :name deleted.', ['name' => $name]));
    }

    public function hardDelete(): void
    {
        abort_if($this->kind === 'user', 404);
        $this->run(fn (RecordDeletion $service, Account|Party|Company $record) => $service->hardDelete($record, auth()->user(), $this->confirmCode),
            fn (string $name): string => __(':name and its transactions were deleted permanently.', ['name' => $name]));
    }

    public function render(): View
    {
        $record = $this->open && $this->recordId ? $this->find($this->kind, $this->recordId) : null;
        $service = app(RecordDeletion::class);

        return view('livewire.delete-record-drawer', [
            'record' => $record,
            'name' => $record ? $this->label($record) : '',
            'usage' => $record ? $service->usage($record) : ['count' => 0, 'total' => 0],
            'blocked' => $record ? $service->blockedReason($record) : null,
            'targets' => $record instanceof Account || $record instanceof Party
                ? $service->transferTargets($record)->mapWithKeys(fn (Account|Party $target): array => [$target->id => $this->label($target)])->all() : [],
        ]);
    }

    /**
     * @param  callable(RecordDeletion, Account|Party|Company|User): void  $action
     * @param  callable(string): string  $message  the success flash for the record's name
     */
    private function run(callable $action, callable $message): void
    {
        $record = $this->recordId ? $this->find($this->kind, $this->recordId) : null;
        abort_unless($record !== null, 404);
        $this->resetErrorBag();
        $name = $this->label($record);
        try {
            $action(app(RecordDeletion::class), $record);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError(in_array($field, ['targetId', 'confirmCode'], true) ? $field : ($field === 'target' ? 'targetId' : 'record'), $messages[0]);
            }

            return;
        }
        $this->reset('open', 'kind', 'recordId', 'targetId', 'confirmCode');
        session()->flash('success', $message($name));
        $this->redirect(CompanyContext::returnUrl(), navigate: true);
    }

    /** The record of the given kind among the user's companies, or null. */
    private function find(string $kind, int $id): Account|Party|Company|User|null
    {
        $companyIds = auth()->user()->accessibleCompanyIds();
        $accounts = fn (): Builder => Account::query()->whereIn('company_id', $companyIds);

        return match ($kind) {
            'category' => $accounts()->categories()->find($id),
            'payment-method' => $accounts()->paymentMethods()->find($id),
            'account' => $accounts()->find($id),
            'party' => Party::query()->whereIn('company_id', $companyIds)->find($id),
            'company' => Company::query()->whereKey($companyIds)->find($id),
            'user' => ManageableUsers::for(auth()->user())->whereKeyNot(auth()->id())->find($id),
            default => null,
        };
    }

    private function label(Account|Party|Company|User $record): string
    {
        return match (true) {
            $record instanceof Account => $record->code.' · '.$record->name,
            default => $record->name,
        };
    }
}
