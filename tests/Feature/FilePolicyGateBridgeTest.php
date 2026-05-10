<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Enums\ProcessingStatus;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\PolicyRegistry;
use EduLazaro\Laracrate\Tests\Support\HasFilesTestModel;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

class FilePolicyGateBridgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_gate_view_matches_file_can_view_for_creator(): void
    {
        $alice = HasFilesTestModel::create(['name' => 'alice']);
        $file  = $this->makeFile(creator: $alice);

        $this->assertTrue($file->canView($alice));
        $this->assertTrue(Gate::forUser($alice)->allows('view', $file));
    }

    public function test_gate_view_matches_for_public_access_with_anonymous(): void
    {
        $file = $this->makeFile(access: 'public');

        $this->assertTrue($file->canView(null));
        $this->assertTrue(Gate::forUser(null)->allows('view', $file));
    }

    public function test_gate_view_matches_when_registered_callback_grants(): void
    {
        $alice = HasFilesTestModel::create(['name' => 'alice']);
        $file  = $this->makeFile(access: 'signed');  // not public, not creator

        // Registra una callback que concede a todo el mundo para este fileable_type.
        $file->forceFill([
            'fileable_type' => 'test_owner',
            'fileable_id'   => $alice->getKey(),
        ])->save();

        app(PolicyRegistry::class)->viewable('test_owner', fn ($f, $u) => $u !== null);

        $this->assertTrue($file->canView($alice));
        $this->assertTrue(Gate::forUser($alice)->allows('view', $file));

        $this->assertFalse($file->canView(null));
        $this->assertFalse(Gate::forUser(null)->allows('view', $file));
    }

    public function test_gate_update_matches_can_edit(): void
    {
        $alice = HasFilesTestModel::create(['name' => 'alice']);
        $bob   = HasFilesTestModel::create(['name' => 'bob']);
        $file  = $this->makeFile(creator: $alice);

        $file->forceFill([
            'fileable_type' => 'test_owner',
            'fileable_id'   => $alice->getKey(),
        ])->save();

        app(PolicyRegistry::class)->editable('test_owner', fn ($f, $u) => $u?->name === 'bob');

        // Alice por ser creator.
        $this->assertTrue($file->canEdit($alice));
        $this->assertTrue(Gate::forUser($alice)->allows('update', $file));

        // Bob por la callback registrada.
        $this->assertTrue($file->canEdit($bob));
        $this->assertTrue(Gate::forUser($bob)->allows('update', $file));

        // Anónimo: no creator, callback devuelve false.
        $this->assertFalse($file->canEdit(null));
        $this->assertFalse(Gate::forUser(null)->allows('update', $file));
    }

    public function test_gate_delete_matches_can_delete(): void
    {
        $alice = HasFilesTestModel::create(['name' => 'alice']);
        $bob   = HasFilesTestModel::create(['name' => 'bob']);
        $file  = $this->makeFile(creator: $alice);

        $this->assertTrue(Gate::forUser($alice)->allows('delete', $file));
        $this->assertSame($file->canDelete($bob), Gate::forUser($bob)->allows('delete', $file));
    }

    public function test_no_callback_and_no_creator_denies_via_gate(): void
    {
        $stranger = HasFilesTestModel::create(['name' => 'stranger']);
        $file     = $this->makeFile(access: 'signed');  // not public

        // Sin callback registrada, no creator → false.
        $this->assertFalse($file->canView($stranger));
        $this->assertFalse(Gate::forUser($stranger)->allows('view', $file));
    }

    /* ---------------------------------------------------------------- */

    protected function makeFile(?HasFilesTestModel $creator = null, string $access = 'signed'): File
    {
        return File::create([
            'disk'              => 'media',
            'path'              => 'test',
            'name'              => 'sample-' . uniqid() . '.bin',
            'mime_type'         => 'application/octet-stream',
            'size'              => 100,
            'collection'        => 'gallery',
            'type'              => FileType::IMAGE,
            'access'            => $access,
            'creator_type'      => $creator !== null ? 'user' : null,
            'creator_id'        => $creator?->getKey(),
            'processing_status' => ProcessingStatus::COMPLETED,
        ]);
    }
}
