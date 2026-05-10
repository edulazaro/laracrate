<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Tests\Support\HasFilesTestModel;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

class FileModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_variant_navigation_with_dot_notation(): void
    {
        $owner = HasFilesTestModel::create(['name' => 'Edu']);

        $video = $this->makeFile(['fileable_type' => 'test_owner', 'fileable_id' => $owner->id, 'type' => FileType::VIDEO, 'name' => 'video.mp4']);
        $preview = $this->makeFile(['parent_id' => $video->id, 'variant' => 'preview', 'type' => FileType::IMAGE, 'name' => 'preview.jpg']);
        $thumb   = $this->makeFile(['parent_id' => $preview->id, 'variant' => 'thumbnail', 'type' => FileType::IMAGE, 'name' => 'thumb.webp']);

        $found = $video->variant('preview.thumbnail');
        $this->assertSame($thumb->id, $found->id);
    }

    public function test_variant_falls_back_to_ancestor_if_chain_breaks(): void
    {
        $owner = HasFilesTestModel::create(['name' => 'Edu']);

        $video   = $this->makeFile(['fileable_type' => 'test_owner', 'fileable_id' => $owner->id, 'type' => FileType::VIDEO]);
        $preview = $this->makeFile(['parent_id' => $video->id, 'variant' => 'preview', 'type' => FileType::IMAGE]);

        // Sin 'small' bajo preview → cae a preview.
        $found = $video->variant('preview.small');
        $this->assertSame($preview->id, $found->id);

        // Sin 'foo' en video → cae a video.
        $found = $video->variant('foo.bar');
        $this->assertSame($video->id, $found->id);
    }

    public function test_make_default_unsets_other_defaults(): void
    {
        $owner = HasFilesTestModel::create(['name' => 'Edu']);
        $a = $this->makeFile(['fileable_type' => 'test_owner', 'fileable_id' => $owner->id, 'collection' => 'gallery', 'default' => true]);
        $b = $this->makeFile(['fileable_type' => 'test_owner', 'fileable_id' => $owner->id, 'collection' => 'gallery', 'default' => false]);

        $b->makeDefault();

        $this->assertFalse($a->fresh()->default);
        $this->assertTrue($b->fresh()->default);
    }

    public function test_published_scopes(): void
    {
        $a = $this->makeFile(['published' => true]);
        $b = $this->makeFile(['published' => false]);

        $this->assertEquals([$a->id], File::published()->pluck('id')->all());
        $this->assertEquals([$b->id], File::unpublished()->pluck('id')->all());
    }

    public function test_publish_unpublish_helpers(): void
    {
        $file = $this->makeFile(['published' => true]);

        $file->unpublish();
        $this->assertFalse($file->fresh()->published);

        $file->publish();
        $this->assertTrue($file->fresh()->published);
    }

    public function test_link_accessor_proxies_to_url(): void
    {
        $file = $this->makeFile(['access' => 'public', 'collection' => 'gallery']);

        // Para access=public lee Storage::disk()->url(); el accessor solo prueba que el método se delega.
        $this->assertNotNull($file->link); // no exigimos formato exacto, solo que no peta
    }

    protected function makeFile(array $attrs = []): File
    {
        return File::create(array_merge([
            'slug'          => (string) Str::ulid(),
            'disk'          => 'media',
            'path'          => 'test',
            'name'          => Str::random(16) . '.bin',
            'original_name' => 'orig.bin',
            'extension'     => 'bin',
            'mime_type'     => 'application/octet-stream',
            'size'          => 100,
            'context'       => 'media',
            'collection'    => 'gallery',
            'type'          => FileType::IMAGE,
            'access'        => 'public',
        ], $attrs));
    }
}
