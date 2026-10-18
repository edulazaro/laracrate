<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\PolicyRegistry;
use EduLazaro\Laracrate\Tests\Support\HasFilesTestModel;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * The viewer endpoint (laracrate::viewer): what an image, audio or video shows, with a
 * link made at request time, and only for whoever may see the file.
 */
class ViewerTest extends TestCase
{
    use RefreshDatabase;

    private HasFilesTestModel $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = HasFilesTestModel::create(['name' => 'owner']);
        app(PolicyRegistry::class)->viewable('test_owner', fn () => true);
    }

    private function file(FileType $type, string $mime, array $attrs = []): File
    {
        return File::create(array_merge([
            'slug'          => (string) Str::ulid(),
            'disk'          => 'media',
            'path'          => 'test/' . Str::random(8),
            'name'          => 'clip',
            'original_name' => 'Hearing.' . Str::after($mime, '/'),
            'mime_type'     => $mime,
            'size'          => 100,
            'collection'    => 'gallery',
            'type'          => $type,
            'access'        => 'public',
            'fileable_type' => 'test_owner',
            'fileable_id'   => $this->owner->id,
        ], $attrs));
    }

    public function test_an_image_shows_its_display_variant(): void
    {
        $image = $this->file(FileType::IMAGE, 'image/jpeg', ['title' => 'Front page']);
        $this->file(FileType::IMAGE, 'image/webp', ['parent_id' => $image->id, 'variant' => 'display', 'path' => 'test/display']);

        $this->getJson(route('laracrate.files.view', $image->slug))
            ->assertOk()
            ->assertJson(['slug' => $image->slug, 'name' => 'Front page', 'type' => 'image', 'mime' => 'image/webp'])
            ->assertJsonPath('url', fn (string $url) => str_contains($url, 'test/display'))
            ->assertJsonStructure(['download']);
    }

    public function test_audio_shows_the_file_itself(): void
    {
        $audio = $this->file(FileType::AUDIO, 'audio/mpeg');

        $this->getJson(route('laracrate.files.view', $audio->slug))
            ->assertOk()
            ->assertJson(['type' => 'audio', 'mime' => 'audio/mpeg', 'name' => 'Hearing.mpeg'])
            ->assertJsonPath('url', fn (string $url) => str_contains($url, $audio->path));
    }

    public function test_a_video_plays_its_playback_copy(): void
    {
        $video = $this->file(FileType::VIDEO, 'video/quicktime');
        $this->file(FileType::VIDEO, 'video/mp4', ['parent_id' => $video->id, 'variant' => 'playback', 'path' => 'test/playback']);

        $this->getJson(route('laracrate.files.view', $video->slug))
            ->assertOk()
            ->assertJson(['type' => 'video', 'mime' => 'video/mp4'])
            ->assertJsonPath('url', fn (string $url) => str_contains($url, 'test/playback'));
    }

    public function test_other_files_are_not_shown(): void
    {
        $pdf = $this->file(FileType::DOCUMENT, 'application/pdf');

        $this->getJson(route('laracrate.files.view', $pdf->slug))->assertStatus(422);
    }

    public function test_only_who_may_see_the_file_gets_a_link(): void
    {
        $audio = $this->file(FileType::AUDIO, 'audio/mpeg', ['access' => 'signed']);
        app(PolicyRegistry::class)->viewable('test_owner', fn () => false);

        $this->getJson(route('laracrate.files.view', $audio->slug))->assertForbidden();
    }
}
