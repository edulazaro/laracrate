<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

class StorageManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_type_config_with_defaults(): void
    {
        $manager = app(StorageManager::class);
        $config  = $manager->getTypeConfig('gallery', 'image');

        $this->assertContains('image/jpeg', $config['accepted_mime_types']);
        $this->assertSame(5120, $config['max_file_size']);
        $this->assertArrayHasKey('thumbnail', $config['variants']);
    }

    public function test_returns_empty_for_type_not_accepted(): void
    {
        $manager = app(StorageManager::class);
        $this->assertSame([], $manager->getTypeConfig('gallery', 'video'));
    }

    public function test_accepts_type(): void
    {
        $manager = app(StorageManager::class);
        $this->assertTrue($manager->acceptsType('gallery', 'image'));
        $this->assertFalse($manager->acceptsType('gallery', 'video'));
        $this->assertTrue($manager->acceptsType('documents', 'document'));
    }

    public function test_normalizes_string_types_into_keyed(): void
    {
        config()->set('laracrate.collections.mixed', [
            'disk'   => 'media',
            'access' => 'public',
            'types'  => ['image', 'video' => ['preview' => ['frame_at' => '00:00:01']]],
        ]);

        $manager = app(StorageManager::class);
        $this->assertTrue($manager->acceptsType('mixed', 'image'));
        $this->assertTrue($manager->acceptsType('mixed', 'video'));
        $this->assertSame([], $manager->getTypeConfig('mixed', 'image'));
        $this->assertSame('00:00:01', $manager->getTypeConfig('mixed', 'video')['preview']['frame_at']);
    }

    public function test_key_of_builds_full_path(): void
    {
        $file = new File(['path' => 'horse/42/gallery', 'name' => 'photo.webp']);
        $this->assertSame('horse/42/gallery/photo.webp', app(StorageManager::class)->keyOf($file));
    }

    public function test_read_write_and_delete_via_storage_fake(): void
    {
        Storage::fake('media');
        $manager = app(StorageManager::class);

        $manager->writeBinary('media', 'test/foo.txt', 'hello world', 'text/plain');
        Storage::disk('media')->assertExists('test/foo.txt');

        $manager->deleteFromBackend('media', 'test/foo.txt');
        Storage::disk('media')->assertMissing('test/foo.txt');
    }

    public function test_move_server_side_for_local_disk(): void
    {
        Storage::fake('media');
        $manager = app(StorageManager::class);

        $manager->writeBinary('media', 'temp/01HK_video.mp4', 'binary');

        $manager->moveServerSide('media', 'temp/01HK_video.mp4', 'horse/42/gallery/01HK_video.mp4');

        Storage::disk('media')->assertMissing('temp/01HK_video.mp4');
        Storage::disk('media')->assertExists('horse/42/gallery/01HK_video.mp4');
    }
}
