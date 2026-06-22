<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Actions\Files\ProcessFileAction;
use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Enums\ProcessingStatus;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Pipeline\Steps\Image\ExtractImageDimensionsStep;
use EduLazaro\Laracrate\Pipeline\Steps\Image\GenerateImageVariantsStep;
use EduLazaro\Laracrate\Pipeline\Steps\Image\OptimizeImageStep;
use EduLazaro\Laracrate\Pipeline\Steps\Text\ChunkTextStep;
use EduLazaro\Laracrate\Pipeline\Steps\Text\ExtractTextStep;
use EduLazaro\Laracrate\Pipeline\Steps\Text\GenerateEmbeddingStep;
use EduLazaro\Laracrate\Support\FileActionRegistry;
use EduLazaro\Laracrate\Tests\Support\RecordingStep;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProcessingPipelineRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_is_singleton_with_default_steps(): void
    {
        $a = app(FileActionRegistry::class);
        $b = app(FileActionRegistry::class);

        $this->assertSame($a, $b, 'Registry must be a singleton');

        $defaults = $a->all();

        $classes = array_map(fn (FileActionInterface $s) => $s::class, $defaults);

        $this->assertContains(ExtractImageDimensionsStep::class, $classes);
        $this->assertContains(OptimizeImageStep::class, $classes);
        $this->assertContains(GenerateImageVariantsStep::class, $classes);
        $this->assertContains(ExtractTextStep::class, $classes);
        $this->assertContains(ChunkTextStep::class, $classes);
        $this->assertContains(GenerateEmbeddingStep::class, $classes);
    }

    public function test_all_returns_steps_sorted_by_priority_ascending(): void
    {
        $registry = new FileActionRegistry();

        $registry->add(new RecordingStep('c', 80));
        $registry->add(new RecordingStep('a', 10));
        $registry->add(new RecordingStep('b', 40));

        $names = array_map(
            fn (FileActionInterface $s) => $s->name,
            $registry->all()
        );

        $this->assertSame(['a', 'b', 'c'], $names);
    }

    public function test_remove_drops_steps_by_class(): void
    {
        $registry = new FileActionRegistry();
        $registry->add(new RecordingStep('keep', 10));
        $registry->add(new RecordingStep('keep2', 20));

        $registry->remove(RecordingStep::class);

        $this->assertSame([], $registry->all());
    }

    public function test_applicable_for_filters_by_supports(): void
    {
        $registry = new FileActionRegistry();
        $registry->add(new RecordingStep('image-only', 10, fn (File $f) => $f->type === FileType::IMAGE));
        $registry->add(new RecordingStep('always', 20));

        $imageFile = $this->makeFile(FileType::IMAGE);
        $videoFile = $this->makeFile(FileType::VIDEO);

        $forImage = array_map(fn ($s) => $s->name, $registry->applicableFor($imageFile));
        $forVideo = array_map(fn ($s) => $s->name, $registry->applicableFor($videoFile));

        $this->assertSame(['image-only', 'always'], $forImage);
        $this->assertSame(['always'], $forVideo);
    }

    public function test_process_file_action_runs_registered_steps_in_priority_order(): void
    {
        // Reemplaza el registry con uno limpio para aislar el test.
        $registry = new FileActionRegistry();
        $this->app->instance(FileActionRegistry::class, $registry);

        RecordingStep::$calls = [];

        $registry->add(new RecordingStep('third', 80));
        $registry->add(new RecordingStep('first', 10));
        $registry->add(new RecordingStep('second', 40));
        $registry->add(new RecordingStep('skipped', 50, fn () => false));

        $file = $this->makeFile(FileType::IMAGE)->fresh();

        ProcessFileAction::create()->run(['file' => $file]);

        $this->assertSame(['first', 'second', 'third'], RecordingStep::$calls);
        $this->assertSame(ProcessingStatus::COMPLETED, $file->refresh()->processing_status);
    }

    public function test_failing_step_marks_file_as_failed_and_stops_pipeline(): void
    {
        $registry = new FileActionRegistry();
        $this->app->instance(FileActionRegistry::class, $registry);

        RecordingStep::$calls = [];

        $registry->add(new RecordingStep('ok', 10));
        $registry->add(new \EduLazaro\Laracrate\Tests\Support\ExplodingStep('boom', 20));
        $registry->add(new RecordingStep('never', 30));

        $file = $this->makeFile(FileType::IMAGE)->fresh();

        try {
            ProcessFileAction::create()->run(['file' => $file]);
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(['ok'], RecordingStep::$calls);

        $file->refresh();
        $this->assertSame(ProcessingStatus::FAILED, $file->processing_status);
        $this->assertSame('boom', $file->processing_error);
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

