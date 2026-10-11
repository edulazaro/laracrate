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
 * The output is always 8-bit 4:2:0 (yuv420p), the only H.264 flavour every browser
 * plays. Without forcing it, ffmpeg keeps the input's pixel format: a 10-bit HDR clip
 * from a phone becomes H.264 High 10, which Safari and iOS refuse to play. HDR input
 * (PQ or HLG) is also tone-mapped to SDR BT.709 when ffmpeg has zscale, otherwise it
 * would play with washed-out colours.
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

                $toneMap = $this->isHdr($inPath) && $this->hasZscale();

                $cmd = sprintf(
                    'ffmpeg -y -i %s -vf %s -c:v libx264 -preset fast -b:v %dk%s -c:a aac -movflags +faststart %s 2>&1',
                    escapeshellarg($inPath),
                    escapeshellarg($this->videoFilter($maxWidth, $maxHeight, $toneMap)),
                    $bitrate,
                    $toneMap ? ' -colorspace bt709 -color_primaries bt709 -color_trc bt709' : '',
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

    /**
     * Scale down within the bounds to even dimensions (libx264 rejects odd ones), tone-map
     * HDR to SDR when asked, and end in yuv420p.
     */
    protected function videoFilter(int $maxWidth, int $maxHeight, bool $toneMap): string
    {
        $filters = [
            "scale='if(gt(iw,{$maxWidth}),{$maxWidth},iw)':'if(gt(ih,{$maxHeight}),{$maxHeight},ih)':force_original_aspect_ratio=decrease:force_divisible_by=2",
        ];

        if ($toneMap) {
            $filters[] = 'zscale=t=linear:npl=100';
            $filters[] = 'format=gbrpf32le';
            $filters[] = 'zscale=p=bt709';
            $filters[] = 'tonemap=tonemap=hable:desat=0';
            $filters[] = 'zscale=t=bt709:m=bt709:r=tv';
        }

        $filters[] = 'format=yuv420p';

        return implode(',', $filters);
    }

    /**
     * Whether the first video stream uses an HDR transfer: PQ (HDR10, Dolby Vision) or
     * HLG (iPhone, broadcast).
     */
    protected function isHdr(string $path): bool
    {
        $transfer = trim((string) shell_exec(sprintf(
            'ffprobe -v error -select_streams v:0 -show_entries stream=color_transfer -of csv=p=0 %s',
            escapeshellarg($path)
        )));

        return in_array($transfer, ['smpte2084', 'arib-std-b67'], true);
    }

    /** zscale needs ffmpeg built with libzimg; without it HDR is converted but not tone-mapped. */
    protected function hasZscale(): bool
    {
        static $available = null;

        return $available ??= str_contains((string) shell_exec('ffmpeg -hide_banner -filters 2>/dev/null'), ' zscale ');
    }
}
