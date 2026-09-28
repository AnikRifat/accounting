<?php

namespace Tests\Feature;

use App\Livewire\Admin\Crm\Leads\Form as LeadForm;
use App\Livewire\Admin\Crm\Leads\Index as LeadIndex;
use App\Livewire\Admin\Parties\Form as PartyForm;
use App\Livewire\Admin\Profile;
use App\Livewire\Admin\Users\Form as UserForm;
use App\Models\Company;
use App\Models\Lead;
use App\Models\Media;
use App\Models\Party;
use App\Models\User;
use App\Services\RecordDeletion;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProfilePhotosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('media.disk'));
    }

    private function userFor(string $role, Company ...$companies): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->companies()->attach(array_map(fn (Company $company): int => $company->id, $companies));

        return $user;
    }

    public function test_a_party_photo_is_optional_replaced_and_removed(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->userFor('accountant', $company));

        Livewire::test(PartyForm::class)->set('name', 'No Photo Traders')->call('save')->assertHasNoErrors();
        $this->assertDatabaseCount('media', 0);

        Livewire::test(PartyForm::class)->set('name', 'Photo Traders')->set('photo', UploadedFile::fake()->image('logo.png', 200, 200))->call('save')->assertHasNoErrors();
        $party = Party::query()->where('name', 'Photo Traders')->sole();
        $first = $party->photo;
        $this->assertSame(Party::PHOTO, $first->collection);
        Storage::disk(config('media.disk'))->assertExists($first->path);
        $this->assertStringContainsString('/media/'.$first->id.'/download', $party->photoUrl());

        Livewire::test(PartyForm::class, ['party' => $party])->assertViewHas('currentPhoto')->set('photo', UploadedFile::fake()->image('new.jpg'))->call('save')->assertHasNoErrors();
        Storage::disk(config('media.disk'))->assertMissing($first->path);
        $this->assertNotSame($first->id, $party->fresh()->photo->id);

        Livewire::test(PartyForm::class, ['party' => $party])->set('removePhoto', true)->call('save')->assertHasNoErrors();
        $this->assertNull($party->fresh()->photo);
        $this->assertSame(0, Media::query()->count());
    }

    public function test_only_images_are_accepted(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->userFor('sales-manager', $company));

        Livewire::test(LeadForm::class)->set('phone', '01711000000')->set('photo', UploadedFile::fake()->create('cv.pdf', 20, 'application/pdf'))
            ->call('save')->assertHasErrors('photo');
        Livewire::test(LeadForm::class)->set('phone', '01711000000')->set('photo', UploadedFile::fake()->image('huge.jpg')->size(5000))
            ->call('save')->assertHasErrors('photo');
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_lead_photos_show_on_the_list_and_go_when_the_lead_is_deleted(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->userFor('sales-manager', $company));
        Livewire::test(LeadForm::class)->set('name', 'Pictured Lead')->set('phone', '01711000000')->set('photo', UploadedFile::fake()->image('face.jpg'))
            ->call('save')->assertHasNoErrors();
        $lead = Lead::sole();
        $path = $lead->photo->path;

        $this->get('/admin/crm/leads')->assertOk()->assertSee('/media/'.$lead->photo->id.'/download', false);
        Livewire::test(LeadIndex::class)->call('delete', $lead->id);
        Storage::disk(config('media.disk'))->assertMissing($path);
        $this->assertSame(0, Media::query()->count());
    }

    public function test_employee_photos_come_from_the_employee_form_or_the_own_profile_and_show_on_their_parties(): void
    {
        $company = Company::factory()->create();
        $employee = User::factory()->employeeOf($company)->create();
        $this->actingAs(User::factory()->create(['role' => 'owner']));

        Livewire::test(UserForm::class, ['user' => $employee])->set('photo', UploadedFile::fake()->image('staff.png'))->call('save')->assertHasNoErrors();
        $photo = $employee->fresh()->photo;
        $this->assertNotNull($photo);
        $this->assertStringContainsString('/media/'.$photo->id.'/download', $employee->parties()->sole()->photoUrl());
        session([CompanyContext::SESSION_KEY => $company->id]);
        $this->get('/admin/parties')->assertOk()->assertSee('/media/'.$photo->id.'/download', false);

        $this->actingAs($employee);
        Livewire::test(Profile::class)->set('photo', UploadedFile::fake()->image('me.jpg'))->call('updateProfile')->assertHasNoErrors();
        $this->assertNotSame($photo->id, $employee->fresh()->photo->id);
        $this->get('/admin/profile')->assertOk()->assertSee('/media/'.$employee->fresh()->photo->id.'/download', false);
    }

    public function test_deleting_a_party_or_a_whole_company_removes_the_photo_files(): void
    {
        $company = Company::factory()->create(['code' => 'PIC']);
        $owner = $this->userFor('owner');
        $this->actingAs($owner);
        session([CompanyContext::SESSION_KEY => $company->id]);
        Livewire::test(PartyForm::class)->set('name', 'Gone Soon')->set('photo', UploadedFile::fake()->image('a.png'))->call('save');
        Livewire::test(PartyForm::class)->set('name', 'Gone Later')->set('photo', UploadedFile::fake()->image('b.png'))->call('save');
        Livewire::test(LeadForm::class)->set('phone', '01711000000')->set('photo', UploadedFile::fake()->image('c.png'))->call('save');
        $paths = Media::query()->pluck('path');
        $this->assertCount(3, $paths);

        app(RecordDeletion::class)->deleteUnused(Party::query()->where('name', 'Gone Soon')->sole(), $owner);
        $this->assertSame(2, Media::query()->count());
        app(RecordDeletion::class)->hardDelete($company, $owner, 'PIC');
        $this->assertSame(0, Media::query()->count());
        foreach ($paths as $path) {
            Storage::disk(config('media.disk'))->assertMissing($path);
        }
    }
}
