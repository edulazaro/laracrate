<?php

namespace EduLazaro\Laracrate\Support;

use EduLazaro\Laracrate\Contracts\TextExtractor;
use EduLazaro\Laracrate\Models\File;

/**
 * Registry de extractors de texto. La app puede registrar los suyos.
 * Devuelve el primero que diga `supports($file) === true`.
 */
class TextExtractorRegistry
{
    /** @var TextExtractor[] */
    protected array $extractors = [];

    public function add(TextExtractor $extractor): static
    {
        $this->extractors[] = $extractor;
        return $this;
    }

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
     * @return TextExtractor[]
     */
    public function all(): array
    {
        return $this->extractors;
    }
}
