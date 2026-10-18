<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use Closure;
use EduLazaro\Laracrate\Actions\Files\ProcessFileAction;
use EduLazaro\Laracrate\Contracts\FileActionInterface;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Enums\ProcessingStatus;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\FileActionRegistry;
use EduLazaro\Laracrate\Tests\Support\RecordingStep;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A file deleted for good while its pipeline runs (the user removes an attachment that
 * is still being processed): its delete purged what existed then, so the pipeline stops
 * and clears what its steps wrote afterwards, instead of leaving variants and sidecars
 * with no file to purge them.
 */
class ProcessDeletedFileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        $this->app->instance(FileActionRegistry::class, new FileActionRegistry());
        RecordingStep::$calls = [];
    }

    private function step(int $priority, Closure $handle): FileActionInterface
    {
        return new class($priority, $handle) implements FileActionInterface {
            public function __construct(private int $priority, private Closure $handle) {}

            public function priority(): int
            {
                return $this->priority;
            }

            public function handle(File $file): void
            {
                ($this->handle)($file);
            }
        };
    }

    private function image(): File
    {
        $file = File::create([
            'slug'          => (string) Str::ulid(),
            'disk'          => 'media',
            'path'          => 'photos/' . Str::random(8),
            'name'          => 'photo.jpg',
            'original_name' => 'photo.jpg',
            'mime_type'     => 'image/jpeg',
            'size'          => 4,
            'collection'    => 'gallery',
            'type'          => FileType::IMAGE,
            'access'        => 'public',
        ]);
        Storage::disk('media')->put($file->key, 'jpeg');

        return $file->fresh();
    }

    public function test_a_file_deleted_mid_pipeline_stops_it_and_leaves_nothing_behind(): void
    {
        $file = $this->image();

        // The person deletes the file while a step is half way: the step goes on
        // writing a sidecar and a variant after the delete purged everything.
        app(FileActionRegistry::class)->add($this->step(10, function (File $file) {
            File::query()->whereKey($file->id)->first()->forceDelete();

            Storage::disk('media')->put($file->key . '.json', '{}');
            $variant = File::create([
                'slug' => (string) Str::ulid(), 'disk' => 'media', 'path' => 'photos/variants/thumb.jpg', 'name' => 'thumb.jpg',
                'mime_type' => 'image/jpeg', 'size' => 4, 'collection' => 'gallery', 'type' => FileType::IMAGE,
                'access' => 'public', 'parent_id' => $file->id, 'variant' => 'thumbnail',
            ]);
            Storage::disk('media')->put($variant->key, 'jpeg');
        }));
        app(FileActionRegistry::class)->add(new RecordingStep('after', 20));

        ProcessFileAction::create()->run(['file' => $file]);

        $this->assertSame([], RecordingStep::$calls, 'no step runs for a deleted file');
        $this->assertSame(0, File::withTrashed()->where('parent_id', $file->id)->count());
        Storage::disk('media')->assertMissing($file->key . '.json');
        Storage::disk('media')->assertMissing('photos/variants/thumb.jpg');
    }

    public function test_a_step_failing_because_the_file_is_gone_is_not_reported(): void
    {
        $file = $this->image();

        app(FileActionRegistry::class)->add($this->step(10, function (File $file) {
            File::query()->whereKey($file->id)->first()->forceDelete();

            throw new \RuntimeException('the binary is not there any more');
        }));

        ProcessFileAction::create()->run(['file' => $file]);

        $this->assertNull(File::withTrashed()->find($file->id));
    }

    public function test_a_soft_deleted_file_is_still_processed(): void
    {
        $file = $this->image();

        app(FileActionRegistry::class)->add($this->step(10, fn (File $file) => File::query()->whereKey($file->id)->first()->delete()));
        app(FileActionRegistry::class)->add(new RecordingStep('after', 20));

        ProcessFileAction::create()->run(['file' => $file]);

        $this->assertSame(['after'], RecordingStep::$calls);
        $this->assertSame(ProcessingStatus::COMPLETED, File::withTrashed()->find($file->id)->processing_status);
    }
}
