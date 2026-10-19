<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Enums\FileAccess;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * url() is the direct link: public, signed until the caller says when private, and the
 * stream route when the file must only go through PHP.
 */
class FileUrlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
    }

    private function file(array $attributes = []): File
    {
        return File::create(array_merge([
            'disk'          => 'media',
            'path'          => 'test',
            'name'          => Str::random(8) . '.jpg',
            'original_name' => 'photo.jpg',
            'extension'     => 'jpg',
            'mime_type'     => 'image/jpeg',
            'size'          => 100,
            'context'       => 'media',
            'collection'    => 'gallery',
            'type'          => FileType::IMAGE,
            'access'        => 'private',
        ], $attributes));
    }

    public function test_a_private_file_gets_a_signed_link_for_the_default_minutes(): void
    {
        $this->freezeTime();

        $url = $this->file()->url();

        $this->assertStringContainsString('_laracrate/local/serve', $url);
        $this->assertStringContainsString('expires=' . now()->addMinutes(5)->getTimestamp(), $url);
    }

    public function test_the_caller_decides_when_the_signed_link_expires(): void
    {
        $this->freezeTime();

        $url = $this->file()->url(expires: now()->addHours(3));

        $this->assertStringContainsString('expires=' . now()->addHours(3)->getTimestamp(), $url);
    }

    public function test_an_encrypted_or_sensitive_file_only_gets_its_stream_route(): void
    {
        $encrypted = $this->file(['is_encrypted' => true]);
        $sensitive = $this->file(['sensitive' => true]);

        $this->assertSame($encrypted->streamUrl(), $encrypted->url());
        $this->assertSame($sensitive->streamUrl(), $sensitive->url(expires: now()->addDay()));
    }

    public function test_a_public_file_gets_its_public_url(): void
    {
        $file = $this->file(['access' => 'public']);

        $this->assertSame(Storage::disk('media')->url($file->key), $file->url());
    }

    public function test_the_1x_access_names_read_as_private(): void
    {
        $this->assertSame(FileAccess::PRIVATE, FileAccess::fromConfig('signed'));
        $this->assertSame(FileAccess::PRIVATE, FileAccess::fromConfig('stream'));
        $this->assertSame(FileAccess::PUBLIC, FileAccess::fromConfig('public'));
        $this->assertSame(FileAccess::PRIVATE, $this->file(['access' => 'signed'])->fresh()->access);
    }
}
