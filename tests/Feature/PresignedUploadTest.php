<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

class PresignedUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_presign_with_canonical_path_when_fileable_provided(): void
    {
        Storage::fake('media');

        $response = $this->postJson('/laracrate/uploads/presign', [
            'disk'           => 'media',
            'mime'           => 'image/jpeg',
            'file_name'      => 'photo.jpg',
            'fileable_type'  => 'property',
            'fileable_id'    => 42,
            'collection'     => 'gallery',
        ]);

        $response->assertOk();
        $key = $response->json('key');

        $this->assertStringStartsWith('property/42/gallery/', $key);
        $this->assertStringContainsString('photo.jpg', $key);
    }

    public function test_presign_falls_back_to_temp_when_no_fileable(): void
    {
        Storage::fake('media');

        $response = $this->postJson('/laracrate/uploads/presign', [
            'disk'      => 'media',
            'mime'      => 'image/jpeg',
            'file_name' => 'photo.jpg',
        ]);

        $response->assertOk();
        $this->assertStringStartsWith('temp/', $response->json('key'));
    }

    public function test_presign_rejects_disk_not_allowed(): void
    {
        $response = $this->postJson('/laracrate/uploads/presign', [
            'disk'      => 'unknown_disk',
            'mime'      => 'image/jpeg',
            'file_name' => 'x.jpg',
        ]);

        $response->assertStatus(403);
    }

    public function test_cancel_removes_temp_file(): void
    {
        Storage::fake('media');
        Storage::disk('media')->put('temp/01HK_thing.jpg', 'binary');

        $key = base64_encode('temp/01HK_thing.jpg');

        $response = $this->deleteJson("/laracrate/uploads/media/{$key}");

        $response->assertOk();
        Storage::disk('media')->assertMissing('temp/01HK_thing.jpg');
    }

    public function test_cancel_refuses_non_temp_keys(): void
    {
        Storage::fake('media');
        Storage::disk('media')->put('property/42/gallery/img.jpg', 'binary');

        $key = base64_encode('property/42/gallery/img.jpg');

        $response = $this->deleteJson("/laracrate/uploads/media/{$key}");

        $response->assertStatus(422);
        Storage::disk('media')->assertExists('property/42/gallery/img.jpg');
    }

    public function test_without_an_allowed_list_only_the_collection_disks_can_be_signed(): void
    {
        config()->set('laracrate.uploads.allowed_disks', []);
        Storage::fake('media');

        $this->postJson('/laracrate/uploads/presign', [
            'disk' => 'public', 'mime' => 'image/png', 'file_name' => 'a.png',
        ])->assertStatus(403);

        $this->postJson('/laracrate/uploads/presign', [
            'disk' => 'media', 'mime' => 'image/png', 'file_name' => 'a.png',
        ])->assertOk();
    }

    public function test_runnable_content_cannot_be_signed(): void
    {
        Storage::fake('media');

        $this->postJson('/laracrate/uploads/presign', [
            'disk' => 'media', 'mime' => 'text/html', 'file_name' => 'page.html',
        ])->assertStatus(422);

        $this->postJson('/laracrate/uploads/presign', [
            'disk' => 'media', 'mime' => 'image/png', 'file_name' => 'trick.svg',
        ])->assertStatus(422);
    }
}
