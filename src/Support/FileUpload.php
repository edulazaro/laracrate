<?php

namespace EduLazaro\Laracrate\Support;

use EduLazaro\Laracrate\Models\File;

/**
 * Value object que representa un archivo ya presente en el backend (subido
 * vía presigned URL al S3, o por cualquier otro mecanismo), antes de
 * persistir el File model.
 *
 * Lo construye el cliente JS tras completar el upload directo y lo envía
 * de vuelta al servidor para que CreateFileAction lo materialice.
 */
class FileUpload
{
    public function __construct(
        public readonly string $disk,
        public readonly string $key,
        public readonly string $originalName,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly ?string $title = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?int $duration = null,
        public readonly array $metadata = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            disk:         $data['disk'],
            key:          $data['key'],
            originalName: $data['original_name'] ?? $data['originalName'] ?? 'unknown',
            mimeType:     $data['mime_type'] ?? $data['mimeType'] ?? 'application/octet-stream',
            size:         (int) ($data['size'] ?? 0),
            title:        $data['title'] ?? null,
            width:        isset($data['width']) ? (int) $data['width'] : null,
            height:       isset($data['height']) ? (int) $data['height'] : null,
            duration:     isset($data['duration']) ? (int) $data['duration'] : null,
            metadata:     $data['metadata'] ?? [],
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
