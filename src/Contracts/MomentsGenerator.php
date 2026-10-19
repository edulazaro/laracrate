<?php

namespace EduLazaro\Laracrate\Contracts;

use EduLazaro\Laracrate\Models\File;

/**
 * Picks the moments of a video a teaser joins or a strip of frames shows: an AI that
 * looks for the action, the scenes an editor marked... Laracrate cuts and joins them.
 *
 *   'teaser' => ['type' => 'video', 'generator' => App\Media\AiTeaser::class],
 */
interface MomentsGenerator
{
    /**
     * @param  array  $spec  the variant's config (clips, seconds, frames...)
     * @return list<array{at: float, seconds?: float}>  start, and length, in seconds
     */
    public function generate(File $video, array $spec): array;
}
