<?php

namespace EduLazaro\Laracrate;

use EduLazaro\Laracrate\Contracts\EmbeddingProvider;
use EduLazaro\Laracrate\Embeddings\NullEmbeddingProvider;
use EduLazaro\Laracrate\Embeddings\OpenAiEmbeddingProvider;
use EduLazaro\Laracrate\Console\Commands\AbortStaleMultipartCommand;
use EduLazaro\Laracrate\Console\Commands\PurgeExpiredFilesCommand;
use EduLazaro\Laracrate\Http\Livewire\LaracrateDropzone;
use EduLazaro\Laracrate\Http\Livewire\LaracrateDropzoneDeferred;
use EduLazaro\Laracrate\Http\Livewire\LaracrateDropzoneSingle;
use EduLazaro\Laracrate\Http\Livewire\LaracrateDropzoneSingleDeferred;
use EduLazaro\Laracrate\Http\Livewire\LaracrateUploader;
use EduLazaro\Laracrate\Http\Livewire\LaracrateUploaderDeferred;
use Livewire\Livewire;
use EduLazaro\Laracrate\Extractors\PdfTextExtractor;
use EduLazaro\Laracrate\Extractors\PlainTextExtractor;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Observers\FileObserver;
use EduLazaro\Laracrate\Pipeline\Steps\Document\ExtractPdfPreviewStep;
use EduLazaro\Laracrate\Pipeline\Steps\Image\ExtractImageDimensionsStep;
use EduLazaro\Laracrate\Pipeline\Steps\Image\GenerateImageVariantsStep;
use EduLazaro\Laracrate\Pipeline\Steps\Image\OptimizeImageStep;
use EduLazaro\Laracrate\Pipeline\Steps\Text\ChunkTextStep;
use EduLazaro\Laracrate\Pipeline\Steps\Text\ExtractTextStep;
use EduLazaro\Laracrate\Pipeline\Steps\Text\GenerateEmbeddingStep;
use EduLazaro\Laracrate\Pipeline\Steps\Video\ExtractVideoDimensionsStep;
use EduLazaro\Laracrate\Pipeline\Steps\Video\ExtractVideoPreviewStep;
use EduLazaro\Laracrate\Pipeline\Steps\Video\TranscodeVideoStep;
use EduLazaro\Laracrate\Policies\FilePolicy;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Services\UsageReporter;
use EduLazaro\Laracrate\Support\PolicyRegistry;
use EduLazaro\Laracrate\Support\ProcessingPipelineRegistry;
use EduLazaro\Laracrate\Support\TextExtractorRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class LaracrateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/laracrate.php', 'laracrate');

        $this->app->singleton(StorageManager::class);
        $this->app->alias(StorageManager::class, 'laracrate.manager');

        $this->app->singleton(PolicyRegistry::class);
        $this->app->alias(PolicyRegistry::class, 'laracrate.policies');

        $this->app->singleton(UsageReporter::class);
        $this->app->alias(UsageReporter::class, 'laracrate.usage');

        $this->registerEmbeddingProvider();
        $this->registerTextExtractorRegistry();
        $this->registerProcessingPipelineRegistry();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'laracrate');
        $this->loadTranslationsFrom(__DIR__ . '/../lang', 'laracrate');

        File::observe(FileObserver::class);

        if (config('laracrate.policies.register_gate', true)) {
            Gate::policy(File::class, FilePolicy::class);
        }

        $this->registerLivewireComponents();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/laracrate.php' => config_path('laracrate.php'),
            ], 'laracrate-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'laracrate-migrations');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/laracrate'),
            ], 'laracrate-views');

            $this->publishes([
                __DIR__ . '/../lang' => $this->app->langPath('vendor/laracrate'),
            ], 'laracrate-translations');

            $this->commands([
                AbortStaleMultipartCommand::class,
                PurgeExpiredFilesCommand::class,
            ]);
        }
    }

    protected function registerLivewireComponents(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        Livewire::component('laracrate-uploader', LaracrateUploader::class);
        Livewire::component('laracrate-uploader-deferred', LaracrateUploaderDeferred::class);
        Livewire::component('laracrate-dropzone', LaracrateDropzone::class);
        Livewire::component('laracrate-dropzone-deferred', LaracrateDropzoneDeferred::class);
        Livewire::component('laracrate-dropzone-single', LaracrateDropzoneSingle::class);
        Livewire::component('laracrate-dropzone-single-deferred', LaracrateDropzoneSingleDeferred::class);
    }

    protected function registerEmbeddingProvider(): void
    {
        $this->app->singleton(EmbeddingProvider::class, function () {
            $providerKey = config('laracrate.embeddings.provider', 'openai');

            return match ($providerKey) {
                'openai' => new OpenAiEmbeddingProvider(
                    apiKey:     config('laracrate.embeddings.api_key'),
                    model:      config('laracrate.embeddings.model', 'text-embedding-3-small'),
                    dimensions: (int) config('laracrate.embeddings.dimensions', 1536),
                ),
                'null', null => new NullEmbeddingProvider(),
                default => new NullEmbeddingProvider(),
            };
        });
    }

    protected function registerTextExtractorRegistry(): void
    {
        $this->app->singleton(TextExtractorRegistry::class, function ($app) {
            $registry = new TextExtractorRegistry();

            // Si la app configura `embeddings.extractors` como array de FQCN,
            // se usa esa lista (control total sobre orden + qué incluir).
            // Si no, se cargan los defaults built-in del package.
            $configured = (array) config('laracrate.embeddings.extractors', []);

            if (! empty($configured)) {
                foreach ($configured as $class) {
                    $instance = $app->make($class);
                    if (! $instance instanceof \EduLazaro\Laracrate\Contracts\TextExtractor) {
                        throw new \RuntimeException(
                            "Laracrate: configured extractor [$class] must implement TextExtractor"
                        );
                    }
                    $registry->add($instance);
                }
            } else {
                // Orden importa: primer extractor que diga supports() gana.
                $registry->add(new PdfTextExtractor());
                $registry->add(new PlainTextExtractor());
            }

            return $registry;
        });
    }

    /**
     * Registra los pasos default del pipeline de procesamiento. Las apps
     * pueden añadir / quitar pasos resolviendo el registry desde su propio
     * ServiceProvider.
     */
    protected function registerProcessingPipelineRegistry(): void
    {
        $this->app->singleton(ProcessingPipelineRegistry::class, function () {
            $registry = new ProcessingPipelineRegistry();

            // Imagen
            $registry->add(new ExtractImageDimensionsStep());
            $registry->add(new OptimizeImageStep());
            $registry->add(new GenerateImageVariantsStep());

            // Vídeo
            $registry->add(new ExtractVideoDimensionsStep());
            $registry->add(new TranscodeVideoStep());
            $registry->add(new ExtractVideoPreviewStep());

            // Documento (PDF preview)
            $registry->add(new ExtractPdfPreviewStep());

            // Texto + IA
            $registry->add(new ExtractTextStep());
            $registry->add(new ChunkTextStep());
            $registry->add(new GenerateEmbeddingStep());

            return $registry;
        });
    }
}
