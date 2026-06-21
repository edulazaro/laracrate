<?php

namespace EduLazaro\Laracrate;

use EduLazaro\Laracrate\Chunks\MysqlChunkStore;
use EduLazaro\Laracrate\Contracts\ChunkStore;
use EduLazaro\Laracrate\Contracts\EmbeddingProvider;
use EduLazaro\Laracrate\Embeddings\NullEmbeddingProvider;
use EduLazaro\Laracrate\Embeddings\OpenAiEmbeddingProvider;
use EduLazaro\Laracrate\Console\Commands\AbortStaleMultipartCommand;
use EduLazaro\Laracrate\Console\Commands\PurgeExpiredFilesCommand;
use EduLazaro\Laracrate\Console\Commands\RecomputeUsageCommand;
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
use EduLazaro\Laracrate\Models\Folder;
use EduLazaro\Laracrate\Observers\FileObserver;
use EduLazaro\Laracrate\Observers\FolderObserver;
use EduLazaro\Laracrate\Pipeline\Steps\Document\ExtractPdfPreviewStep;
use EduLazaro\Laracrate\Pipeline\Steps\Image\ExtractImageDimensionsStep;
use EduLazaro\Laracrate\Pipeline\Steps\Image\GenerateImageVariantsStep;
use EduLazaro\Laracrate\Pipeline\Steps\Image\OptimizeImageStep;
use EduLazaro\Laracrate\Pipeline\Steps\Text\ChunkTextStep;
use EduLazaro\Laracrate\Pipeline\Steps\Text\ExtractTextStep;
use EduLazaro\Laracrate\Pipeline\Steps\Text\GenerateEmbeddingStep;
use EduLazaro\Laracrate\Pipeline\Steps\Text\PersistChunksStep;
use EduLazaro\Laracrate\Pipeline\Steps\Video\ExtractVideoDimensionsStep;
use EduLazaro\Laracrate\Pipeline\Steps\Video\ExtractVideoPreviewStep;
use EduLazaro\Laracrate\Pipeline\Steps\Video\TranscodeVideoStep;
use EduLazaro\Laracrate\Policies\FilePolicy;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laracrate\Services\UsageReporter;
use EduLazaro\Laracrate\Support\PolicyRegistry;
use EduLazaro\Laracrate\Support\FileActionRegistry;
use EduLazaro\Laracrate\Support\TextExtractorRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider that wires up Laracrate bindings, pipeline steps, routes and views.
 */
class LaracrateServiceProvider extends ServiceProvider
{
    /** Registers package singletons, aliases and providers in the container. */
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
        $this->registerFileActionRegistry();
        $this->registerChunkStore();
    }

    /**
     * Binding of the chunks backend (storage + search). Default `mysql`
     * (no external dependencies). Apps can switch to `meilisearch` via
     * LARACRATE_CHUNKS_DRIVER and must register Meilisearch\Client in the
     * container.
     *
     * Custom drivers (Qdrant, pgvector...): the app can bind ChunkStore
     * directly to its implementation; this binding is ignored.
     */
    protected function registerChunkStore(): void
    {
        $this->app->singleton(ChunkStore::class, function ($app) {
            $driver = config('laracrate.chunks.driver', 'mysql');

            return match ($driver) {
                'meilisearch' => $this->makeMeilisearchChunkStore($app),
                default => new MysqlChunkStore(),
            };
        });
    }

    /**
     * Builds the Meilisearch driver, resolving Meilisearch\Client from the
     * container. The app is responsible for binding the client; if it is not
     * there, falls back to MysqlChunkStore with a warning.
     */
    protected function makeMeilisearchChunkStore($app): ChunkStore
    {
        if (! class_exists(\Meilisearch\Client::class) || ! $app->bound(\Meilisearch\Client::class)) {
            \Illuminate\Support\Facades\Log::warning(
                'Laracrate: chunks.driver=meilisearch but Meilisearch\\Client is not bound. ' .
                'Falling back to MysqlChunkStore.'
            );
            return new MysqlChunkStore();
        }

        $client   = $app->make(\Meilisearch\Client::class);
        $index    = config('laracrate.meilisearch.index', \EduLazaro\Laracrate\Search\MeilisearchSync::DEFAULT_INDEX);
        $embedder = config('laracrate.meilisearch.embedder', \EduLazaro\Laracrate\Search\MeilisearchSync::DEFAULT_EMBEDDER);

        $sync = new \EduLazaro\Laracrate\Search\MeilisearchSync(
            client:   $client,
            index:    $index,
            embedder: $embedder,
        );

        return new \EduLazaro\Laracrate\Chunks\MeilisearchChunkStore(
            client:   $client,
            sync:     $sync,
            index:    $index,
            embedder: $embedder,
        );
    }

    /** Boots the package: migrations, routes, views, observers, commands. */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'laracrate');
        $this->loadTranslationsFrom(__DIR__ . '/../lang', 'laracrate');

        File::observe(FileObserver::class);
        Folder::observe(FolderObserver::class);

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
                RecomputeUsageCommand::class,
            ]);
        }
    }

    /** Registers the package's Livewire uploader and dropzone components. */
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

    /** Binds the configured embedding provider (OpenAI or null) in the container. */
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

    /** Binds the text extractor registry from config or built-in defaults. */
    protected function registerTextExtractorRegistry(): void
    {
        $this->app->singleton(TextExtractorRegistry::class, function ($app) {
            $registry = new TextExtractorRegistry();

            // If the app configures `embeddings.extractors` as an array of FQCN,
            // that list is used (full control over order + what to include).
            // Otherwise, the package's built-in defaults are loaded.
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
                // Order matters: the first extractor whose supports() returns true wins.
                $registry->add(new PdfTextExtractor());
                $registry->add(new PlainTextExtractor());
            }

            return $registry;
        });
    }

    /**
     * Registers the default steps of the processing pipeline. Apps can add /
     * remove steps by resolving the registry from their own ServiceProvider.
     */
    protected function registerFileActionRegistry(): void
    {
        $this->app->singleton(FileActionRegistry::class, function () {
            $registry = new FileActionRegistry();

            // Image
            $registry->add(new ExtractImageDimensionsStep());
            $registry->add(new OptimizeImageStep());
            $registry->add(new GenerateImageVariantsStep());

            // Video
            $registry->add(new ExtractVideoDimensionsStep());
            $registry->add(new TranscodeVideoStep());
            $registry->add(new ExtractVideoPreviewStep());

            // Document (PDF preview)
            $registry->add(new ExtractPdfPreviewStep());

            // Text + AI
            $registry->add(new ExtractTextStep());
            $registry->add(new ChunkTextStep());
            $registry->add(new GenerateEmbeddingStep());
            $registry->add(new PersistChunksStep());

            return $registry;
        });
    }
}
