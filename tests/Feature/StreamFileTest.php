<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\PolicyRegistry;
use EduLazaro\Laracrate\Tests\Support\HasFilesTestModel;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StreamFileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The routes go through PHP behind the session and check the view policy on every
     * request: no signature, and nothing that expires.
     */
    public function test_stream_is_refused_to_who_may_not_see_the_file(): void
    {
        $file = $this->viewable(false);

        $this->get($this->path('laracrate.files.stream', $file))->assertStatus(403);
        $this->get($this->path('laracrate.files.download', $file))->assertStatus(403);
    }

    public function test_stream_serves_the_file_without_a_signature(): void
    {
        $file = $this->viewable(true);

        $this->get($this->path('laracrate.files.stream', $file))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_stream_shows_the_file_without_counting_a_download(): void
    {
        $file = $this->viewable(true);

        $this->get($this->path('laracrate.files.stream', $file))->assertOk();

        $this->assertSame(0, $file->fresh()->downloads_count);
    }

    public function test_download_saves_the_file_and_counts_it(): void
    {
        $file = $this->viewable(true);

        $response = $this->get($this->path('laracrate.files.download', $file))->assertOk();

        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertSame(1, $file->fresh()->downloads_count);
        $this->assertNotNull($file->fresh()->last_downloaded_at);
    }

    public function test_the_file_links_are_the_plain_routes(): void
    {
        $file = $this->makeFile();

        $this->assertSame(route('laracrate.files.stream', ['file' => $file->slug]), $file->streamUrl());
        $this->assertSame(route('laracrate.files.download', ['file' => $file->slug]), $file->downloadUrl());
    }

    private function viewable(bool $allowed): File
    {
        Storage::fake('media');

        $owner = HasFilesTestModel::create();
        $file = $this->makeFile([
            'fileable_type' => 'test_owner',
            'fileable_id'   => $owner->id,
            'disk'          => 'media',
        ]);
        Storage::disk('media')->put($file->key, 'binary');

        app(PolicyRegistry::class)->viewable('test_owner', fn () => $allowed);

        return $file;
    }

    private function path(string $route, File $file): string
    {
        return parse_url(route($route, ['file' => $file->slug]), PHP_URL_PATH);
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
            'access'        => 'private',
        ], $attrs));
    }
}
