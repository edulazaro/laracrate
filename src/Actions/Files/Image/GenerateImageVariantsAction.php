<?php

namespace EduLazaro\Laracrate\Actions\Files\Image;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Support\CollectionConfig;
use EduLazaro\Laractions\Action;
use Throwable;

/**
 * Orchestrator: iterates config.variants of the parent File's collection and
 * dispatches GenerateImageVariantAction for each definition.
 *
 * If a definition fails, it continues with the rest and logs it. It does not
 * abort the whole operation.
 */
class GenerateImageVariantsAction extends Action
{
    /**
     * Generate all configured image variants for the given parent File.
     */
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
                logger()->warning('Laracrate: failed to generate variant', [
                    'file_id' => $file->id,
                    'variant' => $name,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return $generated;
    }
}
