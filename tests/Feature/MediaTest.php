<?php

namespace Tests\Feature;

use App\Livewire\Admin\Media\Index;
use App\Models\Media;
use App\Models\User;
use App\Services\MediaService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_upload_tracks_file_and_signed_download_requires_valid_signature(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $token = $user->createToken('media', ['media:write', 'media:read'])->plainTextToken;
        $response = $this->withToken($token)->postJson('/api/v1/media', ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'Example notes'), 'collection' => 'document'])
            ->assertCreated()->assertJsonPath('data.filename', 'notes.txt');
        $media = Media::sole();
        $this->assertSame($user->id, $media->uploaded_by);
        $this->assertSame('local', $media->disk);
        $this->assertSame('text/plain', $media->mime_type);
        $this->assertStringNotContainsString('notes.txt', $media->path);
        Storage::disk('local')->assertExists($media->path);
        $this->get($response->json('data.url'))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/media/'.$media->id.'/download')->assertForbidden();
    }

    public function test_changing_default_disk_keeps_old_files_on_their_original_disk(): void
    {
        Storage::fake('local');
        Storage::fake('s3');
        $user = User::factory()->create();
        $service = app(MediaService::class);
        $first = $service->store(UploadedFile::fake()->createWithContent('first.txt', 'First'), $user);
        config(['media.disk' => 's3']);
        $second = $service->store(UploadedFile::fake()->createWithContent('second.txt', 'Second'), $user);
        $this->assertSame('local', $first->disk);
        $this->assertSame('s3', $second->disk);
        Storage::disk('local')->assertExists($first->path);
        Storage::disk('s3')->assertExists($second->path);
        $service->delete($first, $user);
        Storage::disk('local')->assertMissing($first->path);
        $this->assertSoftDeleted($first);
        Storage::disk('s3')->assertExists($second->path);
    }

    public function test_file_ownership_is_enforced_for_listing_show_replace_and_delete(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $media = app(MediaService::class)->store(UploadedFile::fake()->createWithContent('private.txt', 'Private'), $owner);
        $other = User::factory()->create();
        $token = $other->createToken('other', ['media:read', 'media:write'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/media')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/media/'.$media->id)->assertForbidden();
        $this->deleteJson('/api/v1/media/'.$media->id)->assertForbidden();
        $this->postJson('/api/v1/media/'.$media->id.'/replace', ['file' => UploadedFile::fake()->createWithContent('replacement.txt', 'New')])->assertForbidden();
        Storage::disk('local')->assertExists($media->path);
    }

    public function test_admin_upload_uses_shared_tracking_service_and_records_image_dimensions(): void
    {
        Storage::fake('local');
        $root = User::factory()->create(['role' => 'owner']);
        $this->actingAs($root);
        Livewire::test(Index::class)->set('file', UploadedFile::fake()->image('avatar.png', 120, 80))->set('collection', 'avatar')->call('saveUpload')->assertHasNoErrors();
        $media = Media::sole();
        $this->assertSame(120, $media->width);
        $this->assertSame(80, $media->height);
        $this->assertSame($root->id, $media->uploaded_by);
        Storage::disk('local')->assertExists($media->path);
    }

    public function test_upload_rejects_unsafe_types_oversized_files_and_directory_traversal(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $token = $user->createToken('media', ['media:write'])->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/media', ['file' => UploadedFile::fake()->createWithContent('script.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson('/api/v1/media', ['file' => UploadedFile::fake()->create('large.pdf', 8193, 'application/pdf')])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson('/api/v1/media', ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'Text'), 'collection' => '../private'])->assertUnprocessable()->assertJsonValidationErrors('collection');
        $this->assertDatabaseCount('media', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_replacement_keeps_tracking_history_and_deletion_removes_bytes(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $service = app(MediaService::class);
        $old = $service->store(UploadedFile::fake()->createWithContent('old.txt', 'Old'), $user);
        $new = $service->replace($old, UploadedFile::fake()->createWithContent('new.txt', 'New'), $user);
        $this->assertSoftDeleted($old);
        Storage::disk('local')->assertMissing($old->path);
        Storage::disk('local')->assertExists($new->path);
        $service->delete($new, $user);
        $this->assertSoftDeleted($new);
        Storage::disk('local')->assertMissing($new->path);
    }

    public function test_model_media_helpers_share_the_service_and_polymorphic_owner(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $media = $user->addMedia(UploadedFile::fake()->createWithContent('profile.txt', 'Profile'), $user, 'profile');
        $this->assertTrue($media->mediable->is($user));
        $this->assertCount(1, $user->getMedia('profile'));
        $user->clearMedia($user, 'profile');
        $this->assertSoftDeleted($media);
        Storage::disk('local')->assertMissing($media->path);
    }

    public function test_api_token_without_media_scope_cannot_upload(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $token = $user->createToken('profile-only', ['profile:read'])->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/media', ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'Notes')])->assertForbidden();
        $this->assertDatabaseCount('media', 0);
    }

    public function test_tracking_failure_cleans_up_the_new_physical_file(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        Media::creating(function (): void {
            throw new \RuntimeException('Simulated tracking failure');
        });
        try {
            app(MediaService::class)->store(UploadedFile::fake()->createWithContent('notes.txt', 'Notes'), $user);
            $this->fail('Tracking failure must propagate.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated tracking failure', $error->getMessage());
        }
        $this->assertDatabaseCount('media', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_physical_delete_failure_preserves_the_active_tracking_record(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $service = app(MediaService::class);
        $media = $service->store(UploadedFile::fake()->createWithContent('notes.txt', 'Notes'), $user);
        $disk = Storage::disk('local');
        $adapter = \Mockery::mock(FilesystemAdapter::class);
        $adapter->shouldReceive('delete')->once()->with($media->path)->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($adapter);
        try {
            $service->delete($media, $user);
            $this->fail('Storage deletion failure must propagate.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Media deletion failed.', $error->getMessage());
        }
        $this->assertDatabaseHas('media', ['id' => $media->id, 'deleted_at' => null]);
        $disk->assertExists($media->path);
    }
}
