<?php

namespace EduLazaro\Laracrate\Actions\Files\Video;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laractions\Action;
use Throwable;

/**
 * Extrae width, height y duration con ffprobe. Requiere ffprobe en el path
 * del servidor.
 */
class ExtractVideoDimensionsAction extends Action
{
    public function handle(File $file): File
    {
        if (!$file->isVideo()) {
            return $file;
        }

        if ($file->width && $file->height && $file->duration) {
            return $file;
        }

        try {
            $data = app(StorageManager::class)->withLocalCopy($file, function (string $path) {
                $cmd = sprintf(
                    'ffprobe -v error -select_streams v:0 -show_entries stream=width,height,duration -of csv=p=0:s=, %s',
                    escapeshellarg($path)
                );

                $output = trim((string) shell_exec($cmd));
                if (!$output) return null;

                $parts = explode(',', $output);
                return [
                    'width'    => isset($parts[0]) ? (int) $parts[0] : null,
                    'height'   => isset($parts[1]) ? (int) $parts[1] : null,
                    'duration' => isset($parts[2]) ? (int) round((float) $parts[2]) : null,
                ];
            });

            if ($data) {
                $file->forceFill(array_filter($data))->save();
            }
        } catch (Throwable $e) {
            logger()->warning('Laracrate: fallo al extraer dimensiones de vídeo', [
                'file_id' => $file->id,
                'error'   => $e->getMessage(),
            ]);
        }

        return $file;
    }
}
