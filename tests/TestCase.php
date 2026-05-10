<?php

namespace EduLazaro\Laracrate\Tests;

use EduLazaro\Laracrate\LaracrateServiceProvider;
use EduLazaro\Laracrate\Tests\Support\HasFilesTestModel;
use EduLazaro\Laractions\LaractionsServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LaractionsServiceProvider::class,
            LaracrateServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // SQLite in-memory.
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
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

        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Schema::create('test_owners', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
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
