<?php

namespace EduLazaro\Laracrate\Tests;

use EduLazaro\Laracrate\LaracrateServiceProvider;
use EduLazaro\Laracrate\Tests\Support\HasFilesTestModel;
use EduLazaro\Laractions\LaractionsServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Guards the heavy migration chain so it runs once per process, not per test. */
    protected static bool $schemaMigrated = false;

    protected function getPackageProviders($app): array
    {
        return [
            LaractionsServiceProvider::class,
            LaracrateServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // vendor/ is owned by another UID in this environment, so the testbench
        // storage dir is not writable. Point the app at a writable storage path
        // so Storage::fake() and the framework caches can create directories.
        $storage = sys_get_temp_dir() . '/laracrate-test-storage';
        foreach (['app', 'framework/cache', 'framework/views', 'framework/sessions', 'framework/testing/disks', 'logs'] as $sub) {
            if (! is_dir($dir = $storage . '/' . $sub)) {
                @mkdir($dir, 0777, true);
            }
        }
        $app->useStoragePath($storage);

        // MySQL test database (the package migrations use MySQL-specific DDL:
        // FULLTEXT indexes, information_schema lookups, ALTER ... MODIFY). Point
        // it at a throwaway server with env, defaulting to the local Docker one.
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'    => 'mysql',
            'host'      => env('LARACRATE_TEST_DB_HOST', '127.0.0.1'),
            'port'      => env('LARACRATE_TEST_DB_PORT', '3307'),
            'database'  => env('LARACRATE_TEST_DB_DATABASE', 'laracrate_test'),
            'username'  => env('LARACRATE_TEST_DB_USERNAME', 'root'),
            'password'  => env('LARACRATE_TEST_DB_PASSWORD', 'root'),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            // Non-strict mode: the tests intentionally create minimal File rows
            // (omitting NOT NULL metadata like original_name/extension that
            // CreateFileAction always sets in production). This matches the
            // environment the suite was written against.
            'strict'    => false,
        ]);

        // Disks fake (Storage::fake los reemplaza igualmente, pero dejamos default).
        $app['config']->set('filesystems.disks.media', [
            'driver' => 'local',
            'root'   => storage_path('app/test/media'),
            'url'    => 'http://localhost/storage/media',
            'visibility' => 'public',
        ]);

        $app['config']->set('filesystems.disks.documents', [
            'driver' => 'local',
            'root'   => storage_path('app/test/documents'),
            'url'    => 'http://localhost/storage/documents',
        ]);

        // Colecciones de prueba que cubren los casos del paquete.
        $app['config']->set('laracrate.collections', [
            'avatar' => [
                'disk'   => 'media',
                'access' => 'public',
                'single' => true,
                'types'  => [
                    'image' => [
                        'accepted_mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
                    ],
                ],
            ],
            'gallery' => [
                'disk'   => 'media',
                'access' => 'public',
                'types'  => [
                    'image' => [
                        'accepted_mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
                        'max_file_size'       => 5120,
                        'variants' => [
                            'thumbnail' => ['width' => 100, 'height' => 100, 'fit' => true],
                            'medium'    => ['width' => 400, 'height' => 400],
                        ],
                    ],
                ],
            ],
            'documents' => [
                'disk'   => 'documents',
                'access' => 'signed',
                'types'  => [
                    'document' => [
                        'accepted_mime_types' => ['application/pdf'],
                    ],
                ],
            ],
            'identity' => [
                'disk'      => 'documents',
                'access'    => 'stream',
                'sensitive' => true,
                'encrypt'   => true,
                'types'     => [
                    'image' => [
                        'accepted_mime_types' => ['image/jpeg', 'image/png'],
                    ],
                ],
            ],
        ]);

        $app['config']->set('laracrate.placeholders', [
            'default'  => '/img/placeholder.svg',
            'image'    => '/img/image.svg',
            'video'    => '/img/video.svg',
            'audio'    => '/img/audio.svg',
            'document' => '/img/document.svg',
        ]);

        $app['config']->set('laracrate.uploads', [
            'route_prefix'  => 'laracrate/uploads',
            'middleware'    => ['web'],
            'allowed_disks' => ['media', 'documents'],
        ]);

        $app['config']->set('laracrate.urls', [
            'signed_ttl'             => 5,
            'signed_cache_ttl'       => 4,
            'sensitive_redirect_ttl' => 10,
            'route_signed_ttl'       => 15,
            'bind_to_user'           => true,
        ]);

        $app['config']->set('laracrate.stream', [
            'route_prefix'        => 'files',
            'route_name_prefix'   => 'laracrate.files',
            'middleware'          => ['web'],
            'increment_downloads' => true,
            'log_access'          => false,
        ]);

        // Discard logs during tests. The testbench storage/logs directory is
        // not guaranteed writable, and the processing pipeline logs warnings
        // when it runs (synchronously) over the fake binaries tests create.
        $app['config']->set('logging.default', 'null');
        $app['config']->set('logging.channels.null', [
            'driver'  => 'monolog',
            'handler' => \Monolog\Handler\NullHandler::class,
        ]);

        // The pipeline is exercised directly via ProcessFileAction in the
        // pipeline tests, so the observer's queued job must not run inline: on
        // the default sync queue it shells out to ffmpeg/imagick/pdftoppm per
        // created file and makes the suite crawl. The null driver discards it.
        $app['config']->set('queue.default', 'null');

        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    protected function defineDatabaseMigrations(): void
    {
        // Register the package + test migrations only on the first test of the
        // process. RefreshDatabase runs migrate:fresh once (static-cached) and
        // wraps each later test in a transaction, so re-registering here every
        // test would re-run the whole (slow) MySQL chain per test.
        if (static::$schemaMigrated) {
            return;
        }
        static::$schemaMigrated = true;

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Morph map para que fileable_type sea 'test_owner', no FQCN.
        Relation::enforceMorphMap([
            'test_owner' => HasFilesTestModel::class,
            'user'       => \Orchestra\Testbench\Foundation\UserFactory::class,
        ]);
    }
}
