<?php

namespace EduLazaro\Laracrate\Actions\Files\Video;

use EduLazaro\Laracrate\Actions\Files\Video\Concerns\EncodesBrowserVideo;
use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Str;
use Throwable;

/**
 * Transcodes the video to H.264 / AAC mp4 in place, optionally resized: the original
 * binary is replaced. To keep the original, declare `variants` on the video type
 * instead (GenerateVideoVariantsAction).
 *
 * Costly (CPU + time). Enable only on the video type of the collections that need it:
 *   'transcode' => true
 *
 * Requires ffmpeg on the path.
 */
class TranscodeVideoAction extends Action
{
    use EncodesBrowserVideo;

    /** Transcode the video to mp4 (H.264/AAC), replacing the original binary. */
    public function handle(File $file, ?int $maxWidth = null, ?int $maxHeight = null, ?int $bitrate = null): File
    {
        if (!$file->isVideo() || $file->isVariant()) {
            return $file;
        }

        $maxWidth  = $maxWidth  ?? config('laracrate.video.max_width',  1920);
        $maxHeight = $maxHeight ?? config('laracrate.video.max_height', 1920);
        $bitrate   = $bitrate   ?? config('laracrate.video.bitrate_kbps', 2500);

        $manager = app(StorageManager::class);

        try {
            $transcoded = $manager->withLocalCopy(
                $file,
                fn (string $inPath) => $this->encode($inPath, $maxWidth, $maxHeight, $bitrate)
            );

            if (!$transcoded) {
                return $file;
            }
        } catch (Throwable $e) {
            logger()->warning('Laracrate: failed to transcode', [
                'file_id' => $file->id,
                'error'   => $e->getMessage(),
            ]);
            return $file;
        }

        $oldKey   = $file->key;
        $newName  = Str::beforeLast($file->name, '.') . '.mp4';
        $newKey   = $file->siblingKey($newName);

        $manager->writeBinary($file->disk, $newKey, $transcoded, 'video/mp4');

        if ($oldKey !== $newKey) {
            $manager->deleteFromBackend($file->disk, $oldKey);
        }

        $file->forceFill([
            'path'      => $newKey,
            'name'      => $newName,
            'extension' => 'mp4',
            'mime_type' => 'video/mp4',
            'size'      => strlen($transcoded),
        ])->save();

        ExtractVideoDimensionsAction::create()->run(['file' => $file]);

        return $file;
    }
}
