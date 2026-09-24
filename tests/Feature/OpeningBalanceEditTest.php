<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\PostsLedgerEntries;
use Tests\TestCase;

/** Editing an opening balance needs accounts.manage, the same ability as recording one. */
class OpeningBalanceEditTest extends TestCase
{
    use PostsLedgerEntries, RefreshDatabase;

    protected User $owner;

    public function test_entry_editor_without_accounts_manage_cannot_change_an_opening_balance(): void
    {
        $this->owner = User::factory()->create(['role' => 'owner']);
        $company = Company::factory()->create();
        $opening = $this->opening($company, '1000', 10_000_00, now()->toDateString());
        RolePermission::factory()->create(['role' => 'entry_editor', 'permissions' => ['admin.access', 'entries.view', 'entries.update']]);
        $editor = $this->user('entry_editor', $company);
        $data = ['entry_date' => now()->toDateString(), 'amount' => 99_000_00, 'debit_account_id' => $this->accountId($company, '1000'),
            'credit_account_id' => $company->accounts()->where('is_system', true)->where('type', 'equity')->value('id')];

        try {
            app(LedgerService::class)->update($opening, $data, $editor);
            $this->fail('An entry editor without accounts.manage changed an opening balance.');
        } catch (AuthorizationException) {
            $this->assertSame(10_000_00, $opening->fresh()->amount);
        }

        app(LedgerService::class)->update($opening, $data, $this->owner);
        $this->assertSame(99_000_00, $opening->fresh()->amount);
    }
}
