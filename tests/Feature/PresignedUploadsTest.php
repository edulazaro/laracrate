<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Tests\Support\HasFilesTestModel;
use EduLazaro\Laracrate\Tests\Support\TestUser;
use EduLazaro\Laracrate\Tests\TestCase;
use EduLazaro\Laracrate\Uploads\PresignedUploads;
use EduLazaro\Laracrate\Uploads\UploadGrant;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The 'presign' mode of the upload components: the component signs the batch, the
 * server keeps where each key goes, and the queue registers each object by its key.
 */
class PresignedUploadsTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n%%EOF\n";

    private TestUser $user;
    private HasFilesTestModel $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Relation::morphMap(['test_user' => TestUser::class]);
        Storage::fake('documents');
        Storage::fake('media');

        $this->user = TestUser::create(['name' => 'uploader']);
        $this->owner = HasFilesTestModel::create(['name' => 'owner']);
        $this->actingAs($this->user);
    }

    /** What a component does when files are picked, outside the test's earlier requests. */
    private function sign(array $files, string $collection = 'documents', ?int $room = null): array
    {
        $this->app->instance('request', Request::create('/'));

        return PresignedUploads::sign(UploadGrant::for($this->owner, $collection), $files, $room);
    }

    private function register(string $key)
    {
        return $this->postJson('/laracrate/uploads/register', ['key' => $key]);
    }

    public function test_sign_hands_out_a_temp_key_per_file_and_keeps_its_target(): void
    {
        [$signed] = $this->sign([['name' => 'deal.pdf', 'mime' => 'application/pdf', 'size' => 15]]);

        $this->assertStringStartsWith('temp/', $signed['key']);
        $this->assertNotEmpty($signed['url']);
        $this->assertSame('deal.pdf', PresignedUploads::find($signed['key'])['name']);
    }

    public function test_sign_says_why_a_file_is_refused_and_signs_the_rest(): void
    {
        $signed = $this->sign([
            ['name' => 'page.html', 'mime' => 'text/html', 'size' => 5],
            ['name' => 'big.png', 'mime' => 'image/png', 'size' => 6 * 1024 * 1024],
            ['name' => 'ok.png', 'mime' => 'image/png', 'size' => 100],
        ], 'gallery');

        $this->assertArrayHasKey('error', $signed[0]);
        $this->assertArrayHasKey('error', $signed[1]);
        $this->assertArrayHasKey('key', $signed[2]);
    }

    public function test_sign_takes_no_more_files_than_the_target_has_room_for(): void
    {
        $file = ['name' => 'deal.pdf', 'mime' => 'application/pdf', 'size' => 15];

        $signed = $this->sign([$file, $file, $file], room: 2);

        $this->assertArrayHasKey('key', $signed[1]);
        $this->assertArrayHasKey('error', $signed[2]);
    }

    public function test_register_creates_the_file_in_the_target_kept_at_signing(): void
    {
        [$signed] = $this->sign([['name' => 'deal.pdf', 'mime' => 'application/pdf', 'size' => 1]]);
        Storage::disk('documents')->put($signed['key'], self::PDF);

        $response = $this->register($signed['key'])->assertOk();

        $file = File::find($response->json('id'));
        $this->assertTrue($file->fileable->is($this->owner));
        $this->assertSame('documents', $file->collection);
        $this->assertSame('deal.pdf', $file->original_name);
        $this->assertSame(strlen(self::PDF), $file->size);
        $this->assertSame('test_user', $file->creator_type);
        Storage::disk('documents')->assertMissing($signed['key']);
    }

    public function test_a_key_is_registered_once(): void
    {
        [$signed] = $this->sign([['name' => 'deal.pdf', 'mime' => 'application/pdf', 'size' => 15]]);
        Storage::disk('documents')->put($signed['key'], self::PDF);

        $this->register($signed['key'])->assertOk();
        $this->register($signed['key'])->assertStatus(403);
        $this->assertSame(1, File::count());
    }

    public function test_a_key_nobody_signed_is_refused(): void
    {
        Storage::disk('documents')->put('temp/01ABC_planted.pdf', self::PDF);

        $this->register('temp/01ABC_planted.pdf')->assertStatus(403);
        $this->assertSame(0, File::count());
    }

    public function test_another_user_cannot_register_a_key_someone_else_was_signed(): void
    {
        [$signed] = $this->sign([['name' => 'deal.pdf', 'mime' => 'application/pdf', 'size' => 15]]);
        Storage::disk('documents')->put($signed['key'], self::PDF);

        $this->actingAs(TestUser::create(['name' => 'someone else']));

        $this->register($signed['key'])->assertStatus(403);
        $this->assertSame(0, File::count());
    }

    public function test_register_checks_the_size_the_bucket_holds(): void
    {
        // Signed as 100 bytes for gallery (images up to 5 MB); 6 MB put.
        [$signed] = $this->sign([['name' => 'photo.png', 'mime' => 'image/png', 'size' => 100]], 'gallery');
        Storage::disk('media')->put($signed['key'], str_repeat('x', 6 * 1024 * 1024));

        $this->register($signed['key'])->assertStatus(422);

        $this->assertSame(0, File::count());
        Storage::disk('media')->assertMissing($signed['key']);
    }

    public function test_register_before_the_bytes_arrive_can_be_tried_again(): void
    {
        [$signed] = $this->sign([['name' => 'deal.pdf', 'mime' => 'application/pdf', 'size' => 15]]);

        $this->register($signed['key'])->assertStatus(422);

        Storage::disk('documents')->put($signed['key'], self::PDF);
        $this->register($signed['key'])->assertOk();
    }
}
