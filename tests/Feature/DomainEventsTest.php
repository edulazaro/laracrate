<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Actions\Files\ProcessFileAction;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Enums\ProcessingStatus;
use EduLazaro\Laracrate\Events\EmbeddingsReady;
use EduLazaro\Laracrate\Events\FileProcessed;
use EduLazaro\Laracrate\Events\FileProcessingFailed;
use EduLazaro\Laracrate\Events\FileProcessingStarted;
use EduLazaro\Laracrate\Events\VariantGenerated;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\ProcessingPipelineRegistry;
use EduLazaro\Laracrate\Tests\Support\ExplodingStep;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

class DomainEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_started_and_processed_on_success(): void
    {
        Event::fake([FileProcessingStarted::class, FileProcessed::class, FileProcessingFailed::class]);

        $registry = new ProcessingPipelineRegistry();
        $this->app->instance(ProcessingPipelineRegistry::class, $registry); // pipeline vacío

        $file = $this->makeFile(FileType::IMAGE);

        ProcessFileAction::create()->run(['file' => $file]);

        Event::assertDispatched(FileProcessingStarted::class, fn ($e) => $e->file->is($file));
        Event::assertDispatched(FileProcessed::class,        fn ($e) => $e->file->is($file));
        Event::assertNotDispatched(FileProcessingFailed::class);
    }

    public function test_dispatches_failed_when_step_throws(): void
    {
        Event::fake([FileProcessingStarted::class, FileProcessed::class, FileProcessingFailed::class]);

        $registry = new ProcessingPipelineRegistry();
        $this->app->instance(ProcessingPipelineRegistry::class, $registry);
        $registry->add(new ExplodingStep('boom', 10));

        $file = $this->makeFile(FileType::IMAGE);

        try {
            ProcessFileAction::create()->run(['file' => $file]);
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        Event::assertDispatched(FileProcessingStarted::class);
        Event::assertNotDispatched(FileProcessed::class);
        Event::assertDispatched(FileProcessingFailed::class, function ($e) use ($file) {
            return $e->file->is($file)
                && $e->exception instanceof \RuntimeException
                && $e->exception->getMessage() === 'boom';
        });
    }

    public function test_dispatches_variant_generated_when_child_file_is_created(): void
    {
        Event::fake([VariantGenerated::class]);

        $parent = $this->makeFile(FileType::IMAGE);

        $variant = File::create([
            'parent_id'  => $parent->id,
            'variant'    => 'thumbnail',
            'disk'       => 'media',
            'path'       => 'test',
            'name'       => 'thumb.jpg',
            'mime_type'  => 'image/jpeg',
            'size'       => 1,
            'collection' => 'gallery',
            'type'       => FileType::IMAGE,
            'access'     => 'public',
            'processing_status' => ProcessingStatus::COMPLETED,
        ]);

        Event::assertDispatched(VariantGenerated::class, function ($e) use ($variant, $parent) {
            return $e->variant->is($variant) && $e->parent?->is($parent);
        });
    }

    public function test_does_not_dispatch_variant_generated_for_top_level_files(): void
    {
        Event::fake([VariantGenerated::class]);

        $this->makeFile(FileType::IMAGE);

        Event::assertNotDispatched(VariantGenerated::class);
    }

    public function test_processing_status_is_cast_to_enum(): void
    {
        $registry = new ProcessingPipelineRegistry();
        $this->app->instance(ProcessingPipelineRegistry::class, $registry);

        $file = $this->makeFile(FileType::IMAGE);

        ProcessFileAction::create()->run(['file' => $file]);

        $fresh = $file->refresh();

        $this->assertInstanceOf(ProcessingStatus::class, $fresh->processing_status);
        $this->assertSame(ProcessingStatus::COMPLETED, $fresh->processing_status);
        $this->assertTrue($fresh->processing_status->isTerminal());
        $this->assertFalse($fresh->processing_status->isInProgress());
    }

    protected function makeFile(FileType $type): File
    {
        return File::create([
            'disk'       => 'media',
            'path'       => 'test',
            'name'       => 'sample.bin',
            'mime_type'  => 'application/octet-stream',
            'size'       => 1,
            'collection' => 'gallery',
            'type'       => $type,
            'access'     => 'public',
        ]);
    }
}

