<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Enums\ProcessingStatus;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\UsageReporter;
use EduLazaro\Laracrate\Support\UsageStats;
use EduLazaro\Laracrate\Tests\Support\HasFilesTestModel;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UsageReporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_for_tenant_aggregates_bytes_and_files(): void
    {
        $tenant = HasFilesTestModel::create(['name' => 'org-1']);
        $other  = HasFilesTestModel::create(['name' => 'org-2']);

        $this->makeFile($tenant, 'gallery', FileType::IMAGE,    1_000);
        $this->makeFile($tenant, 'gallery', FileType::IMAGE,    2_000);
        $this->makeFile($tenant, 'documents', FileType::DOCUMENT, 5_000);
        $this->makeFile($other,  'gallery', FileType::IMAGE,    9_999); // ruido

        $stats = app(UsageReporter::class)->forTenant($tenant);

        $this->assertSame(8_000, $stats->totalBytes);
        $this->assertSame(3,     $stats->totalFiles);

        $this->assertArrayHasKey('gallery',   $stats->byCollection);
        $this->assertArrayHasKey('documents', $stats->byCollection);
        $this->assertSame(3_000, $stats->byCollection['gallery']['bytes']);
        $this->assertSame(2,     $stats->byCollection['gallery']['files']);
        $this->assertSame(5_000, $stats->byCollection['documents']['bytes']);

        $this->assertSame(3_000, $stats->byType['image']['bytes']);
        $this->assertSame(5_000, $stats->byType['document']['bytes']);
    }

    public function test_for_collection_filters_by_collection_and_optional_tenant(): void
    {
        $tenant = HasFilesTestModel::create(['name' => 'org-1']);

        $this->makeFile($tenant, 'gallery',   FileType::IMAGE, 100);
        $this->makeFile($tenant, 'documents', FileType::DOCUMENT, 200);
        $this->makeFile(null,    'gallery',   FileType::IMAGE, 300); // sin tenant

        $reporter = app(UsageReporter::class);

        $allGallery     = $reporter->forCollection('gallery');
        $tenantGallery  = $reporter->forCollection('gallery', $tenant);

        $this->assertSame(400, $allGallery->totalBytes);
        $this->assertSame(2,   $allGallery->totalFiles);

        $this->assertSame(100, $tenantGallery->totalBytes);
        $this->assertSame(1,   $tenantGallery->totalFiles);
    }

    public function test_for_creator_filters_by_creator_morph(): void
    {
        $alice = HasFilesTestModel::create(['name' => 'alice']);
        $bob   = HasFilesTestModel::create(['name' => 'bob']);

        $this->makeFile(null, 'gallery', FileType::IMAGE, 1_000, creator: $alice);
        $this->makeFile(null, 'gallery', FileType::IMAGE, 2_000, creator: $alice);
        $this->makeFile(null, 'gallery', FileType::IMAGE, 9_000, creator: $bob);

        $stats = app(UsageReporter::class)->forCreator($alice);

        $this->assertSame(3_000, $stats->totalBytes);
        $this->assertSame(2,     $stats->totalFiles);
    }

    public function test_global_aggregates_everything(): void
    {
        $this->makeFile(null, 'gallery',   FileType::IMAGE,    1_000);
        $this->makeFile(null, 'documents', FileType::DOCUMENT, 2_000);

        $stats = app(UsageReporter::class)->global();

        $this->assertSame(3_000, $stats->totalBytes);
        $this->assertSame(2,     $stats->totalFiles);
    }

    public function test_includes_soft_deleted_by_default_excludes_when_requested(): void
    {
        $tenant = HasFilesTestModel::create(['name' => 'org-1']);

        $live = $this->makeFile($tenant, 'gallery', FileType::IMAGE, 1_000);
        $dead = $this->makeFile($tenant, 'gallery', FileType::IMAGE, 4_000);
        $dead->delete(); // soft delete — sigue en bucket

        $reporter = app(UsageReporter::class);

        $withTrashed    = $reporter->forTenant($tenant);
        $withoutTrashed = $reporter->forTenant($tenant, excludeTrashed: true);

        // Default: incluye el soft-deleted (es lo que factura el provider).
        $this->assertSame(5_000, $withTrashed->totalBytes);
        $this->assertSame(2,     $withTrashed->totalFiles);

        // Con flag: solo activos.
        $this->assertSame(1_000, $withoutTrashed->totalBytes);
        $this->assertSame(1,     $withoutTrashed->totalFiles);
    }

    public function test_usage_stats_helpers(): void
    {
        $stats = new UsageStats(totalBytes: 1_500_000_000);

        $this->assertEqualsWithDelta(1_464_843.75, $stats->kilobytes(), 0.01);
        $this->assertEqualsWithDelta(1_430.51, $stats->megabytes(), 0.01);
        $this->assertEqualsWithDelta(1.40, $stats->gigabytes(), 0.01);

        $this->assertSame('1.40 GB', $stats->human());

        $this->assertTrue($stats->exceeds(1_000_000_000));
        $this->assertFalse($stats->exceeds(2_000_000_000));

        $this->assertSame(500_000_000, $stats->remaining(2_000_000_000));
        $this->assertSame(75.0, $stats->percentageOf(2_000_000_000));
    }

    public function test_human_size_handles_units(): void
    {
        $this->assertSame('512 B',  (new UsageStats(512))->human(0));
        $this->assertSame('2 KB',   (new UsageStats(2 * 1024))->human(0));
        $this->assertSame('5 MB',   (new UsageStats(5 * 1024 * 1024))->human(0));
        $this->assertSame('3 GB',   (new UsageStats(3 * 1024 ** 3))->human(0));
    }

    /* ---------------------------------------------------------------- */

    protected function makeFile(
        ?HasFilesTestModel $tenant,
        string $collection,
        FileType $type,
        int $size,
        ?HasFilesTestModel $creator = null,
    ): File {
        return File::create([
            'disk'              => 'media',
            'path'              => 'test',
            'name'              => 'sample-' . uniqid() . '.bin',
            'mime_type'         => 'application/octet-stream',
            'size'              => $size,
            'collection'        => $collection,
            'type'              => $type,
            'access'            => 'public',
            'tenant_type'       => $tenant?->getMorphClass(),
            'tenant_id'         => $tenant?->getKey(),
            'creator_type'      => $creator?->getMorphClass(),
            'creator_id'        => $creator?->getKey(),
            'processing_status' => ProcessingStatus::COMPLETED,
        ]);
    }
}
