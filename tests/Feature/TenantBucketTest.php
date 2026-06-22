<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Models\TenantBucket;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Contracts\Filesystem\Filesystem;

class TenantBucketTest extends TestCase
{
    private function manager(): StorageManager
    {
        return app(StorageManager::class);
    }

    private function bucket(array $overrides = []): TenantBucket
    {
        return TenantBucket::create(array_merge([
            'tenant_type' => 'test_owner',
            'tenant_id'   => 1,
            'base_disk'   => 'media',
            'bucket'      => 'tenant-bucket',
            'is_active'   => true,
        ], $overrides));
    }

    public function test_to_disk_config_inherits_base_and_overrides_bucket_and_url(): void
    {
        $config = $this->bucket(['public_url' => 'https://cdn.example.com'])->toDiskConfig();

        $this->assertSame('local', $config['driver']);          // inherited from the `media` base disk
        $this->assertSame('tenant-bucket', $config['bucket']);  // overridden
        $this->assertSame('https://cdn.example.com', $config['url']);
    }

    public function test_byoa_credentials_override_the_base_disk(): void
    {
        $config = $this->bucket(['credentials' => [
            'driver'   => 's3',
            'key'      => 'byoa-key',
            'endpoint' => 'https://byoa.example.com',
        ]])->toDiskConfig();

        $this->assertSame('s3', $config['driver']);
        $this->assertSame('byoa-key', $config['key']);
    }

    public function test_config_for_resolves_a_tb_disk_to_the_tenant_bucket(): void
    {
        $tb = $this->bucket();

        $config = $this->manager()->configFor("tb:{$tb->id}");

        $this->assertSame('tenant-bucket', $config['bucket']);
    }

    public function test_disk_for_builds_a_working_filesystem_from_the_bucket(): void
    {
        $tb = $this->bucket();
        $file = File::create([
            'fileable_type' => 'test_owner',
            'fileable_id'   => 1,
            'disk'          => "tb:{$tb->id}",
            'path'          => 'x.bin',
            'name'          => 'x.bin',
            'original_name' => 'x.bin',
            'extension'     => 'bin',
            'mime_type'     => 'application/octet-stream',
            'size'          => 1,
            'context'       => 'media',
            'collection'    => 'gallery',
            'type'          => FileType::IMAGE,
            'access'        => 'public',
        ]);

        $disk = $this->manager()->diskFor($file);
        $this->assertInstanceOf(Filesystem::class, $disk);

        $disk->put('probe.txt', 'hi');
        $this->assertSame('hi', $disk->get('probe.txt'));
    }

    public function test_inactive_bucket_throws(): void
    {
        $tb = $this->bucket(['is_active' => false]);

        $this->expectException(\RuntimeException::class);
        $this->manager()->configFor("tb:{$tb->id}");
    }
}
