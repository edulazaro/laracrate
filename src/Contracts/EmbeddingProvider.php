<?php

namespace EduLazaro\Laracrate\Contracts;

/**
 * Generador de embeddings vectoriales para texto.
 *
 * Las apps pueden registrar su propia implementación (OpenAI, Anthropic,
 * BGE-M3 self-hosted, etc.) ligando `EmbeddingProvider::class` en su
 * ServiceProvider. El package incluye `OpenAiEmbeddingProvider` como default.
 */
interface EmbeddingProvider
{
    /**
     * Genera embeddings para un batch de strings.
     *
     * @param array<int, string> $texts
     * @return array<int, array<int, float>> Vector por cada texto, mismo orden.
     */
    public function embed(array $texts): array;

    /**
     * Dimensiones del vector que produce este provider/modelo.
     */
    public function dimensions(): int;

    /**
     * Identificador del modelo (ej. "text-embedding-3-small").
     */
    public function model(): string;

    /**
     * Identificador del provider (ej. "openai", "anthropic", "local").
     */
    public function name(): string;
}
