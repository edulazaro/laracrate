<?php

namespace EduLazaro\Laracrate\Actions\Files\Video;

use EduLazaro\Laracrate\Models\File;
use EduLazaro\Laracrate\Services\StorageManager;
use EduLazaro\Laractions\Action;
use Illuminate\Support\Str;
use Throwable;

/**
 * Transcodes the video to H.264 / AAC mp4 and optionally resizes it.
 * Replaces the binary in the backend.
 *
 * Costly (CPU + time). Enable only on collections that need it:
 *   'collections.video_uploads.transcode' => true
 *
 * Requires ffmpeg on the path.
 */
class TranscodeVideoAction extends Action
{
    /**
     * Transcode the video to mp4 (H.264/AAC) and replace the original binary.
     */
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
            $transcoded = $manager->withLocalCopy($file, function (string $inPath) use ($maxWidth, $maxHeight, $bitrate) {
                $outPath = sys_get_temp_dir() . '/laracrate_transcoded_' . Str::random(16) . '.mp4';

                $vfilter = "scale='if(gt(iw,{$maxWidth}),{$maxWidth},iw)':'if(gt(ih,{$maxHeight}),{$maxHeight},ih)':force_original_aspect_ratio=decrease";

                $cmd = sprintf(
                    'ffmpeg -y -i %s -vf %s -c:v libx264 -preset fast -b:v %dk -c:a aac -movflags +faststart %s 2>&1',
                    escapeshellarg($inPath),
                    escapeshellarg($vfilter),
                    $bitrate,
                    escapeshellarg($outPath)
                );

                exec($cmd, $output, $code);

                if ($code !== 0 || !is_file($outPath)) {
                    logger()->warning('Laracrate: ffmpeg failed to transcode', [
                        'cmd' => $cmd, 'output' => $output, 'code' => $code,
                    ]);
                    return null;
                }

                $bin = file_get_contents($outPath);
                @unlink($outPath);
                return $bin;
            });

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
