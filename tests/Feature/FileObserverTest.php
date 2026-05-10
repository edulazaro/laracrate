<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Tests\Support\HasFilesTestModel;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_force_delete_removes_asset_from_backend(): void
    {
        Storage::fake('media');
        Bus::fake();

        $owner = HasFilesTestModel::create();

        $upload = \Illuminate\Http\UploadedFile::fake()->image('a.jpg');
        $file = $owner->addFile($upload, 'gallery');

        $key = $file->path . '/' . $file->name;
        Storage::disk('media')->assertExists($key);

        $file->forceDelete();

        Storage::disk('media')->assertMissing($key);
    }

    public function test_cascade_delete_removes_children_and_their_assets(): void
    {
        Storage::fake('media');

        $owner = HasFilesTestModel::create();

        $parent = File::create([
            'slug' => (string) Str::ulid(),
            'fileable_type' => 'test_owner', 'fileable_id' => $owner->id,
            'disk' => 'media', 'path' => 'p', 'name' => 'parent.jpg', 'original_name' => 'parent.jpg',
            'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'size' => 100,
            'context' => 'media', 'collection' => 'gallery', 'type' => FileType::IMAGE, 'access' => 'public',
        ]);
        Storage::disk('media')->put('p/parent.jpg', 'parent-binary');

        $child = File::create([
            'slug' => (string) Str::ulid(), 'parent_id' => $parent->id, 'variant' => 'thumbnail',
            'fileable_type' => 'test_owner', 'fileable_id' => $owner->id,
            'disk' => 'media', 'path' => 'p/variants', 'name' => 'thumb.webp', 'original_name' => 'thumb.webp',
            'extension' => 'webp', 'mime_type' => 'image/webp', 'size' => 50,
            'context' => 'media', 'collection' => 'gallery', 'type' => FileType::IMAGE, 'access' => 'public',
        ]);
        Storage::disk('media')->put('p/variants/thumb.webp', 'thumb-binary');

        $parent->forceDelete();

        Storage::disk('media')->assertMissing('p/parent.jpg');
        Storage::disk('media')->assertMissing('p/variants/thumb.webp');
        $this->assertDatabaseMissing('files', ['id' => $child->id]);
    }

    public function test_soft_delete_does_not_remove_asset(): void
    {
        Storage::fake('media');
        Bus::fake();

        $owner = HasFilesTestModel::create();
        $file = $owner->addFile(\Illuminate\Http\UploadedFile::fake()->image('a.jpg'), 'gallery');

        $key = $file->path . '/' . $file->name;
        Storage::disk('media')->assertExists($key);

        $file->delete(); // soft delete

        Storage::disk('media')->assertExists($key); // sigue ahí
        $this->assertSoftDeleted('files', ['id' => $file->id]);
    }
}
