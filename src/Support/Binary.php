<?php

namespace EduLazaro\Laracrate\Support;

/**
 * Value object para upload de contenido binario generado server-side.
 *
 * Caso de uso: PDFs generados con mPDF, imágenes generadas con GD, exports
 * en memoria, etc. El caller pasa el contenido bruto + mime + nombre lógico;
 * el paquete decide path canónico, valida y sube.
 *
 *   $case->addFile(new Binary(
 *       content: $pdfContent,
 *       mimeType: 'application/pdf',
 *       originalName: 'Contrato_firmado.pdf',
 *   ), 'documents', data: [...]);
 *
 * Diferencias frente a UploadedFile:
 *   - UploadedFile = subida HTTP, archivo en `php://input` o tmp local.
 *   - Binary       = contenido en memoria, sin archivo intermedio.
 *
 * Diferencias frente a FileUpload (presigned):
 *   - FileUpload = el cliente ya subió a `temp/X` en R2; el paquete mueve.
 *   - Binary     = el server tiene los bytes; el paquete escribe al disk final.
 */
class Binary
{
    public function __construct(
        public readonly string $content,
        public readonly string $mimeType,
        public readonly string $originalName,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?int $duration = null,
    ) {}

    public function size(): int
    {
        return strlen($this->content);
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->originalName, PATHINFO_EXTENSION) ?: 'bin');
    }
}
