<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use Aws\Command;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use EduLazaro\Laracrate\Actions\Multipart\AbortMultipartUploadAction;
use EduLazaro\Laracrate\Actions\Multipart\CompleteMultipartUploadAction;
use EduLazaro\Laracrate\Actions\Multipart\GeneratePartUrlsAction;
use EduLazaro\Laracrate\Actions\Multipart\InitiateMultipartUploadAction;
use EduLazaro\Laracrate\Console\Commands\AbortStaleMultipartCommand;
use EduLazaro\Laracrate\Enums\MultipartUploadStatus;
use EduLazaro\Laracrate\Models\MultipartUpload;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use RuntimeException;

class MultipartUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_initiate_creates_session_and_calls_provider(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result(['UploadId' => 'fake-upload-1', 'Bucket' => 'b', 'Key' => 'k']));
        $this->fakeS3Client('s3test', $mock);

        $upload = InitiateMultipartUploadAction::create()->run([
            'disk'         => 's3test',
            'key'          => 'cases/42/videos/foo.mp4',
            'mime'         => 'video/mp4',
            'expectedSize' => 25 * 1024 * 1024,  // 25 MB → 5 partes con part_size 5 MB
            'partSize'     => 5 * 1024 * 1024,
        ]);

        $this->assertSame('fake-upload-1', $upload->upload_id);
        $this->assertSame(MultipartUploadStatus::ACTIVE, $upload->status);
        $this->assertSame(5, $upload->total_parts);
        $this->assertSame(5 * 1024 * 1024, $upload->part_size);
        $this->assertNotNull($upload->expires_at);
    }

    public function test_initiate_rejects_part_size_below_5mb(): void
    {
        $this->fakeS3Client('s3test', new MockHandler());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('minimum part_size in S3 is 5 MB');

        InitiateMultipartUploadAction::create()->run([
            'disk'         => 's3test',
            'key'          => 'foo',
            'expectedSize' => 100 * 1024 * 1024,
            'partSize'     => 1024 * 1024,
        ]);
    }

    public function test_initiate_rejects_too_many_parts(): void
    {
        $this->fakeS3Client('s3test', new MockHandler());

        // 100 GB / 5 MB = 20.000 partes → > 10.000 que es el máximo de S3.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not allow more than 10,000 parts');

        InitiateMultipartUploadAction::create()->run([
            'disk'         => 's3test',
            'key'          => 'foo',
            'expectedSize' => 100 * 1024 * 1024 * 1024,  // 100 GB
            'partSize'     => 5 * 1024 * 1024,
        ]);
    }

    public function test_initiate_rejects_non_s3_disk(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not S3-compatible');

        InitiateMultipartUploadAction::create()->run([
            'disk'         => 'media',  // local driver del TestCase
            'key'          => 'foo',
            'expectedSize' => 50 * 1024 * 1024,
        ]);
    }

    public function test_generate_part_urls_returns_one_per_requested_part(): void
    {
        $this->fakeS3Client('s3test', new MockHandler());

        $upload = $this->makeActiveUpload('s3test');

        $urls = GeneratePartUrlsAction::create()->run([
            'upload'      => $upload,
            'partNumbers' => [1, 3, 5],
        ]);

        $this->assertCount(3, $urls);
        $this->assertSame([1, 3, 5], array_column($urls, 'part_number'));
        $this->assertSame(['PUT', 'PUT', 'PUT'], array_column($urls, 'method'));
        foreach ($urls as $u) {
            $this->assertNotEmpty($u['url']);
        }
    }

    public function test_generate_part_urls_validates_range(): void
    {
        $this->fakeS3Client('s3test', new MockHandler());

        $upload = $this->makeActiveUpload('s3test', totalParts: 5);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('out of range');

        GeneratePartUrlsAction::create()->run([
            'upload'      => $upload,
            'partNumbers' => [99],
        ]);
    }

    public function test_complete_marks_upload_completed_and_calls_provider(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result(['ETag' => '"final-etag"']));
        $this->fakeS3Client('s3test', $mock);

        $upload = $this->makeActiveUpload('s3test');

        $result = CompleteMultipartUploadAction::create()->run([
            'upload' => $upload,
            'parts'  => [
                ['part_number' => 2, 'etag' => '"e2"'],
                ['part_number' => 1, 'etag' => '"e1"'],
            ],
        ]);

        $this->assertSame(MultipartUploadStatus::COMPLETED, $result->status);
        $this->assertNotNull($result->completed_at);
    }

    public function test_complete_rejects_already_terminal_upload(): void
    {
        $this->fakeS3Client('s3test', new MockHandler());

        $upload = $this->makeActiveUpload('s3test');
        $upload->forceFill(['status' => MultipartUploadStatus::COMPLETED])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not active');

        CompleteMultipartUploadAction::create()->run([
            'upload' => $upload,
            'parts'  => [['part_number' => 1, 'etag' => '"e"']],
        ]);
    }

    public function test_abort_marks_session_aborted_and_calls_provider(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result([]));
        $this->fakeS3Client('s3test', $mock);

        $upload = $this->makeActiveUpload('s3test');

        $result = AbortMultipartUploadAction::create()->run(['upload' => $upload]);

        $this->assertSame(MultipartUploadStatus::ABORTED, $result->status);
        $this->assertNotNull($result->aborted_at);
    }

    public function test_abort_with_reason_expired(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result([]));
        $this->fakeS3Client('s3test', $mock);

        $upload = $this->makeActiveUpload('s3test');

        $result = AbortMultipartUploadAction::create()->run([
            'upload' => $upload,
            'reason' => MultipartUploadStatus::EXPIRED,
        ]);

        $this->assertSame(MultipartUploadStatus::EXPIRED, $result->status);
    }

    public function test_abort_swallows_provider_errors_to_preserve_local_cleanup(): void
    {
        $mock = new MockHandler();
        $mock->append(function () {
            throw new \Aws\S3\Exception\S3Exception('NoSuchUpload', new Command('AbortMultipartUpload'));
        });
        $this->fakeS3Client('s3test', $mock);

        $upload = $this->makeActiveUpload('s3test');

        $result = AbortMultipartUploadAction::create()->run(['upload' => $upload]);

        // Aunque S3 falle, el row local queda marcado.
        $this->assertSame(MultipartUploadStatus::ABORTED, $result->status);
    }

    public function test_stale_scope_finds_only_expired_active_uploads(): void
    {
        $this->fakeS3Client('s3test', new MockHandler());

        // Activo pero NO expirado.
        $this->makeActiveUpload('s3test', expiresAt: now()->addHours(2));
        // Activo y expirado → debe aparecer.
        $stale = $this->makeActiveUpload('s3test', expiresAt: now()->subHour());
        // Completado y expirado → NO debe aparecer.
        $completed = $this->makeActiveUpload('s3test', expiresAt: now()->subHour());
        $completed->forceFill(['status' => MultipartUploadStatus::COMPLETED])->save();

        $found = MultipartUpload::stale()->get();

        $this->assertCount(1, $found);
        $this->assertSame($stale->id, $found->first()->id);
    }

    public function test_cron_command_aborts_stale_uploads(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result([]));  // primera AbortMultipartUpload
        $mock->append(new Result([]));  // segunda
        $this->fakeS3Client('s3test', $mock);

        $this->makeActiveUpload('s3test', expiresAt: now()->subHour());
        $this->makeActiveUpload('s3test', expiresAt: now()->subHours(3));
        $this->makeActiveUpload('s3test', expiresAt: now()->addHour());  // no expirada

        $this->artisan(AbortStaleMultipartCommand::class)
            ->assertSuccessful();

        $this->assertSame(2, MultipartUpload::where('status', MultipartUploadStatus::EXPIRED)->count());
        $this->assertSame(1, MultipartUpload::active()->count());
    }

    public function test_cron_command_dry_run_does_not_modify(): void
    {
        $this->fakeS3Client('s3test', new MockHandler());

        $this->makeActiveUpload('s3test', expiresAt: now()->subHour());

        $this->artisan(AbortStaleMultipartCommand::class, ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(1, MultipartUpload::active()->count());
        $this->assertSame(0, MultipartUpload::where('status', MultipartUploadStatus::EXPIRED)->count());
    }

    /* ---------------------------------------------------------------- */

    /**
     * Configura un disk fake con driver=s3 y un S3Client mock para que
     * `Storage::disk('s3test')->getClient()` devuelva nuestro cliente fake.
     */
    protected function fakeS3Client(string $disk, MockHandler $handler): void
    {
        config()->set("filesystems.disks.{$disk}", [
            'driver'   => 's3',
            'bucket'   => 'test-bucket',
            'region'   => 'auto',
            'endpoint' => 'http://localhost:9000',
            'key'      => 'k',
            'secret'   => 's',
        ]);

        $client = new S3Client([
            'region'      => 'auto',
            'version'     => 'latest',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'endpoint'    => 'http://localhost:9000',
            'handler'     => $handler,
        ]);

        // Wrapper alrededor de StorageManager: forzamos el cliente fake.
        $manager = $this->app->make(StorageManager::class);
        $fake = new class($client) extends StorageManager {
            public function __construct(private S3Client $fake) {}
            public function s3ClientOf(string $disk): ?S3Client { return $this->fake; }
        };

        $this->app->instance(StorageManager::class, $fake);
    }

    protected function makeActiveUpload(string $disk, int $totalParts = 5, ?\Illuminate\Support\Carbon $expiresAt = null): MultipartUpload
    {
        return MultipartUpload::create([
            'upload_id'     => 'upload-' . uniqid(),
            'disk'          => $disk,
            'key'           => 'test/' . uniqid() . '.bin',
            'mime_type'     => 'application/octet-stream',
            'expected_size' => $totalParts * 5 * 1024 * 1024,
            'part_size'     => 5 * 1024 * 1024,
            'total_parts'   => $totalParts,
            'status'        => MultipartUploadStatus::ACTIVE,
            'expires_at'    => $expiresAt ?? now()->addHour(),
        ]);
    }
}
