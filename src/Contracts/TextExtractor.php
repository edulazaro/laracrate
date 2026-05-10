<?php

namespace EduLazaro\Laracrate\Contracts;

use EduLazaro\Laracrate\Models\File;

/**
 * Extractor de texto plano de un File.
 *
 * Implementaciones: PdfTextExtractor (smalot/pdfparser), PlainTextExtractor
 * (text/*, csv), OcrTextExtractor (Tesseract sobre imágenes), AudioTextExtractor
 * (Whisper sobre audio). El package incluye los dos primeros.
 */
interface TextExtractor
{
    /**
     * Devuelve true si este extractor sabe manejar el File dado.
     */
    public function supports(File $file): bool;

    /**
     * Devuelve el texto extraído. Lanza excepción si falla.
     */
    public function extract(File $file): string;
}
