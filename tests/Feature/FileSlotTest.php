<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Models\FileSlot;
use EduLazaro\Laracrate\Tests\TestCase;

class FileSlotTest extends TestCase
{
    private function makeFile(array $overrides = []): File
    {
        return File::create(array_merge([
            'fileable_type' => 'test_owner',
            'fileable_id'   => 1,
            'disk'          => 'media',
            'path'          => uniqid('f') . '.bin',
            'name'          => 'f.bin',
            'original_name' => 'f.bin',
            'extension'     => 'bin',
            'mime_type'     => 'application/octet-stream',
            'size'          => 1,
            'context'       => 'media',
            'collection'    => 'gallery',
            'type'          => FileType::IMAGE,
            'access'        => 'public',
        ], $overrides));
    }

    public function test_accepts_extension_is_case_insensitive_and_empty_allows_all(): void
    {
        $slot = FileSlot::create(['name' => 'DNI', 'allowed_extensions' => ['pdf', 'jpg']]);

        $this->assertTrue($slot->acceptsExtension('PDF'));
        $this->assertTrue($slot->acceptsExtension('.jpg'));
        $this->assertFalse($slot->acceptsExtension('exe'));

        $open = FileSlot::create(['name' => 'Any']);
        $this->assertTrue($open->acceptsExtension('whatever'));
    }

    public function test_accepts_type_and_empty_allows_all(): void
    {
        $slot = FileSlot::create(['name' => 'Docs', 'allowed_types' => ['document']]);

        $this->assertTrue($slot->acceptsType('document'));
        $this->assertFalse($slot->acceptsType('image'));

        $open = FileSlot::create(['name' => 'Any']);
        $this->assertTrue($open->acceptsType('image'));
    }

    public function test_uploaded_count_total_and_filtered_by_creator(): void
    {
        $slot = FileSlot::create(['name' => 'Slot']);
        $a = $this->makeFile(['creator_type' => 'user', 'creator_id' => 1]);
        $b = $this->makeFile(['creator_type' => 'user', 'creator_id' => 2]);
        $slot->files()->attach([$a->id, $b->id]);

        $this->assertSame(2, $slot->uploadedCount());
        $this->assertSame(1, $slot->uploadedCount('user', 1));
    }

    public function test_can_accept_more_respects_global_limit(): void
    {
        $slot = FileSlot::create(['name' => 'Slot', 'max_files_total' => 1]);
        $this->assertTrue($slot->canAcceptMore()['can']);

        $slot->files()->attach($this->makeFile()->id);

        $check = $slot->canAcceptMore();
        $this->assertFalse($check['can']);
        $this->assertSame('global', $check['reason']);
        $this->assertSame(1, $check['limit']);
    }

    public function test_can_accept_more_respects_per_creator_limit(): void
    {
        $slot = FileSlot::create(['name' => 'Slot', 'max_files_per_creator' => 1]);
        $slot->files()->attach($this->makeFile(['creator_type' => 'user', 'creator_id' => 7])->id);

        $own = $slot->canAcceptMore('user', 7);
        $this->assertFalse($own['can']);
        $this->assertSame('per_creator', $own['reason']);

        $this->assertTrue($slot->canAcceptMore('user', 8)['can']);
    }
}
