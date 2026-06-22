<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Console\Commands\RecomputeUsageCommand;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Tests\Support\HasFilesTestModel;
use EduLazaro\Laracrate\Tests\TestCase;

class RecomputeUsageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A collection that tracks usage counters in laracrate_folderables.
        config()->set('laracrate.collections.drive', [
            'disk'         => 'media',
            'access'       => 'public',
            'track_usage'  => true,
        ]);
    }

    private function makeFile(HasFilesTestModel $owner, int $size): File
    {
        return File::create([
            'fileable_type' => 'test_owner',
            'fileable_id'   => $owner->id,
            'disk'          => 'media',
            'path'          => uniqid('f') . '.bin',
            'name'          => 'f.bin',
            'original_name' => 'f.bin',
            'extension'     => 'bin',
            'mime_type'     => 'application/octet-stream',
            'size'          => $size,
            'context'       => 'media',
            'collection'    => 'drive',
            'type'          => FileType::DOCUMENT,
            'access'        => 'public',
        ]);
    }

    public function test_observer_increments_usage_counters_on_create(): void
    {
        $org = HasFilesTestModel::create();
        $this->makeFile($org, 100);
        $this->makeFile($org, 250);

        $usage = $org->usage('drive');

        $this->assertNotNull($usage);
        $this->assertSame(350, $usage->total_size_bytes);
        $this->assertSame(2, $usage->files_count);
    }

    public function test_command_recomputes_drifted_counters(): void
    {
        $org = HasFilesTestModel::create();
        $this->makeFile($org, 100);

        // Simulate drift (observer failure, manual import, restore from backup).
        $org->usage('drive')->update(['total_size_bytes' => 999_999, 'files_count' => 50]);

        $this->artisan(RecomputeUsageCommand::class)->assertSuccessful();

        $usage = $org->usage('drive')->fresh();
        $this->assertSame(100, $usage->total_size_bytes);
        $this->assertSame(1, $usage->files_count);
    }

    public function test_dry_run_does_not_modify_counters(): void
    {
        $org = HasFilesTestModel::create();
        $this->makeFile($org, 100);
        $org->usage('drive')->update(['total_size_bytes' => 999_999]);

        $this->artisan(RecomputeUsageCommand::class, ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(999_999, $org->usage('drive')->fresh()->total_size_bytes);
    }
}
