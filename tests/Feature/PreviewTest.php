<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * `preview` names one of the type's variants; preview() is that variant.
 */
class PreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
    }

    private function collection(array $types): void
    {
        config()->set('laracrate.collections.clips', ['disk' => 'media', 'access' => 'private', 'types' => $types]);
    }

    private function file(FileType $type, string $mime, array $attributes = []): File
    {
        return File::create(array_merge([
            'disk'          => 'media',
            'path'          => 'clips/' . Str::random(8),
            'name'          => Str::random(8),
            'original_name' => 'clip',
            'extension'     => 'bin',
            'mime_type'     => $mime,
            'size'          => 10,
            'context'       => 'media',
            'collection'    => 'clips',
            'type'          => $type,
            'access'        => 'private',
        ], $attributes));
    }

    public function test_preview_is_the_variant_the_type_names(): void
    {
        $this->collection(['video' => ['preview' => 'portada.mini']]);
        $video   = $this->file(FileType::VIDEO, 'video/mp4');
        $portada = $this->file(FileType::IMAGE, 'image/jpeg', ['parent_id' => $video->id, 'variant' => 'portada']);
        $mini    = $this->file(FileType::IMAGE, 'image/jpeg', ['parent_id' => $portada->id, 'variant' => 'mini', 'path' => 'clips/mini']);

        $this->assertTrue($video->preview()->is($mini));
        $this->assertStringContainsString($mini->slug, $video->preview_link);
    }

    public function test_without_that_variant_there_is_no_preview(): void
    {
        $this->collection(['video' => ['preview' => 'portada']]);
        $video = $this->file(FileType::VIDEO, 'video/mp4');

        $this->assertNull($video->preview());
        $this->assertSame($video->placeholderFor('image'), $video->preview_link);
    }

    public function test_a_type_that_names_no_preview_has_none(): void
    {
        $this->collection(['image' => []]);
        $image = $this->file(FileType::IMAGE, 'image/jpeg');

        $this->assertNull($image->preview());
        // An image without a preview stands for itself.
        $this->assertStringContainsString($image->slug, $image->preview_link);
    }

    public function test_a_1x_preview_block_fails_with_what_to_change(): void
    {
        $this->collection(['video' => ['preview' => ['frame_at' => '00:00:01']]]);
        $video = $this->file(FileType::VIDEO, 'video/mp4');

        $this->assertSame($video->placeholderFor('image'), $video->preview_link);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('names a variant now');
        $video->preview();
    }

    public function test_false_turns_the_preview_off(): void
    {
        $this->collection(['video' => ['preview' => false]]);

        $this->assertNull($this->file(FileType::VIDEO, 'video/mp4')->preview());
    }
}
