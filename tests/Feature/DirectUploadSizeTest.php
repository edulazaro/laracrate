<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Exceptions\UploadRejected;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\FileUpload;
use EduLazaro\Laracrate\Tests\Support\HasFilesTestModel;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/**
 * A direct upload is registered with the size the bucket holds, whatever its caller
 * says: a presigned PUT does not bind the length, and the size in a FileUpload is
 * often what the browser declared.
 */
class DirectUploadSizeTest extends TestCase
{
    use RefreshDatabase;

    private HasFilesTestModel $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('media');
        $this->owner = HasFilesTestModel::create(['name' => 'owner']);
    }

    private function upload(string $disk, string $key, string $mime, int $declared): FileUpload
    {
        return FileUpload::fromArray([
            'disk' => $disk, 'key' => $key, 'mime_type' => $mime,
            'original_name' => basename($key), 'size' => $declared,
        ]);
    }

    public function test_the_file_keeps_the_size_the_bucket_holds(): void
    {
        Storage::disk('documents')->put('temp/01HK_deal.pdf', str_repeat('x', 2048));

        $file = $this->owner->addFile($this->upload('documents', 'temp/01HK_deal.pdf', 'application/pdf', 1), 'documents');

        $this->assertSame(2048, $file->size);
    }

    public function test_an_object_bigger_than_the_collection_allows_is_refused_and_deleted(): void
    {
        // gallery takes images up to 5 MB: declared 100 bytes, 6 MB in the bucket.
        Storage::disk('media')->put('temp/01HK_big.png', str_repeat('x', 6 * 1024 * 1024));

        try {
            $this->owner->addFile($this->upload('media', 'temp/01HK_big.png', 'image/png', 100), 'gallery');
            $this->fail('A file over the maximum was registered.');
        } catch (UploadRejected $e) {
            $this->assertSame('size', $e->reason);
        }

        $this->assertSame(0, File::count());
        Storage::disk('media')->assertMissing('temp/01HK_big.png');
    }

    public function test_an_object_that_never_arrived_is_refused(): void
    {
        try {
            $this->owner->addFile($this->upload('documents', 'temp/01HK_ghost.pdf', 'application/pdf', 15), 'documents');
            $this->fail('A file with no object was registered.');
        } catch (UploadRejected $e) {
            $this->assertSame('missing', $e->reason);
        }

        $this->assertSame(0, File::count());
    }
}
