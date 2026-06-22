<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\PolicyRegistry;
use EduLazaro\Laracrate\Tests\Support\HasFilesTestModel;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class StreamFileTest extends TestCase
{
    use RefreshDatabase;

    public function test_stream_rejects_unsigned_request(): void
    {
        $file = $this->makeFile();

        $response = $this->get("/files/{$file->slug}/stream");

        $response->assertStatus(403);
    }

    public function test_stream_serves_with_valid_signed_url_when_policy_allows(): void
    {
        Storage::fake('media');

        $owner = HasFilesTestModel::create();
        $file = $this->makeFile([
            'fileable_type' => 'test_owner',
            'fileable_id'   => $owner->id,
            'disk'          => 'media',
        ]);

        Storage::disk('media')->put($file->key, 'hello');

        // Permitir view sobre test_owner.
        app(PolicyRegistry::class)->viewable('test_owner', fn () => true);

        $url = URL::temporarySignedRoute(
            'laracrate.files.stream',
            now()->addMinutes(5),
            ['file' => $file->slug]
        );

        $response = $this->get(parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_stream_increments_downloads_count(): void
    {
        Storage::fake('media');

        $owner = HasFilesTestModel::create();
        $file = $this->makeFile([
            'fileable_type' => 'test_owner',
            'fileable_id'   => $owner->id,
            'disk'          => 'media',
        ]);
        Storage::disk('media')->put($file->key, 'binary');

        app(PolicyRegistry::class)->viewable('test_owner', fn () => true);

        $url = URL::temporarySignedRoute(
            'laracrate.files.stream',
            now()->addMinutes(5),
            ['file' => $file->slug]
        );

        $this->get(parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY))->assertOk();

        $this->assertSame(1, $file->fresh()->downloads_count);
        $this->assertNotNull($file->fresh()->last_downloaded_at);
    }

    public function test_preview_does_not_increment_downloads(): void
    {
        Storage::fake('media');

        $owner = HasFilesTestModel::create();
        $file = $this->makeFile([
            'fileable_type' => 'test_owner',
            'fileable_id'   => $owner->id,
            'disk'          => 'media',
        ]);
        Storage::disk('media')->put($file->key, 'binary');

        app(PolicyRegistry::class)->viewable('test_owner', fn () => true);

        $url = URL::temporarySignedRoute(
            'laracrate.files.preview',
            now()->addMinutes(5),
            ['file' => $file->slug]
        );

        $this->get(parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY))->assertOk();

        $this->assertSame(0, $file->fresh()->downloads_count);
    }

    public function test_policy_denial_returns_403(): void
    {
        Storage::fake('media');

        $owner = HasFilesTestModel::create();
        $file = $this->makeFile([
            'fileable_type' => 'test_owner',
            'fileable_id'   => $owner->id,
            'disk'          => 'media',
        ]);
        Storage::disk('media')->put($file->key, 'binary');

        app(PolicyRegistry::class)->viewable('test_owner', fn () => false);

        $url = URL::temporarySignedRoute(
            'laracrate.files.stream',
            now()->addMinutes(5),
            ['file' => $file->slug]
        );

        $this->get(parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY))
            ->assertStatus(403);
    }

    protected function makeFile(array $attrs = []): File
    {
        return File::create(array_merge([
            'slug'          => (string) Str::ulid(),
            'disk'          => 'media',
            'path'          => 'test',
            'name'          => Str::random(8) . '.jpg',
            'original_name' => 'orig.jpg',
            'extension'     => 'jpg',
            'mime_type'     => 'image/jpeg',
            'size'          => 100,
            'context'       => 'media',
            'collection'    => 'gallery',
            'type'          => FileType::IMAGE,
            'access'        => 'stream',
        ], $attrs));
    }
}
