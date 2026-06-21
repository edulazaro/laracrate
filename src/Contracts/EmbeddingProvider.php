<?php

namespace EduLazaro\Laracrate\Contracts;

/**
 * Generator of vector embeddings for text.
 *
 * Apps can register their own implementation (OpenAI, Anthropic,
 * self-hosted BGE-M3, etc.) by binding `EmbeddingProvider::class` in their
 * ServiceProvider. The package includes `OpenAiEmbeddingProvider` as the default.
 */
interface EmbeddingProvider
{
    /**
     * Generates embeddings for a batch of strings.
     *
     * @param array<int, string> $texts
     * @return array<int, array<int, float>> One vector per text, same order.
     */
    public function embed(array $texts): array;

    /**
     * Dimensions of the vector this provider/model produces.
     */
    public function dimensions(): int;

    /**
     * Model identifier (e.g. "text-embedding-3-small").
     */
    public function model(): string;

    /**
     * Provider identifier (e.g. "openai", "anthropic", "local").
     */
    public function name(): string;
}
