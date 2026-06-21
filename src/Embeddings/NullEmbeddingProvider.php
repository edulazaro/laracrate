<?php

namespace EduLazaro\Laracrate\Embeddings;

use EduLazaro\Laracrate\Contracts\EmbeddingProvider;
use RuntimeException;

/**
 * No-op provider. If the app tries to generate embeddings without having
 * registered a real provider, this one is used and throws. It serves to detect
 * misconfiguration instead of failing silently.
 */
class NullEmbeddingProvider implements EmbeddingProvider
{
    /**
     * Always throws: no real embedding provider is configured.
     */
    public function embed(array $texts): array
    {
        throw new RuntimeException(
            'No EmbeddingProvider configured. Bind a real implementation ' .
            'in your ServiceProvider, e.g.: app()->bind(EmbeddingProvider::class, OpenAiEmbeddingProvider::class).'
        );
    }

    /**
     * Return the embedding dimensions (zero for the null provider).
     */
    public function dimensions(): int
    {
        return 0;
    }

    /**
     * Return the model identifier.
     */
    public function model(): string
    {
        return 'null';
    }

    /**
     * Return the provider name.
     */
    public function name(): string
    {
        return 'null';
    }
}
