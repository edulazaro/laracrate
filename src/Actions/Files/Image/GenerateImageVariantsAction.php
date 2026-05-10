<?php

namespace EduLazaro\Laracrate\Actions\Files\Image;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\CollectionConfig;
use EduLazaro\Laractions\Action;
use Throwable;

/**
 * Orquestador: itera config.variants de la colección del File padre y
 * dispatcha GenerateImageVariantAction por cada definición.
 *
 * Si una definición falla, sigue con las demás y loggea. No aborta toda
 * la operación.
 */
class GenerateImageVariantsAction extends Action
{
    public function handle(File $file, ?array $variants = null): array
    {
        if (!$file->isImage()) {
            return [];
        }

        if ($variants === null) {
            $config   = CollectionConfig::resolve($file->collection, $file->fileable_type);
            $variants = $config['variants'] ?? [];
        }

        if (empty($variants)) {
            return [];
        }

        $generated = [];

        foreach ($variants as $name => $options) {
            try {
                $variant = GenerateImageVariantAction::create()->run([
                    'file'    => $file,
                    'name'    => $name,
                    'options' => is_array($options) ? $options : [],
                ]);

                if ($variant) {
                    $generated[$name] = $variant;
                }
            } catch (Throwable $e) {
                logger()->warning('Laracrate: fallo al generar variant', [
                    'file_id' => $file->id,
                    'variant' => $name,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return $generated;
    }
}
