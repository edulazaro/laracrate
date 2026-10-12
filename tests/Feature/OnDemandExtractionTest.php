<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Actions\Files\ProcessFileAction;
use EduLazaro\Laracrate\Actions\Files\RequestExtractionAction;
use EduLazaro\Laracrate\Contracts\EmbeddingProvider;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Enums\ProcessingStatus;
use EduLazaro\Laracrate\Extractors\PlainTextExtractor;
use EduLazaro\Laracrate\Jobs\ProcessFileJob;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\ExtractionResolver;
use EduLazaro\Laracrate\Support\FileActionRegistry;
use EduLazaro\Laracrate\Support\TextExtractorRegistry;
use EduLazaro\Laracrate\Tests\Support\HasFilesTestModel;
use EduLazaro\Laracrate\Tests\Support\RecordingStep;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

class OnDemandExtractionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('laracrate.collections.docs', [
            'disk'    => 'media',
            'access'  => 'public',
            'extract' => 'on_demand',
            'embed'   => true,
        ]);
        config()->set('laracrate.embeddings.enabled', true);
        config()->set('laracrate.embeddings.extractors', [PlainTextExtractor::class]);
        config()->set('laracrate.embeddings.chunk_size', 1000);

        $this->app->forgetInstance(TextExtractorRegistry::class);

        $this->app->bind(EmbeddingProvider::class, fn () => new class implements EmbeddingProvider {
            public function embed(array $texts): array
            {
                return array_map(fn () => [0.1, 0.2, 0.3], $texts);
            }
            public function dimensions(): int { return 3; }
            public function model(): string { return 'fake'; }
            public function name(): string { return 'fake'; }
        });
    }

    private function makeTextFile(string $path): File
    {
        Storage::disk('media')->put($path, str_repeat('Transcript of a long hearing that nobody asked to index yet. ', 5));

        return File::create([
            'fileable_type' => 'test_owner',
            'fileable_id'   => 1,
            'disk'          => 'media',
            'path'          => $path,
            'name'          => basename($path),
            'original_name' => basename($path),
            'extension'     => 'txt',
            'mime_type'     => 'text/plain',
            'size'          => 300,
            'context'       => 'media',
            'collection'    => 'docs',
            'type'          => FileType::DOCUMENT,
            'access'        => 'public',
        ]);
    }

    public function test_upload_skips_the_text_pipeline_until_requested(): void
    {
        Storage::fake('media');
        $file = $this->makeTextFile('docs/hearing.txt');

        ProcessFileAction::create()->run(['file' => $file]);

        $this->assertFalse(Storage::disk('media')->exists('docs/hearing.txt.json'));
        $this->assertTrue($file->refresh()->canRequestExtraction());

        $requester = HasFilesTestModel::create(['name' => 'Ana']);
        $this->assertTrue($file->requestExtraction($requester, sync: true));

        $file->refresh();
        $this->assertTrue(Storage::disk('media')->exists('docs/hearing.txt.json'));
        $this->assertTrue($file->hasEmbeddings());
        $this->assertSame(ProcessingStatus::COMPLETED, $file->processing_status);
        $this->assertNotEmpty($file->metadata[ExtractionResolver::REQUESTED_AT]);
        $this->assertSame($requester->getMorphClass(), $file->metadata['extraction_requested_by_type']);
        $this->assertSame($requester->getKey(), $file->metadata['extraction_requested_by_id']);
        $this->assertFalse($file->canRequestExtraction());
    }

    public function test_request_is_queued_from_the_extraction_band(): void
    {
        Storage::fake('media');
        $file = $this->makeTextFile('docs/queued.txt');
        Bus::fake();

        $this->assertTrue($file->requestExtraction());

        Bus::assertDispatched(ProcessFileJob::class, fn (ProcessFileJob $job) =>
            $job->file->is($file) && $job->fromPriority === RequestExtractionAction::FROM_PRIORITY);
        $this->assertSame(ProcessingStatus::PENDING, $file->refresh()->processing_status);
    }

    public function test_request_is_refused_when_nothing_is_on_demand(): void
    {
        Storage::fake('media');
        config()->set('laracrate.collections.docs.extract', 'always');
        $file = $this->makeTextFile('docs/always.txt');
        Bus::fake();

        $this->assertFalse($file->requestExtraction());

        Bus::assertNotDispatched(ProcessFileJob::class);
    }

    public function test_process_from_priority_skips_the_earlier_steps(): void
    {
        $registry = new FileActionRegistry();
        $this->app->instance(FileActionRegistry::class, $registry);
        RecordingStep::$calls = [];

        $registry->add(new RecordingStep('transcode', 25));
        $registry->add(new RecordingStep('variants', 40));
        $registry->add(new RecordingStep('extract', 60));
        $registry->add(new RecordingStep('embed', 80));

        Storage::fake('media');
        $file = $this->makeTextFile('docs/steps.txt');

        ProcessFileAction::create()->run(['file' => $file, 'fromPriority' => 60]);

        $this->assertSame(['extract', 'embed'], RecordingStep::$calls);
    }
}
