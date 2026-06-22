<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Actions\Files\ChunkTextAction;
use EduLazaro\Laracrate\Actions\Files\ExtractTextAction;
use EduLazaro\Laracrate\Actions\Files\GenerateEmbeddingAction;
use EduLazaro\Laracrate\Actions\Files\PersistChunksAction;
use EduLazaro\Laracrate\Contracts\ChunkStore;
use EduLazaro\Laracrate\Contracts\EmbeddingProvider;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Extractors\PlainTextExtractor;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Models\FileChunk;
use EduLazaro\Laracrate\Support\TextExtractorRegistry;
use EduLazaro\Laracrate\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

class EmbeddingPipelineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('laracrate.collections.docs', [
            'disk'    => 'media',
            'access'  => 'public',
            'extract' => true,
            'embed'   => true,
        ]);
        config()->set('laracrate.embeddings.enabled', true);
        config()->set('laracrate.embeddings.extractors', [PlainTextExtractor::class]);
        config()->set('laracrate.embeddings.chunk_size', 1000);

        // Re-resolve the registry so it picks up the extractors set above.
        $this->app->forgetInstance(TextExtractorRegistry::class);

        // Deterministic, offline embedding provider (no OpenAI call).
        $this->app->bind(EmbeddingProvider::class, fn () => new class implements EmbeddingProvider {
            public function embed(array $texts): array
            {
                return array_map(fn () => [0.1, 0.2, 0.3], $texts);
            }
            public function dimensions(): int { return 3; }
            public function model(): string { return 'fake'; }
            public function name(): string { return 'fake'; }
        });
    }

    private function makeTextFile(string $path, string $text): File
    {
        $file = File::create([
            'fileable_type' => 'test_owner',
            'fileable_id'   => 1,
            'disk'          => 'media',
            'path'          => $path,
            'name'          => basename($path),
            'original_name' => basename($path),
            'extension'     => 'txt',
            'mime_type'     => 'text/plain',
            'size'          => strlen($text),
            'context'       => 'media',
            'collection'    => 'docs',
            'type'          => FileType::DOCUMENT,
            'access'        => 'public',
        ]);
        Storage::disk('media')->put($path, $text);

        return $file;
    }

    public function test_extract_chunk_embed_persist_end_to_end(): void
    {
        Storage::fake('media');

        $text = str_repeat('Laracrate extracts the text, chunks it, embeds it and indexes it for retrieval. ', 5);
        $file = $this->makeTextFile('docs/doc.txt', $text);

        // 1. Extract text -> {key}.json sidecar.
        $this->assertTrue(ExtractTextAction::create()->run(['file' => $file]));
        $this->assertTrue(Storage::disk('media')->exists('docs/doc.txt.json'));

        // 2. Chunk -> {key}.chunks.jsonl.
        $chunks = ChunkTextAction::create()->run(['file' => $file]);
        $this->assertNotEmpty($chunks);
        $this->assertTrue(Storage::disk('media')->exists('docs/doc.txt.chunks.jsonl'));

        // 3. Embed -> rewrites the JSONL with vectors.
        $embedded = GenerateEmbeddingAction::create()->run(['file' => $file]);
        $this->assertGreaterThan(0, $embedded);

        // 4. Persist -> active ChunkStore.
        $persisted = PersistChunksAction::create()->run(['file' => $file]);
        $this->assertGreaterThan(0, $persisted);

        $this->assertNotEmpty(app(ChunkStore::class)->getByFile($file));

        $chunk = FileChunk::where('file_id', $file->id)->first();
        $this->assertIsArray($chunk->embedding);
        $this->assertCount(3, $chunk->embedding);
        $this->assertEqualsWithDelta(0.1, $chunk->embedding[0], 1e-6);
    }

    public function test_extracted_text_is_readable_from_the_file(): void
    {
        Storage::fake('media');

        $text = str_repeat('readable plain text content for extraction. ', 4);
        $file = $this->makeTextFile('docs/readable.txt', $text);

        ExtractTextAction::create()->run(['file' => $file]);

        $this->assertStringContainsString('readable plain text', (string) $file->extractedText());
    }
}
