<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Jobs\ProcessFileJob;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Tests\Support\HasFilesTestModel;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class HasFilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_file_with_uploaded_file_persists_and_dispatches_job(): void
    {
        Storage::fake('media');
        Bus::fake();

        $owner = HasFilesTestModel::create(['name' => 'Edu']);
        $upload = UploadedFile::fake()->image('photo.jpg', 200, 200);

        $file = $owner->addFile($upload, 'gallery');

        $this->assertNotNull($file);
        $this->assertSame('test_owner', $file->fileable_type);
        $this->assertSame($owner->id, $file->fileable_id);
        $this->assertSame('gallery', $file->collection);
        $this->assertSame(FileType::IMAGE, $file->type);
        Storage::disk('media')->assertExists($file->path . '/' . $file->name);

        Bus::assertDispatched(ProcessFileJob::class);
    }

    public function test_files_relation_returns_top_level_only_ordered_by_position(): void
    {
        $owner = HasFilesTestModel::create();

        $a = $this->makeFile($owner, ['position' => 2]);
        $b = $this->makeFile($owner, ['position' => 0]);
        $c = $this->makeFile($owner, ['position' => 1]);
        // Variant — no debe aparecer.
        $variant = File::create([
            'slug' => (string) Str::ulid(), 'parent_id' => $a->id, 'variant' => 'thumbnail',
            'fileable_type' => 'test_owner', 'fileable_id' => $owner->id,
            'disk' => 'media', 'path' => 'x', 'name' => 'v.webp', 'original_name' => 'v.webp',
            'extension' => 'webp', 'mime_type' => 'image/webp', 'size' => 1,
            'context' => 'media', 'collection' => 'gallery', 'type' => FileType::IMAGE, 'access' => 'public',
        ]);

        $files = $owner->files('gallery')->get();

        $this->assertEquals([$b->id, $c->id, $a->id], $files->pluck('id')->all());
        $this->assertFalse($files->contains('id', $variant->id));
    }

    public function test_reorder_files_assigns_position_by_index(): void
    {
        $owner = HasFilesTestModel::create();

        $a = $this->makeFile($owner);
        $b = $this->makeFile($owner);
        $c = $this->makeFile($owner);

        $owner->reorderFiles('gallery', [$c->id, $a->id, $b->id]);

        $this->assertSame(0, $c->fresh()->position);
        $this->assertSame(1, $a->fresh()->position);
        $this->assertSame(2, $b->fresh()->position);
    }

    public function test_set_file_replaces_existing_in_single_collection(): void
    {
        Storage::fake('media');
        Bus::fake();

        config()->set('laracrate.collections.avatar', [
            'disk'   => 'media',
            'access' => 'public',
            'single' => true,
            'types'  => ['image' => ['accepted_mime_types' => ['image/jpeg']]],
        ]);

        $owner = HasFilesTestModel::create();

        $first  = $owner->addFile(UploadedFile::fake()->image('a.jpg'), 'avatar');
        $second = $owner->setFile('avatar', UploadedFile::fake()->image('b.jpg'));

        $this->assertSoftDeleted('files', ['id' => $first->id]);
        $this->assertNotNull($second);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_collection_rejects_unaccepted_mime(): void
    {
        Storage::fake('media');

        $owner = HasFilesTestModel::create();
        $upload = UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream');

        $this->expectException(\InvalidArgumentException::class);
        $owner->addFile($upload, 'gallery');
    }

    public function test_autoposition_increments_per_collection(): void
    {
        Storage::fake('media');
        Bus::fake();

        $owner = HasFilesTestModel::create();

        $a = $owner->addFile(UploadedFile::fake()->image('1.jpg'), 'gallery');
        $b = $owner->addFile(UploadedFile::fake()->image('2.jpg'), 'gallery');
        $c = $owner->addFile(UploadedFile::fake()->image('3.jpg'), 'gallery');

        $this->assertSame(0, $a->position);
        $this->assertSame(1, $b->position);
        $this->assertSame(2, $c->position);
    }

    protected function makeFile(HasFilesTestModel $owner, array $attrs = []): File
    {
        return File::create(array_merge([
            'slug' => (string) Str::ulid(),
            'fileable_type' => 'test_owner', 'fileable_id' => $owner->id,
            'disk' => 'media', 'path' => 'test', 'name' => Str::random(8) . '.jpg',
            'original_name' => 'orig.jpg', 'extension' => 'jpg', 'mime_type' => 'image/jpeg',
            'size' => 100, 'context' => 'media', 'collection' => 'gallery',
            'type' => FileType::IMAGE, 'access' => 'public',
        ], $attrs));
    }
}
