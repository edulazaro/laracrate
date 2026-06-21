<?php

namespace EduLazaro\Laracrate\Support;

use EduLazaro\Laracrate\Contracts\TextExtractor;
use EduLazaro\Laracrate\Models\File;

/**
 * Registry of chained text extractors.
 *
 * Registration order defines priority: the first is tried before the second.
 * If an extractor returns text below the minimum threshold
 * (`min_text_per_file`), the next one is tried as a fallback.
 *
 * Typical pattern: fast/free extractor first (smalot for native PDFs),
 * slow/paid extractor as a fallback (OCR over scanned PDFs).
 *
 * Apps can add their own extractors via `add()` or by configuring
 * `laracrate.embeddings.extractors` as an array of FQCNs.
 */
class TextExtractorRegistry
{
    /** @var TextExtractor[] */
    protected array $extractors = [];

    /** Register a text extractor in the chain. */
    public function add(TextExtractor $extractor): static
    {
        $this->extractors[] = $extractor;
        return $this;
    }

    /**
     * Return the first extractor that supports the file (legacy compat: apps
     * calling `for($file)` directly keep working, without fallback).
     */
    public function for(File $file): ?TextExtractor
    {
        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($file)) {
                return $extractor;
            }
        }

        return null;
    }

    /**
     * Return ALL extractors that support the file, in priority order.
     * Useful for implementing a fallback chain (e.g. smalot then OCR).
     *
     * @return TextExtractor[]
     */
    public function chainFor(File $file): array
    {
        return array_values(array_filter(
            $this->extractors,
            fn (TextExtractor $e) => $e->supports($file)
        ));
    }

    /**
     * Return all registered extractors in registration order.
     *
     * @return TextExtractor[]
     */
    public function all(): array
    {
        return $this->extractors;
    }
}
