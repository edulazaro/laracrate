<?php

namespace EduLazaro\Laracrate\Events;

use EduLazaro\Laracrate\Models\File;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a child File (parent_id != null) is persisted: thumbnail,
 * preview, transcoded copy, watermarked, etc. Regardless of which action
 * created it (it is a centralized event in the Observer).
 */
class VariantGenerated
{
    use Dispatchable;

    /** Create the event for the generated variant and its optional parent. */
    public function __construct(
        public File $variant,
        public ?File $parent = null,
    ) {}
}
