<?php

namespace EduLazaro\Laracrate\Actions\Files\Video;

use EduLazaro\Laracrate\Enums\FileType;
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
 * Costly (CPU + time). Enable only on the video type of the collections that need it:
 *   'transcode' => true        replaces the original with the transcoded mp4
 *   'transcode' => 'variant'   keeps the original untouched and stores the mp4 as its
 *                              `playback` variant ($file->playback()), skipped when the
 *                              original already plays in every browser. For evidence and
 *                              anything else whose original must survive.
 *
 * Requires ffmpeg on the path.
 */
class TranscodeVideoAction extends Action
{
    /** Name of the variant that holds the browser-safe copy. */
    public const PLAYBACK = 'playback';

    /**
     * Transcode the video to mp4 (H.264/AAC). Replaces the original binary or, with
     * `$keepOriginal`, stores the result as the `playback` variant.
     */
    public function handle(File $file, ?int $maxWidth = null, ?int $maxHeight = null, ?int $bitrate = null, bool $keepOriginal = false): File
    {
        if (!$file->isVideo() || $file->isVariant()) {
            return $file;
        }

        $maxWidth  = $maxWidth  ?? config('laracrate.video.max_width',  1920);
        $maxHeight = $maxHeight ?? config('laracrate.video.max_height', 1920);
        $bitrate   = $bitrate   ?? config('laracrate.video.bitrate_kbps', 2500);

        $manager = app(StorageManager::class);

        if ($keepOriginal) {
            // Regenerating replaces the previous copy.
            $file->children()->where('variant', self::PLAYBACK)->first()?->forceDelete();
        }

        try {
            $transcoded = $manager->withLocalCopy($file, function (string $inPath) use ($maxWidth, $maxHeight, $bitrate, $keepOriginal) {
                if ($keepOriginal && $this->playsEverywhere($inPath)) {
                    return null;
                }

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

        if ($keepOriginal) {
            $this->storePlayback($file, $transcoded);

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

    /** Store the transcoded binary as the file's `playback` variant. */
    protected function storePlayback(File $file, string $binary): File
    {
        $name = Str::beforeLast($file->name, '.') . '_playback.mp4';
        $key  = $file->variantKey($name);

        app(StorageManager::class)->writeBinary($file->disk, $key, $binary, 'video/mp4');

        $playback = $file->createVariant(self::PLAYBACK, [
            'path'          => $key,
            'name'          => $name,
            'original_name' => $name,
            'extension'     => 'mp4',
            'mime_type'     => 'video/mp4',
            'size'          => strlen($binary),
            'type'          => FileType::VIDEO,
        ]);

        ExtractVideoDimensionsAction::create()->run(['file' => $playback]);

        return $playback;
    }

    /**
     * Whether the video plays as it is in every browser: H.264 8-bit 4:2:0 (not HDR) in
     * MP4/MOV, with AAC or MP3 audio or none. Range requests take care of an index at
     * the end, so there is nothing a copy would add.
     */
    protected function playsEverywhere(string $path): bool
    {
        $probe = json_decode((string) shell_exec(sprintf(
            'ffprobe -v error -show_entries format=format_name:stream=codec_type,codec_name,pix_fmt,color_transfer -of json %s',
            escapeshellarg($path)
        )), true);

        if (! is_array($probe) || ! str_contains($probe['format']['format_name'] ?? '', 'mp4')) {
            return false;
        }

        foreach ($probe['streams'] ?? [] as $stream) {
            $type = $stream['codec_type'] ?? null;
            $codec = $stream['codec_name'] ?? null;

            if ($type === 'video' && ($codec !== 'h264' || ($stream['pix_fmt'] ?? null) !== 'yuv420p'
                || in_array($stream['color_transfer'] ?? '', ['smpte2084', 'arib-std-b67'], true))) {
                return false;
            }

            if ($type === 'audio' && ! in_array($codec, ['aac', 'mp3'], true)) {
                return false;
            }
        }

        return true;
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
