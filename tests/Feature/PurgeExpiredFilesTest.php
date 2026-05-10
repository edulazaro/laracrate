<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Console\Commands\PurgeExpiredFilesCommand;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Enums\ProcessingStatus;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

class PurgeExpiredFilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Añade una colección con TTL para los tests.
        config()->set('laracrate.collections.temp_uploads', [
            'disk'      => 'media',
            'access'    => 'public',
            'ttl_hours' => 24,
            'types'     => ['image' => []],
        ]);

        config()->set('laracrate.collections.exports', [
            'disk'      => 'documents',
            'access'    => 'signed',
            'ttl_hours' => 1,
            'types'     => ['document' => []],
        ]);
    }

    public function test_deletes_files_older_than_ttl(): void
    {
        $expired = $this->makeFile('temp_uploads', age: 30);  // 30 h > 24 h
        $fresh   = $this->makeFile('temp_uploads', age: 5);   // 5 h < 24 h

        $this->artisan(PurgeExpiredFilesCommand::class)->assertSuccessful();

        $this->assertNull(File::withTrashed()->find($expired->id), 'expired file should be hard-deleted');
        $this->assertNotNull(File::find($fresh->id), 'fresh file should survive');
    }

    public function test_does_not_touch_collections_without_ttl(): void
    {
        // 'gallery' viene del TestCase y NO tiene ttl_hours.
        $old = $this->makeFile('gallery', age: 1000);

        $this->artisan(PurgeExpiredFilesCommand::class)->assertSuccessful();

        $this->assertNotNull(File::find($old->id), 'gallery file without ttl must survive');
    }

    public function test_dry_run_does_not_delete(): void
    {
        $expired = $this->makeFile('temp_uploads', age: 50);

        $this->artisan(PurgeExpiredFilesCommand::class, ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertNotNull(File::find($expired->id), 'dry-run must not delete');
    }

    public function test_collection_filter_limits_scope(): void
    {
        $tempExpired   = $this->makeFile('temp_uploads', age: 50);
        $exportExpired = $this->makeFile('exports', age: 5);  // 5 h > 1 h ttl

        $this->artisan(PurgeExpiredFilesCommand::class, ['--collection' => 'exports'])
            ->assertSuccessful();

        $this->assertNotNull(File::find($tempExpired->id), 'other collection must survive when --collection given');
        $this->assertNull(File::withTrashed()->find($exportExpired->id), 'specified collection must be purged');
    }

    public function test_purges_soft_deleted_files_too(): void
    {
        $softDeletedAndExpired = $this->makeFile('temp_uploads', age: 50);
        $softDeletedAndExpired->delete();

        $this->artisan(PurgeExpiredFilesCommand::class)->assertSuccessful();

        $this->assertNull(
            File::withTrashed()->find($softDeletedAndExpired->id),
            'soft-deleted expired files must be force-deleted'
        );
    }

    public function test_does_not_force_delete_variants_directly(): void
    {
        // El padre expirado se borra, y el FileObserver::deleting cascadea
        // a los hijos. El comando no debe procesar hijos directamente para
        // no duplicar el delete.
        $parent = $this->makeFile('temp_uploads', age: 50);

        $variant = File::create([
            'parent_id'         => $parent->id,
            'variant'           => 'thumbnail',
            'disk'              => 'media',
            'path'              => 'test',
            'name'              => 'thumb-' . uniqid() . '.jpg',
            'mime_type'         => 'image/jpeg',
            'size'              => 100,
            'collection'        => 'temp_uploads',
            'type'              => FileType::IMAGE,
            'access'            => 'public',
            'processing_status' => ProcessingStatus::COMPLETED,
            'created_at'        => now()->subHours(50),
        ]);

        $this->artisan(PurgeExpiredFilesCommand::class)->assertSuccessful();

        $this->assertNull(File::withTrashed()->find($parent->id));
        $this->assertNull(File::withTrashed()->find($variant->id));
    }

    /* ---------------------------------------------------------------- */

    protected function makeFile(string $collection, int $age): File
    {
        $type = match ($collection) {
            'exports' => FileType::DOCUMENT,
            default   => FileType::IMAGE,
        };

        $file = File::create([
            'disk'              => 'media',
            'path'              => 'test',
            'name'              => 'sample-' . uniqid() . '.bin',
            'mime_type'         => 'application/octet-stream',
            'size'              => 100,
            'collection'        => $collection,
            'type'              => $type,
            'access'            => 'public',
            'processing_status' => ProcessingStatus::COMPLETED,
        ]);

        // Backdatear created_at saltándose timestamps.
        $file->timestamps = false;
        $file->created_at = Carbon::now()->subHours($age);
        $file->save();
        $file->timestamps = true;

        return $file;
    }
}
