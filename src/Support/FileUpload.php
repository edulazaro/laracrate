<?php

namespace EduLazaro\Laracrate\Support;

use EduLazaro\Laracrate\Models\File;

/**
 * Value object que representa un archivo YA presente en el backend (subido
 * vía presigned URL a S3/R2, o por cualquier otro mecanismo), antes de
 * persistir el File model.
 *
 * Lo construye el cliente JS tras completar el upload directo y lo envía
 * de vuelta al servidor para que CreateFileAction lo materialice.
 *
 * Solo describe el binario físico (disk, key, mime, size, dimensiones,
 * digest). Para atributos del File model (title, description, category,
 * visibility, metadata JSON) usa el parámetro `$data` de `addFile()`.
 */
class FileUpload
{
    public function __construct(
        public readonly string $disk,
        public readonly string $key,
        public readonly string $originalName,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?int $duration = null,
        public readonly ?string $digest = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            disk:         $data['disk'],
            key:          $data['key'],
            originalName: $data['original_name'] ?? $data['originalName'] ?? 'unknown',
            mimeType:     $data['mime_type'] ?? $data['mimeType'] ?? 'application/octet-stream',
            size:         (int) ($data['size'] ?? 0),
            width:        isset($data['width']) ? (int) $data['width'] : null,
            height:       isset($data['height']) ? (int) $data['height'] : null,
            duration:     isset($data['duration']) ? (int) $data['duration'] : null,
            digest:       $data['digest'] ?? null,
        );
    }

    protected ?File $resultingFile = null;

    public function bindTo(File $file): void
    {
        $this->resultingFile = $file;
    }

    public function getFile(): ?File
    {
        return $this->resultingFile;
    }
}
