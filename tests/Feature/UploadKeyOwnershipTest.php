<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Tests\Support\HasFilesTestModel;
use EduLazaro\Laracrate\Tests\TestCase;
use EduLazaro\Laracrate\Support\FileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/**
 * A key the browser sends back can only become a File of the model it was uploaded
 * for: knowing another model's key must not be enough to claim its object.
 */
class UploadKeyOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function upload(string $key): FileUpload
    {
        return FileUpload::fromArray([
            'disk' => 'documents', 'key' => $key, 'mime_type' => 'application/pdf',
            'original_name' => 'deal.pdf', 'size' => 15,
        ]);
    }

    public function test_the_key_of_another_model_is_refused(): void
    {
        Storage::fake('documents');
        $victim = HasFilesTestModel::create(['name' => 'victim']);
        $attacker = HasFilesTestModel::create(['name' => 'attacker']);
        $key = "test_owner/{$victim->id}/documents/01HK_secret.pdf";
        Storage::disk('documents')->put($key, '%PDF');

        $this->expectException(\InvalidArgumentException::class);
        $attacker->addFile($this->upload($key), 'documents');
    }

    public function test_a_key_under_the_own_path_and_a_temp_key_are_accepted(): void
    {
        Storage::fake('documents');
        $owner = HasFilesTestModel::create(['name' => 'owner']);
        Storage::disk('documents')->put("test_owner/{$owner->id}/documents/01HK_own.pdf", '%PDF');
        Storage::disk('documents')->put('temp/01HK_new.pdf', '%PDF');

        $own = $owner->addFile($this->upload("test_owner/{$owner->id}/documents/01HK_own.pdf"), 'documents');
        $fresh = $owner->addFile($this->upload('temp/01HK_new.pdf'), 'documents');

        $this->assertSame("test_owner/{$owner->id}/documents/01HK_own.pdf", $own->key);
        $this->assertStringStartsWith("test_owner/{$owner->id}/documents/", $fresh->key);
    }

    public function test_a_key_climbing_out_of_its_path_is_refused(): void
    {
        $owner = HasFilesTestModel::create(['name' => 'owner']);

        $this->expectException(\InvalidArgumentException::class);
        $owner->addFile($this->upload("test_owner/{$owner->id}/documents/../../9/documents/x.pdf"), 'documents');
    }
}
