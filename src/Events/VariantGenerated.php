<?php

namespace EduLazaro\Laracrate\Events;

use EduLazaro\Laracrate\Models\File;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara cuando se persiste un File hijo (parent_id != null) — thumbnail,
 * preview, transcoded copy, watermarked, etc. Independientemente de qué action
 * lo creó (es un evento centralizado en el Observer).
 */
class VariantGenerated
{
    use Dispatchable;

    public function __construct(
        public File $variant,
        public ?File $parent = null,
    ) {}
}
