<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

class PresignedUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_presign_never_writes_into_a_model_path_the_request_names(): void
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
        $this->assertStringStartsWith('temp/', $response->json('key'));
        $this->assertStringContainsString('photo.jpg', $response->json('key'));
    }

    public function test_presign_signs_a_temp_key(): void
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

    /** Presign as a browser with the session `$session`, and put the bytes. */
    private function presignIn(string $session): string
    {
        $key = $this->withCredentials()->withCookie(config('session.cookie'), $session)
            ->postJson('/laracrate/uploads/presign', ['disk' => 'media', 'mime' => 'image/jpeg', 'file_name' => 'thing.jpg'])
            ->assertOk()->json('key');
        Storage::disk('media')->put($key, 'binary');

        return $key;
    }

    private function cancelIn(string $session, string $key)
    {
        return $this->withCredentials()->withCookie(config('session.cookie'), $session)
            ->deleteJson('/laracrate/uploads/media/' . base64_encode($key));
    }

    public function test_cancel_removes_the_temp_file_it_signed(): void
    {
        Storage::fake('media');
        $key = $this->presignIn(str_repeat('a', 40));

        $this->cancelIn(str_repeat('a', 40), $key)->assertOk();

        Storage::disk('media')->assertMissing($key);
    }

    public function test_another_browser_cannot_cancel_it(): void
    {
        Storage::fake('media');
        $key = $this->presignIn(str_repeat('a', 40));

        $this->cancelIn(str_repeat('b', 40), $key)->assertForbidden();

        Storage::disk('media')->assertExists($key);
    }

    public function test_a_temp_key_presign_did_not_sign_cannot_be_cancelled(): void
    {
        Storage::fake('media');
        Storage::disk('media')->put('temp/01HK_someone.jpg', 'binary');

        $this->cancelIn(str_repeat('a', 40), 'temp/01HK_someone.jpg')->assertForbidden();

        Storage::disk('media')->assertExists('temp/01HK_someone.jpg');
    }

    public function test_cancel_only_takes_a_plain_temp_key(): void
    {
        Storage::fake('media');
        Storage::disk('media')->put('temp/nested/01HK_thing.jpg', 'binary');

        $this->cancelIn(str_repeat('a', 40), 'temp/nested/01HK_thing.jpg')->assertStatus(422);

        Storage::disk('media')->assertExists('temp/nested/01HK_thing.jpg');
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
