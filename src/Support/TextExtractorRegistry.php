<?php

namespace EduLazaro\Laracrate\Support;

use EduLazaro\Laracrate\Contracts\TextExtractor;
use EduLazaro\Laracrate\Models\File;

/**
 * Registry de extractors de texto encadenados.
 *
 * El orden de registro define la prioridad: el primero se prueba antes que
 * el segundo. Si un extractor devuelve texto por debajo del umbral mínimo
 * (`min_text_per_file`), el siguiente se prueba como fallback.
 *
 * Patrón típico: extractor rápido/gratis primero (smalot para PDFs nativos)
 * → extractor lento/de pago como fallback (OCR sobre PDFs escaneados).
 *
 * Las apps pueden añadir sus propios extractors via `add()` o configurando
 * `laracrate.embeddings.extractors` como array de FQCN.
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

    /**
     * Devuelve el primer extractor que soporta el file (legacy compat — apps
     * que llaman directo a `for($file)` siguen funcionando, sin fallback).
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
     * Devuelve TODOS los extractors que soportan el file, en orden de prioridad.
     * Útil para implementar fallback chain (ej. smalot → OCR).
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
     * @return TextExtractor[]
     */
    public function all(): array
    {
        return $this->extractors;
    }
}
