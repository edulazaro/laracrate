<?php

namespace EduLazaro\Laracrate\Support;

use JsonSerializable;

/**
 * Structured result of a TextExtractor.
 *
 * Every extraction returns this DTO with:
 *   - `fullText`: full text of the document (concatenation of pages).
 *   - `pages`: array of pages/segments of the document. Each entry carries
 *     `page_number` (1-based) and `text`. For extractors that have no
 *     concept of pages (.txt, single image, audio without timestamps), a
 *     single entry with page_number=1.
 *   - `metadata`: optional additional info (lang, extractor, total_pages, ...).
 *
 * Serialized to `{path}.json` in the file's storage.
 */
class ExtractedContent implements JsonSerializable
{
    /**
     * @param string $fullText                                  full concatenated text
     * @param array<int,array{page_number:int,text:string}> $pages
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public string $fullText,
        public array $pages = [],
        public array $metadata = [],
    ) {}

    /**
     * Convenience constructor for extractors with no concept of pages.
     */
    public static function singlePage(string $text, array $metadata = []): self
    {
        return new self(
            fullText: $text,
            pages: [['page_number' => 1, 'text' => $text]],
            metadata: $metadata,
        );
    }

    /**
     * Constructor from an array of pages. Computes `fullText` by concatenating.
     *
     * @param array<int,array{page_number:int,text:string}> $pages
     */
    public static function fromPages(array $pages, array $metadata = []): self
    {
        $fullText = implode(
            "\n\n",
            array_filter(array_map(fn ($p) => $p['text'] ?? '', $pages))
        );

        return new self(
            fullText: $fullText,
            pages: array_values($pages),
            metadata: $metadata,
        );
    }

    /** Whether the extracted full text is blank. */
    public function isEmpty(): bool
    {
        return trim($this->fullText) === '';
    }

    /** Number of pages/segments in this result. */
    public function totalPages(): int
    {
        return count($this->pages);
    }

    /**
     * Serialize to the storage JSON shape.
     *
     * @return array{full_text:string,pages:array,metadata:array}
     */
    public function jsonSerialize(): array
    {
        return [
            'full_text' => $this->fullText,
            'pages'     => $this->pages,
            'metadata'  => $this->metadata,
        ];
    }

    /** Rebuild the DTO from its serialized array form. */
    public static function fromArray(array $data): self
    {
        return new self(
            fullText: $data['full_text'] ?? '',
            pages:    $data['pages']     ?? [],
            metadata: $data['metadata']  ?? [],
        );
    }
}
