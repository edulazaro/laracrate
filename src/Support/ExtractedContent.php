<?php

namespace EduLazaro\Laracrate\Support;

use JsonSerializable;

/**
 * Resultado estructurado de un TextExtractor.
 *
 * Toda extracción devuelve este DTO con:
 *   - `fullText`: texto completo del documento (concatenación de páginas).
 *   - `pages`: array de páginas/segmentos del documento. Cada entrada lleva
 *     `page_number` (1-based) y `text`. Para extractores que no tienen
 *     concepto de página (.txt, imagen única, audio sin timestamps), una
 *     única entrada con page_number=1.
 *   - `metadata`: info adicional opcional (lang, extractor, total_pages, ...).
 *
 * Se serializa a `{path}.json` en el storage del file.
 */
class ExtractedContent implements JsonSerializable
{
    /**
     * @param string $fullText                                  texto completo concatenado
     * @param array<int,array{page_number:int,text:string}> $pages
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public string $fullText,
        public array $pages = [],
        public array $metadata = [],
    ) {}

    /**
     * Constructor de conveniencia para extractores sin concepto de página.
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
     * Constructor desde array de páginas. Calcula `fullText` concatenando.
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

    public function isEmpty(): bool
    {
        return trim($this->fullText) === '';
    }

    public function totalPages(): int
    {
        return count($this->pages);
    }

    /** @return array{full_text:string,pages:array,metadata:array} */
    public function jsonSerialize(): array
    {
        return [
            'full_text' => $this->fullText,
            'pages'     => $this->pages,
            'metadata'  => $this->metadata,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            fullText: $data['full_text'] ?? '',
            pages:    $data['pages']     ?? [],
            metadata: $data['metadata']  ?? [],
        );
    }
}
