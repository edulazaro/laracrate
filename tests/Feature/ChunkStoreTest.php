<?php

namespace EduLazaro\Laracrate\Tests\Feature;

use EduLazaro\Laracrate\Contracts\ChunkStore;
use EduLazaro\Laracrate\Contracts\EmbeddingProvider;
use EduLazaro\Laracrate\Enums\FileType;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Tests\TestCase;

class ChunkStoreTest extends TestCase
{
    private function store(): ChunkStore
    {
        return app(ChunkStore::class); // mysql driver by default in tests
    }

    private function makeFile(string $collection = 'gallery'): File
    {
        return File::create([
            'fileable_type' => 'test_owner',
            'fileable_id'   => 1,
            'disk'          => 'media',
            'path'          => uniqid('f') . '.txt',
            'name'          => 'f.txt',
            'original_name' => 'f.txt',
            'extension'     => 'txt',
            'mime_type'     => 'text/plain',
            'size'          => 10,
            'context'       => 'media',
            'collection'    => $collection,
            'type'          => FileType::DOCUMENT,
            'access'        => 'public',
        ]);
    }

    /** Bind a fake provider that always returns the given query vector. */
    private function fakeProvider(array $vector): void
    {
        $this->app->bind(EmbeddingProvider::class, fn () => new class($vector) implements EmbeddingProvider {
            public function __construct(private array $vector) {}
            public function embed(array $texts): array
            {
                return array_map(fn () => $this->vector, $texts);
            }
            public function dimensions(): int { return count($this->vector); }
            public function model(): string { return 'fake'; }
            public function name(): string { return 'fake'; }
        });
    }

    public function test_store_and_get_by_file(): void
    {
        $file = $this->makeFile();

        $count = $this->store()->store($file, [
            ['chunk_index' => 0, 'text' => 'first chunk', 'tokens' => 2, 'metadata' => ['page' => 1]],
            ['chunk_index' => 1, 'text' => 'second chunk', 'tokens' => 2],
        ]);

        $this->assertSame(2, $count);
        $this->assertNotNull($file->fresh()->mysql_indexed_at);

        $chunks = $this->store()->getByFile($file);
        $this->assertCount(2, $chunks);
        $this->assertSame('first chunk', $chunks[0]['text']);
        $this->assertSame(1, $chunks[0]['metadata']['page']);
    }

    public function test_store_is_idempotent_replacing_previous_chunks(): void
    {
        $file = $this->makeFile();
        $this->store()->store($file, [['chunk_index' => 0, 'text' => 'old']]);
        $this->store()->store($file, [['chunk_index' => 0, 'text' => 'new']]);

        $chunks = $this->store()->getByFile($file);
        $this->assertCount(1, $chunks);
        $this->assertSame('new', $chunks[0]['text']);
    }

    public function test_delete_by_file(): void
    {
        $file = $this->makeFile();
        $this->store()->store($file, [['chunk_index' => 0, 'text' => 'x']]);

        $this->store()->deleteByFile($file);

        $this->assertCount(0, $this->store()->getByFile($file));
    }

    public function test_keyword_search_without_embeddings(): void
    {
        $file = $this->makeFile();
        $this->store()->store($file, [
            ['chunk_index' => 0, 'text' => 'the contract mentions a deadline'],
            ['chunk_index' => 1, 'text' => 'unrelated paragraph about invoices'],
        ]);

        $hits = $this->store()->search('deadline', [], ['semantic_ratio' => 0]);

        $this->assertCount(1, $hits);
        $this->assertSame('keyword', $hits[0]['matched']);
        $this->assertStringContainsString('deadline', $hits[0]['text']);

        $this->assertCount(0, $this->store()->search('nonexistentword', [], ['semantic_ratio' => 0]));
    }

    public function test_search_filters_by_collection_scope(): void
    {
        $docs   = $this->makeFile('documents');
        $other  = $this->makeFile('gallery');
        $this->store()->store($docs, [['chunk_index' => 0, 'text' => 'shared term here']]);
        $this->store()->store($other, [['chunk_index' => 0, 'text' => 'shared term here']]);

        $hits = $this->store()->search('shared term', ['collection' => 'documents'], ['semantic_ratio' => 0]);

        $this->assertCount(1, $hits);
        $this->assertSame($docs->id, $hits[0]['file_id']);
    }

    public function test_semantic_search_ranks_by_cosine_similarity(): void
    {
        $this->fakeProvider([1.0, 0.0]); // query vector

        $file = $this->makeFile();
        $this->store()->store($file, [
            ['chunk_index' => 0, 'text' => 'aaa', 'embedding' => [1.0, 0.0]], // cosine 1.0
            ['chunk_index' => 1, 'text' => 'bbb', 'embedding' => [0.0, 1.0]], // cosine 0.0
        ]);

        // Query text not present in any chunk, so matches come purely from vectors.
        $hits = $this->store()->search('zzz', [], ['semantic_ratio' => 1.0]);

        $this->assertSame(0, $hits[0]['chunk_index']);
        $this->assertSame('semantic', $hits[0]['matched']);
        $this->assertGreaterThan($hits[1]['score'], $hits[0]['score']);
    }

    public function test_hybrid_search_marks_keyword_plus_semantic_hits(): void
    {
        $this->fakeProvider([1.0, 0.0]);

        $file = $this->makeFile();
        $this->store()->store($file, [
            ['chunk_index' => 0, 'text' => 'the needle is here', 'embedding' => [1.0, 0.0]],
        ]);

        $hits = $this->store()->search('needle', [], ['semantic_ratio' => 0.5]);

        $this->assertCount(1, $hits);
        $this->assertSame('hybrid', $hits[0]['matched']);
    }
}
