<?php

namespace EduLazaro\Laracrate\Contracts;

use EduLazaro\Laracrate\Models\File;

/**
 * Action of a File's processing pipeline.
 *
 * Each action is an independent piece that decides whether it applies to the
 * given File (`supports`) and what to do (`handle`). The registry runs them
 * ordered by ascending `priority`. Priority convention:
 *
 *   0-19   : metadata (dimensions, duration)
 *   20-39  : transformation of the original (optimize, transcode, encrypt)
 *   40-59  : derivatives (variants, previews, thumbnails)
 *   60-79  : semantic extraction (text, OCR, transcription)
 *   80-99  : AI (chunking, embeddings, classification)
 *   100+   : app-specific post-processing
 *
 * Apps register their own actions declaratively in the collection
 * in `config/laracrate.php`:
 *
 *   'documents' => [
 *       'actions' => [
 *           \App\FileActions\ClassifyDocumentAction::class,
 *       ],
 *   ]
 */
interface FileActionInterface
{
    /**
     * Runs the action on the file. Throws on failure: the
     * orchestrator decides the policy.
     */
    public function handle(File $file): void;

    /**
     * Ascending priority. Actions with a lower number run first.
     */
    public function priority(): int;

    /*
     * OPTIONAL: each implementation may declare
     *
     *   public function supports(File $file): bool;
     *
     * for extra per-file gating (e.g. only if there is extracted text, only if
     * certain metadata exists, etc.). If the method does not exist, ProcessFileAction
     * assumes true and always invokes handle().
     *
     * The scope by fileable and by collection is done declaratively in
     * `config.collections.*.actions` and `config.collections.*.models.X.actions`,
     * not in supports(). Variants do not reach here either: ProcessFileAction
     * filters them out beforehand (isVariant check).
     */
}
