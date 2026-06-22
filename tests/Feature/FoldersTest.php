<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Tests\Support\HasFilesTestModel;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

class FoldersTest extends TestCase
{
    private function makeFile(HasFilesTestModel $owner, ?int $folderId = null): File
    {
        return File::create([
            'fileable_type' => 'test_owner',
            'fileable_id'   => $owner->id,
            'folder_id'     => $folderId,
            'disk'          => 'media',
            'path'          => uniqid('f') . '.bin',
            'name'          => 'f.bin',
            'original_name' => 'f.bin',
            'extension'     => 'bin',
            'mime_type'     => 'application/octet-stream',
            'size'          => 100,
            'context'       => 'media',
            'collection'    => 'gallery',
            'type'          => FileType::IMAGE,
            'access'        => 'public',
        ]);
    }

    public function test_add_folder_creates_root_with_computed_path(): void
    {
        $org    = HasFilesTestModel::create(['name' => 'org']);
        $folder = $org->addFolder('Contracts');

        $this->assertNull($folder->parent_id);
        $this->assertSame('Contracts', $folder->path);
        $this->assertSame('test_owner', $folder->folderable_type);
    }

    public function test_nested_folder_path_is_parent_then_child(): void
    {
        $org       = HasFilesTestModel::create();
        $contracts = $org->addFolder('Contracts');
        $y2025     = $org->addFolder('2025', parent: $contracts);

        $this->assertSame('Contracts/2025', $y2025->path);
    }

    public function test_root_folders_returns_only_roots_ordered_by_name(): void
    {
        $org = HasFilesTestModel::create();
        $org->addFolder('Beta');
        $alpha = $org->addFolder('Alpha');
        $org->addFolder('Inner', parent: $alpha); // not a root

        $this->assertSame(['Alpha', 'Beta'], $org->rootFolders()->pluck('name')->all());
    }

    public function test_renaming_a_folder_cascades_path_to_descendants(): void
    {
        $org       = HasFilesTestModel::create();
        $contracts = $org->addFolder('Contracts');
        $y2025     = $org->addFolder('2025', parent: $contracts);
        $q1        = $org->addFolder('Q1', parent: $y2025);

        $contracts->name = 'Agreements';
        $contracts->save();

        $this->assertSame('Agreements', $contracts->fresh()->path);
        $this->assertSame('Agreements/2025', $y2025->fresh()->path);
        $this->assertSame('Agreements/2025/Q1', $q1->fresh()->path);
    }

    public function test_add_folder_with_parent_from_another_owner_throws(): void
    {
        $org1   = HasFilesTestModel::create();
        $org2   = HasFilesTestModel::create();
        $shared = $org1->addFolder('Shared');

        $this->expectException(\InvalidArgumentException::class);
        $org2->addFolder('Child', parent: $shared);
    }

    public function test_move_file_into_folder_sets_folder_id(): void
    {
        $org    = HasFilesTestModel::create();
        $folder = $org->addFolder('Docs');
        $file   = $this->makeFile($org);

        $file->moveToFolder($folder);

        $this->assertSame($folder->id, $file->fresh()->folder_id);
    }

    public function test_force_delete_recursive_removes_subtree_and_files(): void
    {
        Storage::fake('media');

        $org  = HasFilesTestModel::create();
        $root = $org->addFolder('Drive');
        $sub  = $org->addFolder('Sub', parent: $root);
        $file = $this->makeFile($org, $sub->id);

        $root->forceDeleteRecursive();

        $this->assertDatabaseMissing('laracrate_folders', ['id' => $root->id]);
        $this->assertDatabaseMissing('laracrate_folders', ['id' => $sub->id]);
        $this->assertDatabaseMissing('laracrate_files', ['id' => $file->id]);
    }
}
