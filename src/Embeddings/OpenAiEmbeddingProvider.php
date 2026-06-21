<?php

namespace EduLazaro\Laracrate\Embeddings;

use EduLazaro\Laracrate\Contracts\EmbeddingProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Embedding provider backed by the OpenAI embeddings API.
 */
class OpenAiEmbeddingProvider implements EmbeddingProvider
{
    /**
     * Create a new OpenAI embedding provider.
     */
    public function __construct(
        protected ?string $apiKey = null,
        protected string $model = 'text-embedding-3-small',
        protected int $dimensions = 1536,
        protected string $endpoint = 'https://api.openai.com/v1/embeddings',
        protected int $timeout = 60,
    ) {
        $this->apiKey ??= config('laracrate.embeddings.api_key') ?: env('OPENAI_API_KEY');
    }

    /**
     * Generate embeddings for the given texts.
     */
    public function embed(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        if (!$this->apiKey) {
            throw new RuntimeException('OpenAI API key not configured (OPENAI_API_KEY or laracrate.embeddings.api_key).');
        }

        $response = Http::withToken($this->apiKey)
            ->timeout($this->timeout)
            ->retry(2, 500)
            ->acceptJson()
            ->post($this->endpoint, [
                'model' => $this->model,
                'input' => array_values($texts),
            ]);

        if (!$response->successful()) {
            throw new RuntimeException(
                'OpenAI embeddings API error: ' . $response->status() . ' ' . $response->body()
            );
        }

        $data = $response->json('data') ?? [];

        return array_map(fn ($row) => $row['embedding'] ?? [], $data);
    }

    /**
     * Return the embedding dimensions.
     */
    public function dimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * Return the model identifier.
     */
    public function model(): string
    {
        return $this->model;
    }

    /**
     * Return the provider name.
     */
    public function name(): string
    {
        return 'openai';
    }
}
