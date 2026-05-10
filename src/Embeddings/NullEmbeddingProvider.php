<?php

namespace EduLazaro\Laracrate\Embeddings;

use EduLazaro\Laracrate\Contracts\EmbeddingProvider;
use RuntimeException;

/**
 * Provider noop. Si la app intenta generar embeddings sin haber registrado
 * un provider real, se usa éste y lanza excepción. Sirve para detectar
 * misconfig en lugar de fallar silenciosamente.
 */
class NullEmbeddingProvider implements EmbeddingProvider
{
    public function embed(array $texts): array
    {
        throw new RuntimeException(
            'No hay EmbeddingProvider configurado. Liga una implementación real ' .
            'en tu ServiceProvider, ej: app()->bind(EmbeddingProvider::class, OpenAiEmbeddingProvider::class).'
        );
    }

    public function dimensions(): int
    {
        return 0;
    }

    public function model(): string
    {
        return 'null';
    }

    public function name(): string
    {
        return 'null';
    }
}
