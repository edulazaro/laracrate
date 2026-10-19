<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use EduLazaro\Laracrate\Enums\MultipartUploadStatus;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Models\MultipartUpload;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Tests\Support\HasFilesTestModel;
use EduLazaro\Laracrate\Tests\Support\TestUser;
use EduLazaro\Laracrate\Tests\TestCase;
use EduLazaro\Laracrate\Uploads\UploadGrant;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The multipart endpoints under an UploadGrant: the target only ever comes from the
 * grant, the collection rules run before and after the upload, and a session only
 * answers to the grant that started it.
 */
class MultipartEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private const MB = 1024 * 1024;

    private TestUser $user;
    private HasFilesTestModel $owner;
    private MockHandler $s3;

    protected function setUp(): void
    {
        parent::setUp();

        Relation::morphMap(['test_user' => TestUser::class]);
        Storage::fake('documents');
        Storage::fake('media');

        $this->user = TestUser::create(['name' => 'uploader']);
        $this->owner = HasFilesTestModel::create(['name' => 'owner']);
        $this->actingAs($this->user);

        // The provider calls go to a mock; the assembled object is put on the fake disk.
        config()->set('filesystems.disks.documents.bucket', 'test-bucket');
        config()->set('filesystems.disks.media.bucket', 'test-bucket');
        $this->s3 = new MockHandler();
        $client = new S3Client([
            'region' => 'auto', 'version' => 'latest', 'endpoint' => 'http://localhost:9000',
            'credentials' => ['key' => 'k', 'secret' => 's'], 'handler' => $this->s3,
        ]);
        $this->app->instance(StorageManager::class, new class($client) extends StorageManager {
            public function __construct(private S3Client $fake) {}
            public function s3ClientOf(string $disk): ?S3Client { return $this->fake; }
        });
    }

    private function grant(string $collection = 'documents', ?int $maxFiles = null): string
    {
        // Issued outside the test's earlier requests: each of those got a new session,
        // since a test request keeps no cookie the way a browser does.
        $this->app->instance('request', Request::create('/'));

        return UploadGrant::for($this->owner, $collection, maxFiles: $maxFiles)->token();
    }

    private function init(string $grant, string $name = 'hearing.pdf', string $mime = 'application/pdf', int $size = 12 * self::MB, array $extra = [])
    {
        $this->s3->append(new Result(['UploadId' => 'upload-' . uniqid()]));

        return $this->postJson('/laracrate/multipart/init', [
            'grant' => $grant, 'file_name' => $name, 'mime' => $mime, 'size' => $size,
        ] + $extra);
    }

    private function complete(string $grant, int $id)
    {
        $this->s3->append(new Result([]));

        return $this->postJson("/laracrate/multipart/{$id}/complete", [
            'grant' => $grant,
            'parts' => [['part_number' => 1, 'etag' => '"a"'], ['part_number' => 2, 'etag' => '"b"']],
        ]);
    }

    public function test_init_takes_the_target_from_the_grant_and_not_from_the_request(): void
    {
        $response = $this->init($this->grant(), extra: [
            'disk' => 'media', 'fileable_type' => 'test_user', 'fileable_id' => $this->user->id, 'collection' => 'avatar',
        ])->assertOk();

        $session = MultipartUpload::find($response->json('id'));
        $this->assertSame('documents', $session->disk);
        $this->assertStringStartsWith('test_owner/' . $this->owner->id . '/documents/', $session->key);
        $this->assertSame('documents', $session->collection);
        $this->assertSame((string) $this->owner->id, (string) $session->fileable_id);
        $this->assertCount($session->total_parts, $response->json('parts'));
    }

    public function test_the_routes_inherit_the_uploads_middleware_when_theirs_is_null(): void
    {
        // The shipped config: a null that config()'s default used to keep, leaving the
        // routes with no middleware at all.
        $this->assertNull(config('laracrate.multipart.middleware'));

        foreach (['init', 'parts', 'complete', 'abort'] as $route) {
            $this->assertSame(
                config('laracrate.uploads.middleware'),
                app('router')->getRoutes()->getByName("laracrate.multipart.{$route}")->middleware(),
            );
        }
    }

    public function test_init_without_a_grant_is_refused(): void
    {
        $this->postJson('/laracrate/multipart/init', [
            'disk' => 'media', 'file_name' => 'page.html', 'mime' => 'text/html', 'size' => 12 * self::MB,
        ])->assertStatus(422);

        $this->assertSame(0, MultipartUpload::count());
    }

    public function test_init_applies_the_collection_rules_before_opening_a_session(): void
    {
        // gallery takes jpeg, png and webp up to 5 MB.
        $this->postJson('/laracrate/multipart/init', [
            'grant' => $this->grant('gallery'), 'file_name' => 'page.html', 'mime' => 'text/html', 'size' => 1 * self::MB,
        ])->assertStatus(422);

        $this->postJson('/laracrate/multipart/init', [
            'grant' => $this->grant('gallery'), 'file_name' => 'big.png', 'mime' => 'image/png', 'size' => 6 * self::MB,
        ])->assertStatus(422);

        $this->assertSame(0, MultipartUpload::count());
    }

    public function test_init_counts_against_the_grant_file_limit(): void
    {
        $grant = $this->grant(maxFiles: 1);

        $this->init($grant, extra: ['upload' => 'a'])->assertOk();
        $this->postJson('/laracrate/multipart/init', [
            'grant' => $grant, 'upload' => 'b', 'file_name' => 'other.pdf', 'mime' => 'application/pdf', 'size' => 12 * self::MB,
        ])->assertStatus(422);

        $this->assertSame(1, MultipartUpload::count());
    }

    public function test_a_grant_cannot_be_used_by_another_user(): void
    {
        $grant = $this->grant();
        $this->actingAs(TestUser::create(['name' => 'someone else']));

        $this->init($grant)->assertStatus(403);
        $this->assertSame(0, MultipartUpload::count());
    }

    public function test_complete_creates_the_file_with_the_size_the_bucket_holds(): void
    {
        $grant = $this->grant();
        $session = MultipartUpload::find($this->init($grant)->json('id'));
        Storage::disk('documents')->put($session->key, str_repeat('x', 3 * self::MB));

        $response = $this->complete($grant, $session->id)->assertOk();

        $file = File::find($response->json('id'));
        $this->assertTrue($file->fileable->is($this->owner));
        $this->assertSame($session->key, $file->key);
        $this->assertSame(3 * self::MB, $file->size);
        $this->assertSame('hearing.pdf', $file->original_name);
        $this->assertSame('test_user', $file->creator_type);
        $this->assertSame(MultipartUploadStatus::COMPLETED, $session->fresh()->status);
        $this->assertSame($file->id, $session->fresh()->file_id);
    }

    public function test_complete_deletes_an_object_bigger_than_the_collection_allows(): void
    {
        $grant = $this->grant('gallery');
        // Declared 1 MB, assembled 6 MB: no part URL binds a length.
        $session = MultipartUpload::find($this->init($grant, 'photo.png', 'image/png', 1 * self::MB)->json('id'));
        Storage::disk('media')->put($session->key, str_repeat('x', 6 * self::MB));

        $this->complete($grant, $session->id)->assertStatus(422);

        $this->assertSame(0, File::count());
        Storage::disk('media')->assertMissing($session->key);
    }

    public function test_a_session_only_answers_to_the_grant_that_started_it(): void
    {
        $session = MultipartUpload::find($this->init($this->grant())->json('id'));
        $other = $this->grant();

        $this->postJson("/laracrate/multipart/{$session->id}/parts", ['grant' => $other, 'part_numbers' => [1]])->assertStatus(403);
        $this->complete($other, $session->id)->assertStatus(403);
        $this->deleteJson("/laracrate/multipart/{$session->id}", ['grant' => $other])->assertStatus(403);

        $this->assertSame(MultipartUploadStatus::ACTIVE, $session->fresh()->status);
    }

    public function test_a_session_started_without_a_grant_cannot_be_reached(): void
    {
        // What an application gets when it starts a session with the actions on its own.
        $session = MultipartUpload::create([
            'upload_id' => 'app-upload', 'disk' => 'documents', 'key' => 'exports/report.zip',
            'mime_type' => 'application/zip', 'expected_size' => 10 * self::MB, 'part_size' => 5 * self::MB,
            'total_parts' => 2, 'status' => MultipartUploadStatus::ACTIVE, 'expires_at' => now()->addHour(),
        ]);

        $this->complete($this->grant(), $session->id)->assertStatus(403);
        $this->deleteJson("/laracrate/multipart/{$session->id}", ['grant' => $this->grant()])->assertStatus(403);
    }

    public function test_abort_with_its_grant_closes_the_session(): void
    {
        $grant = $this->grant();
        $session = MultipartUpload::find($this->init($grant)->json('id'));
        $this->s3->append(new Result([]));

        $this->deleteJson("/laracrate/multipart/{$session->id}", ['grant' => $grant])->assertOk();

        $this->assertSame(MultipartUploadStatus::ABORTED, $session->fresh()->status);
    }
}
