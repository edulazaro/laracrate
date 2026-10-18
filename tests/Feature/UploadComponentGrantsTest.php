<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Http\Livewire\LaracrateDropzone;
use EduLazaro\Laracrate\Http\Livewire\LaracrateDropzoneDeferred;
use EduLazaro\Laracrate\Http\Livewire\LaracrateDropzoneSingleDeferred;
use EduLazaro\Laracrate\Http\Livewire\LaracrateUploader;
use EduLazaro\Laracrate\Tests\Support\HasFilesTestModel;
use EduLazaro\Laracrate\Tests\Support\TestUser;
use EduLazaro\Laracrate\Tests\TestCase;
use EduLazaro\Laracrate\Uploads\UploadGrant;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The upload components turn their locked props into the grant the browser carries.
 * Exercised on the classes directly: the test harness does not boot Livewire.
 */
class UploadComponentGrantsTest extends TestCase
{
    use RefreshDatabase;

    private HasFilesTestModel $owner;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(\Livewire\Component::class)) {
            $this->markTestSkipped('livewire/livewire is not installed in this package (it is not a dev dependency).');
        }

        Relation::morphMap(['test_user' => TestUser::class]);
        $this->actingAs(TestUser::create(['name' => 'uploader']));
        $this->owner = HasFilesTestModel::create(['name' => 'owner']);
    }

    public function test_a_dropzone_grants_its_target_and_adds_files(): void
    {
        $component = new LaracrateDropzone();
        $component->mount(model: $this->owner, collection: 'gallery', contextKey: 'hero');

        $grant = UploadGrant::fromToken($component->uploadGrant);

        $this->assertSame('gallery', $grant->collection);
        $this->assertSame('hero', $grant->contextKey);
        $this->assertFalse($grant->replace);
        $this->assertSame('direct', $grant->transport);
        $this->assertSame($grant->scope(), $component->uploadScope);
    }

    public function test_single_file_components_replace_and_the_uploader_goes_through_the_server(): void
    {
        $single = new LaracrateDropzoneSingleDeferred();
        $single->mount(model: $this->owner, collection: 'avatar');
        $this->assertTrue(UploadGrant::fromToken($single->uploadGrant)->replace);

        $uploader = new LaracrateUploader();
        $uploader->mount(model: $this->owner, collection: 'avatar');
        $grant = UploadGrant::fromToken($uploader->uploadGrant);
        $this->assertTrue($grant->replace);
        $this->assertSame('server', $grant->transport);
    }

    public function test_the_deferred_dropzone_only_takes_the_slots_it_offers(): void
    {
        $component = new LaracrateDropzoneDeferred();
        $component->mount(model: $this->owner, collection: 'gallery', slotOptions: [
            ['id' => 11, 'name' => 'Front'],
            ['id' => 12, 'name' => 'Back'],
        ]);

        $component->selectedSlotId = 12;
        $component->updatedSelectedSlotId(12);
        $this->assertSame([12], UploadGrant::fromToken($component->uploadGrant)->slots);

        // A slot it does not offer, sent from the browser.
        $component->selectedSlotId = 999;
        $component->updatedSelectedSlotId(999);
        $this->assertSame([], $component->slots);
        $this->assertSame([], UploadGrant::fromToken($component->uploadGrant)->slots);

        $component->setSlots([11, 500]);
        $this->assertSame([11], $component->slots);
    }
}
