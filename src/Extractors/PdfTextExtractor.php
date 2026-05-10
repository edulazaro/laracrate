<?php

namespace EduLazaro\Laracrate\Extractors;

use EduLazaro\Laracrate\Contracts\TextExtractor;
use EduLazaro\Laracrate\Models\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Extractor de texto para PDFs usando smalot/pdfparser si está disponible.
 *
 * El package no fuerza la dependencia. Si la app necesita esta extracción,
 * tiene que añadir `composer require smalot/pdfparser`. Si no está
 * instalado, `supports()` devuelve false y el pipeline lo omite limpiamente.
 */
class PdfTextExtractor implements TextExtractor
{
    public function supports(File $file): bool
    {
        if ($file->mime_type !== 'application/pdf') {
            return false;
        }

        return class_exists(\Smalot\PdfParser\Parser::class);
    }

    public function extract(File $file): string
    {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            throw new RuntimeException(
                'PdfTextExtractor requiere smalot/pdfparser. Instálalo con: composer require smalot/pdfparser'
            );
        }

        $key = $file->key;
        $tmpPath = tempnam(sys_get_temp_dir(), 'laracrate_pdf_');

        try {
            $stream = app(\EduLazaro\Laracrate\Services\StorageManager::class)
                ->diskFor($file)
                ->readStream($key);
            if (!$stream) {
                throw new RuntimeException("No se pudo leer {$key} del disk {$file->disk}");
            }
            file_put_contents($tmpPath, stream_get_contents($stream));
            if (is_resource($stream)) {
                fclose($stream);
            }

            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($tmpPath);
            $text = $pdf->getText();

            return trim(preg_replace('/\s+/u', ' ', (string) $text));
        } finally {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }
}
